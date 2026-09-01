<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * The #[Service] allowlist behind the '@method' controller-dependency form.
 */
class ServiceAttributeTest extends AppTestCase
{
    /**
     * @return array<string, true>
     */
    private function serviceMethods(): array
    {
        $method = new \ReflectionMethod($this->app(), 'serviceMethods');

        return $method->invoke($this->app());
    }

    private function resolveDependency(string $dep): mixed
    {
        $method = new \ReflectionMethod($this->app(), 'resolveDependency');

        return $method->invoke($this->app(), $dep);
    }

    public function testTheMapContainsAnnotatedKernelAccessors(): void
    {
        $map = $this->serviceMethods();

        $this->assertArrayHasKey('session', $map);
        $this->assertArrayHasKey('tokenStorage', $map);
        $this->assertArrayHasKey('entityManager', $map);
    }

    public function testAnAppOverrideInheritsTheKernelDeclarationsAttribute(): void
    {
        // userProvider() is implemented by the fixture App without its own
        // attribute; the kernel's abstract declaration carries it.
        $this->assertArrayHasKey('userProvider', $this->serviceMethods());
    }

    public function testAnAnnotatedAppMethodIsInTheMap(): void
    {
        $this->assertArrayHasKey('totpService', $this->serviceMethods());
    }

    public function testUnannotatedKernelInternalsAreNotInjectable(): void
    {
        // boot() exists on the kernel but is deliberately outside the
        // allowlist — the whole point of the attribute.
        $this->assertArrayNotHasKey('boot', $this->serviceMethods());
        $this->assertArrayNotHasKey('resolve', $this->serviceMethods());
    }

    public function testResolvingAnAnnotatedMethodWorks(): void
    {
        $this->assertSame($this->app()->session(), $this->resolveDependency('@session'));
    }

    public function testAnExistingButUnannotatedMethodGetsTheAnnotateHint(): void
    {
        try {
            $this->resolveDependency('@boot');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("'@boot' is not a #[Service] method", $e->getMessage());
            $this->assertStringContainsString('annotate', $e->getMessage());
            $this->assertStringContainsString('#[Service]', $e->getMessage());
        }
    }

    public function testATypoGetsADidYouMeanSuggestion(): void
    {
        try {
            $this->resolveDependency('@totpServce');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Did you mean '@totpService'", $e->getMessage());
        }
    }

    public function testBootValidationReportsEveryBadReferenceAtOnce(): void
    {
        $app = $this->app();
        $property = new \ReflectionProperty($app, 'controllers');
        $original = $property->getValue($app);

        $property->setValue($app, $original + [
            'App\Fake\OneController' => ['@nope', '@session'],
            'App\Fake\TwoController' => ['@alsoNope'],
        ]);

        try {
            $method = new \ReflectionMethod($app, 'validateControllerDependencies');
            $method->invoke($app);
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("OneController: '@nope'", $e->getMessage());
            $this->assertStringContainsString("TwoController: '@alsoNope'", $e->getMessage());
            $this->assertStringNotContainsString('@session', $e->getMessage());
        } finally {
            $property->setValue($app, $original);
        }
    }
}
