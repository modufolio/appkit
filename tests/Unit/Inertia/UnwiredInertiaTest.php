<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Inertia;

use Modufolio\Appkit\Core\AbstractController;
use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\Inertia\InertiaRendererInterface;
use Modufolio\Appkit\Inertia\UnwiredRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A host that never wired Inertia: `$this->inertia` is still assigned, so
 * using it names the module to add instead of raising the fatal Error an
 * uninitialized typed property gives.
 */
final class UnwiredInertiaTest extends TestCase
{
    private function controller(): UnwiredHostController
    {
        $app = $this->createStub(AppInterface::class);
        $app->method('inertia')->willThrowException(new \LogicException('A controller returned an Inertia page, but Inertia is not wired.'));

        $controller = new UnwiredHostController();
        $controller->setSubscribedServices($app);

        return $controller;
    }

    public function testAnUnwiredHostStillLeavesTheControllerPropertyAssigned(): void
    {
        self::assertInstanceOf(UnwiredRenderer::class, $this->controller()->renderer());
    }

    /**
     * Building the renderer resolves the host's shared props and flash store,
     * which reach for the session — so it must not happen while the kernel is
     * merely wiring the controller.
     */
    public function testTheRendererIsNotBuiltWhileTheControllerIsWired(): void
    {
        $app = $this->createMock(AppInterface::class);
        $app->expects(self::never())->method('inertia');

        (new UnwiredHostController())->setSubscribedServices($app);
    }

    public function testUsingTheRendererBuildsItOnce(): void
    {
        $app = $this->createMock(AppInterface::class);
        $app->expects(self::once())
            ->method('inertia')
            ->willThrowException(new \LogicException('A controller returned an Inertia page, but Inertia is not wired.'));

        $controller = new UnwiredHostController();
        $controller->setSubscribedServices($app);

        // Two reads, one build: the unwired renderer is memoised like any other.
        self::assertSame($controller->renderer(), $controller->renderer());
    }

    public function testUsingItNamesWhatToWire(): void
    {
        $renderer = $this->controller()->renderer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/list .*InertiaModule in config\/modules\.php/');

        $renderer->flash('saved', true);
    }
}

/** A controller on a host with no Inertia wiring. */
final class UnwiredHostController extends AbstractController
{
    public function renderer(): InertiaRendererInterface
    {
        return $this->inertia;
    }
}
