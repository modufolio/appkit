<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Inertia\Flash\FlashBagStore;
use Modufolio\Appkit\Inertia\Flash\FlashStoreInterface;
use Modufolio\Appkit\Module\AbstractModule;

/**
 * Wires the {@see InertiaRenderer} the kernel uses to finish the pages
 * controllers return. List it in config/modules.php:
 *
 * ```php
 * return [
 *     \Modufolio\Appkit\Inertia\InertiaModule::class => [
 *         'version_file' => __DIR__ . '/sri.php',
 *     ],
 * ];
 * ```
 *
 * The host declares {@see RootViewInterface} (its HTML document) and, when
 * it has one, {@see SharedPropsInterface} in config/services.php; the module
 * builds the renderer from them, with flash data kept in the session's flash
 * bag unless the host declares a {@see FlashStoreInterface} of its own. A
 * host that declares {@see InertiaRendererInterface} — or `InertiaRenderer`
 * itself — keeps its own: application definitions sit above module
 * definitions, which is also how a decorator gets in front of this one.
 *
 * Configuration keys:
 *   - `version`: the asset version string, when the host knows it
 *   - `version_file`: a file whose contents hash into the version (a
 *     manifest, an SRI map); ignored when `version` is set
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class InertiaModule extends AbstractModule
{
    protected function defaultConfig(): array
    {
        return ['version' => null, 'version_file' => null];
    }

    protected function loadServices(ServiceConfigurator $services, array $config): void
    {
        $version = self::version($config);

        // Shared: one renderer per request, so `$app->inertia()` and
        // `$this->inertia` are the same object — a flash put on one is read
        // by the other even where the store is not session-backed. It holds
        // the request's flash bag, so it must not outlive the request: the
        // instance table an application's reset() clears is what makes that
        // true (docs/deployment.md, "The reset contract").
        $services->shared(InertiaRenderer::class, static function (AppInterface $app) use ($version): InertiaRenderer {
            if (!$app->has(RootViewInterface::class)) {
                throw new \LogicException(sprintf(
                    'Inertia needs the HTML document a first visit receives: declare %s in config/services.php.',
                    RootViewInterface::class,
                ));
            }

            $rootView = $app->get(RootViewInterface::class);

            if (!$rootView instanceof RootViewInterface) {
                throw new \LogicException(sprintf('The service "%s" must be a root view, %s given.', RootViewInterface::class, get_debug_type($rootView)));
            }

            $shared = $app->has(SharedPropsInterface::class) ? $app->get(SharedPropsInterface::class) : null;

            return new InertiaRenderer(
                $rootView,
                $version,
                $shared instanceof SharedPropsInterface ? $shared : null,
                self::flashStore($app),
            );
        });

        // The seam the kernel asks through. An application that declares
        // either id in config/services.php wins over both, since application
        // definitions sit above module definitions.
        $services->alias(InertiaRendererInterface::class, InertiaRenderer::class);
    }

    /**
     * Flash data survives a redirect in the session's flash bag, which the
     * kernel offers as a core service; a host may declare its own store.
     */
    private static function flashStore(AppInterface $app): ?FlashStoreInterface
    {
        if ($app->has(FlashStoreInterface::class)) {
            $store = $app->get(FlashStoreInterface::class);

            return $store instanceof FlashStoreInterface ? $store : null;
        }

        try {
            return new FlashBagStore($app->session()->getFlashBag());
        } catch (\RuntimeException) {
            // No request yet (a console command building the renderer): no
            // session to keep flash data in.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>       $config
     * @return string|\Closure(): string
     */
    private static function version(array $config): string|\Closure
    {
        $version = $config['version'] ?? null;

        if (is_string($version) && $version !== '') {
            return $version;
        }

        $file = $config['version_file'] ?? null;

        if (is_string($file) && $file !== '') {
            return InertiaRenderer::versionFromFile($file);
        }

        return '';
    }
}
