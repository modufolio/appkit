<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Exception\UnresolvableServiceException;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoService;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Container\ContainerExceptionInterface;

/**
 * The container's failure-path craft: circular chains, did-you-mean
 * suggestions with module provenance, deprecated ids, and reset fan-out.
 */
class ContainerDiagnosticsTest extends AppTestCase
{
    public function testCircularDependenciesReportTheFullChain(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator
            ->set(\ArrayObject::class, fn ($app) => new \ArrayObject((array) $app->get(\SplStack::class)))
            ->set(\SplStack::class, function ($app) {
                $app->get(\ArrayObject::class);

                return new \SplStack();
            });
        $this->app()->configureServices($configurator);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency detected: ArrayObject -> SplStack -> ArrayObject');

        $this->app()->get(\ArrayObject::class);
    }

    /**
     * resolve() answers a registered authenticator name; has() must say so
     * too, or a caller that checks first is told "no" and then get() works.
     */
    public function testHasAgreesWithGetForAuthenticatorNames(): void
    {
        $this->app()->registerAuthenticator('diagnostics_probe', fn () => new \ArrayObject());

        $this->assertTrue($this->app()->has('diagnostics_probe'));
        $this->assertInstanceOf(\ArrayObject::class, $this->app()->get('diagnostics_probe'));
        $this->assertFalse($this->app()->has('diagnostics_probe_missing'));
    }

    /**
     * An id a module declared shared() and the application redeclares with
     * set() takes the application's lifetime: a fresh object per resolve,
     * not the module's cached one.
     */
    public function testRedeclaringASharedIdWithSetDropsTheSharedLifetime(): void
    {
        $first = new ServiceConfigurator();
        $first->shared(\ArrayObject::class, fn () => new \ArrayObject());
        $this->app()->configureServices($first);

        $cached = $this->app()->get(\ArrayObject::class);
        $this->assertSame($cached, $this->app()->get(\ArrayObject::class), 'shared() caches within a request');

        $second = new ServiceConfigurator();
        $second->set(\ArrayObject::class, fn () => new \ArrayObject());
        $this->app()->configureServices($second);

        $a = $this->app()->get(\ArrayObject::class);
        $b = $this->app()->get(\ArrayObject::class);

        $this->assertNotSame($cached, $a, 'the redeclared id must not serve the previous cached instance');
        $this->assertNotSame($a, $b, 'set() builds a fresh object per resolve');
    }

    /**
     * A deprecation a module attached to an id no longer fires once the
     * application owns that id.
     */
    public function testRedeclaringADeprecatedIdClearsTheDeprecation(): void
    {
        $first = new ServiceConfigurator();
        $first->set(\SplQueue::class, fn () => new \SplQueue())
            ->deprecate(\SplQueue::class, 'SplQueue is deprecated.');
        $this->app()->configureServices($first);

        $second = new ServiceConfigurator();
        $second->set(\SplQueue::class, fn () => new \SplQueue());
        $this->app()->configureServices($second);

        $deprecations = [];
        set_error_handler(static function (int $no, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $this->app()->get(\SplQueue::class);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
    }

    public function testAFactoryMissingAConstructorArgumentIsAnUnresolvableService(): void
    {
        $configurator = new ServiceConfigurator();
        // \DateInterval requires one argument; a factory that forgets it is the
        // shape of a services.php wiring bug.
        $configurator->set(\DateInterval::class, fn () => (new \ReflectionClass(\DateInterval::class))->newInstance());
        $this->app()->configureServices($configurator);

        try {
            $this->app()->get(\DateInterval::class);
            $this->fail('Expected an UnresolvableServiceException.');
        } catch (UnresolvableServiceException $e) {
            $this->assertSame(\DateInterval::class, $e->serviceId);
            $this->assertInstanceOf(\ArgumentCountError::class, $e->getPrevious());
            $this->assertInstanceOf(\LogicException::class, $e);
            $this->assertInstanceOf(ContainerExceptionInterface::class, $e);
            $this->assertStringContainsString('Service "DateInterval" cannot be built', $e->getMessage());
            $this->assertStringContainsString('expects exactly 1 argument', $e->getMessage());
        }
    }

    public function testUnknownIdsSuggestNearMissesWithModuleProvenance(): void
    {
        try {
            // One trailing character off from the blog fixture module's DemoService.
            $this->app()->get(DemoService::class.'x');
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('Did you mean', $e->getMessage());
            $this->assertStringContainsString(DemoService::class, $e->getMessage());
            $this->assertStringContainsString('from module "demo"', $e->getMessage());
        }
    }

    public function testUnknownIdsNameTheRequestingService(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator->set(\SplQueue::class, function ($app) {
            $app->get('Totally\Unknown\ServiceZz');

            return new \SplQueue();
        });
        $this->app()->configureServices($configurator);

        try {
            $this->app()->get(\SplQueue::class);
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('(needed by "SplQueue")', $e->getMessage());
        }
    }

    public function testDeprecatedServiceIdsWarnOnceOnResolve(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator
            ->set(\SplObjectStorage::class, fn () => new \SplObjectStorage())
            ->deprecate(\SplObjectStorage::class, 'The "SplObjectStorage" id is deprecated, use "NewStorage".');
        $this->app()->configureServices($configurator);

        $this->expectUserDeprecationMessage('The "SplObjectStorage" id is deprecated, use "NewStorage".');

        $this->app()->get(\SplObjectStorage::class);
    }

    public function testDeprecatingAnUndeclaredIdFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no such service is declared/');

        (new ServiceConfigurator())->deprecate(\ArrayObject::class, 'gone');
    }

    public function testOnResetCallbacksRunOnceAndAreCleared(): void
    {
        $calls = [];
        $this->app()->onReset(function (bool $terminate) use (&$calls): void {
            $calls[] = $terminate;
        });

        $this->app()->resetModules();
        $this->app()->resetModules();

        $this->assertSame([false], $calls, 'The callback must run exactly once — reset clears it.');
    }

    public function testOnResetReceivesTheTerminateFlag(): void
    {
        $terminated = null;
        $this->app()->onReset(function (bool $terminate) use (&$terminated): void {
            $terminated = $terminate;
        });

        $this->app()->resetModules(terminate: true);

        $this->assertTrue($terminated);
    }

    public function testAThrowingResetCallbackDoesNotStopTheOthers(): void
    {
        $secondRan = false;
        $this->app()->onReset(function (): void {
            throw new \RuntimeException('bad lease');
        });
        $this->app()->onReset(function () use (&$secondRan): void {
            $secondRan = true;
        });

        try {
            $this->app()->resetModules();
            $this->fail('Expected the first failure to be rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('bad lease', $e->getMessage());
        }

        $this->assertTrue($secondRan, 'Later callbacks must still run when an earlier one throws.');
    }
}
