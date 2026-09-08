<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Inertia;

use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Inertia\CallableRootView;
use Modufolio\Appkit\Inertia\Flash\ArrayFlashStore;
use Modufolio\Appkit\Inertia\Flash\FlashStoreInterface;
use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\Inertia;
use Modufolio\Appkit\Inertia\InertiaModule;
use Modufolio\Appkit\Inertia\InertiaRenderer;
use Modufolio\Appkit\Inertia\InertiaRendererInterface;
use Modufolio\Appkit\Inertia\Page;
use Modufolio\Appkit\Inertia\RootViewInterface;
use Modufolio\Appkit\Inertia\SharedPropsInterface;
use Modufolio\Appkit\Inertia\Testing\InertiaPage;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

final class InertiaModuleTest extends TestCase
{
    /** @param array<string, object> $services */
    private function app(array $services): AppInterface
    {
        $app = $this->createStub(AppInterface::class);
        $app->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $app->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);
        $app->method('session')->willThrowException(new \RuntimeException('Session is not available.'));

        return $app;
    }

    private function rootView(): RootViewInterface
    {
        return new CallableRootView(static fn (Page $page): string => Inertia::snippet($page));
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, object> $services
     */
    private function renderer(array $config, array $services): InertiaRenderer
    {
        $configurator = new ServiceConfigurator();
        (new InertiaModule())->services($configurator, $config);

        $renderer = $configurator->definitions[InertiaRenderer::class]($this->app($services));
        self::assertInstanceOf(InertiaRenderer::class, $renderer);

        return $renderer;
    }

    public function testTheModuleWiresTheRendererFromTheHostsRootViewAndSharedProps(): void
    {
        $shared = new class implements SharedPropsInterface {
            public function create(): array
            {
                return ['auth' => 'shared'];
            }
        };
        $renderer = $this->renderer(['version' => 'v9'], [RootViewInterface::class => $this->rootView(), SharedPropsInterface::class => $shared]);

        self::assertSame('v9', $renderer->version());

        $page = InertiaPage::fromResponse($renderer->render('X', ['a' => 1], new ServerRequest('GET', '/', [Header::INERTIA => 'true'])));
        self::assertSame(['auth' => 'shared', 'a' => 1], $page->props());
    }

    public function testSharedPropsAreOptionalAndAHostsFlashStoreIsUsed(): void
    {
        $store = new ArrayFlashStore();
        $renderer = $this->renderer([], [RootViewInterface::class => $this->rootView(), FlashStoreInterface::class => $store]);

        self::assertSame('', $renderer->version());
        self::assertSame($store, $renderer->flashStore());
    }

    public function testARootViewIsRequired(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RootViewInterface::class);

        $this->renderer([], []);
    }

    public function testAVersionFileIsHashed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'inertia');
        self::assertNotFalse($file);
        file_put_contents($file, 'build-7');

        try {
            $renderer = $this->renderer(['version_file' => $file], [RootViewInterface::class => $this->rootView()]);
            self::assertSame(md5('build-7'), $renderer->version());
        } finally {
            unlink($file);
        }
    }

    public function testAnUnknownConfigKeyIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        (new InertiaModule())->services(new ServiceConfigurator(), ['versoin' => 'x']);
    }

    public function testTheRendererIsRequestScopedAndAnsweredByBothIds(): void
    {
        $configurator = new ServiceConfigurator();
        (new InertiaModule())->services($configurator, []);

        // Shared, so a flash put on `$app->inertia()` is read by the page
        // `$this->inertia` renders in the same request.
        self::assertArrayHasKey(InertiaRenderer::class, $configurator->shared);
        self::assertArrayHasKey(InertiaRendererInterface::class, $configurator->definitions);

        $renderer = $this->renderer([], [RootViewInterface::class => $this->rootView()]);
        $app = $this->createStub(AppInterface::class);
        $app->method('get')->willReturn($renderer);

        self::assertSame($renderer, $configurator->definitions[InertiaRendererInterface::class]($app));
    }

    public function testTheModuleIsNamedInertia(): void
    {
        self::assertSame('inertia', (new InertiaModule())->name());
    }
}
