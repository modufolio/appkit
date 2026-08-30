<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\TwoFactor\TotpService;
use Modufolio\Appkit\Security\User\UserChecker;
use Modufolio\Appkit\Security\User\UserCheckerInterface;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ServiceConfiguratorTest extends AppTestCase
{
    public function testKernelCoreServicesResolveWithoutAnInterfacesFile(): void
    {
        // The fixture app boots without a fileMap['interfaces'] entry, so every
        // core id below is served by the kernel's own defaults.
        $this->assertInstanceOf(CsrfTokenManagerInterface::class, $this->app()->get(CsrfTokenManagerInterface::class));
        $this->assertInstanceOf(EntityManagerInterface::class, $this->app()->get(EntityManagerInterface::class));
        $this->assertInstanceOf(UrlGeneratorInterface::class, $this->app()->get(UrlGeneratorInterface::class));
        $this->assertInstanceOf(ResponseInterface::class, $this->app()->get(ResponseInterface::class));
    }

    public function testServicesFileDefinitionsResolve(): void
    {
        // Declared in tests/fixtures/config/services.php.
        $this->assertInstanceOf(TotpService::class, $this->app()->get(TotpService::class));
        $this->assertInstanceOf(BruteForceProtectionInterface::class, $this->app()->get(BruteForceProtectionInterface::class));
    }

    public function testSetProducesAFreshInstancePerGet(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator->set(\ArrayObject::class, fn () => new \ArrayObject());
        $this->app()->configureServices($configurator);

        $this->assertNotSame(
            $this->app()->get(\ArrayObject::class),
            $this->app()->get(\ArrayObject::class),
        );
    }

    public function testSharedResolvesOncePerRequestAndClearsOnReset(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator->shared(\SplStack::class, fn () => new \SplStack());
        $this->app()->configureServices($configurator);

        $first = $this->app()->get(\SplStack::class);
        $this->assertSame($first, $this->app()->get(\SplStack::class));

        $this->app()->reset();
        $this->app()->initializeTestState();

        $this->assertNotSame($first, $this->app()->get(\SplStack::class));
    }

    public function testAliasResolvesThroughTheContainer(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator
            ->shared(\SplQueue::class, fn () => new \SplQueue())
            ->alias(\SplDoublyLinkedList::class, \SplQueue::class);
        $this->app()->configureServices($configurator);

        $this->assertSame(
            $this->app()->get(\SplQueue::class),
            $this->app()->get(\SplDoublyLinkedList::class),
        );
    }

    public function testNamespacedIdMustBeARealClass(): void
    {
        $configurator = new ServiceConfigurator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not an existing class or interface');

        $configurator->set('Modufolio\\Appkit\\DoesNotExist', fn () => new \stdClass());
    }

    public function testAliasSwallowedNamespaceIsDiagnosed(): void
    {
        // `use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;` plus a bare
        // `ServiceConfigurator\...` style token produces a doubled prefix; the
        // validator names the class that does exist and the leading-backslash fix.
        $configurator = new ServiceConfigurator();

        try {
            $configurator->set('Modufolio\\Modufolio\\Appkit\\DependencyInjection\\ServiceConfigurator', fn () => new \stdClass());
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('does exist', $e->getMessage());
            self::assertStringContainsString(ServiceConfigurator::class, $e->getMessage());
            self::assertStringContainsString('leading backslash', $e->getMessage());
        }
    }

    public function testNonNamespacedStringIdsAreStillAccepted(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator->set('someLegacyServiceId', fn () => new \stdClass());

        self::assertArrayHasKey('someLegacyServiceId', $configurator->definitions);
    }

    public function testAliasOfItselfIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        (new ServiceConfigurator())->alias(\ArrayObject::class, \ArrayObject::class);
    }

    public function testServiceDefinitionOverridesAKernelCoreService(): void
    {
        // A stateless sentinel: identical behaviour to the kernel default, but
        // assertSame proves the services.php definition won the lookup.
        $sentinel = new UserChecker();
        $configurator = new ServiceConfigurator();
        $configurator->set(UserCheckerInterface::class, fn () => $sentinel);
        $this->app()->configureServices($configurator);

        $this->assertSame($sentinel, $this->app()->get(UserCheckerInterface::class));
    }

    public function testLaterConfigureServicesCallWinsForTheSameId(): void
    {
        $first = new ServiceConfigurator();
        $first->set(\ArrayObject::class, fn () => new \ArrayObject(['first']));
        $this->app()->configureServices($first);

        $second = new ServiceConfigurator();
        $second->set(\ArrayObject::class, fn () => new \ArrayObject(['second']));
        $this->app()->configureServices($second);

        $this->assertSame(['second'], $this->app()->get(\ArrayObject::class)->getArrayCopy());
    }

    public function testFactoriesReceiveTheApp(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator->set(\ArrayIterator::class, fn ($app) => new \ArrayIterator([$app->baseDir]));
        $this->app()->configureServices($configurator);

        $iterator = $this->app()->get(\ArrayIterator::class);

        $this->assertSame([$this->app()->baseDir], iterator_to_array($iterator));
    }
}
