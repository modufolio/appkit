<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Module\ModuleRegistry;

/**
 * Module lifecycle: loading the manifest, applying module services and
 * controller maps, and fanning out reset between requests.
 *
 * Behavior only: every property this trait touches is declared on {@see Kernel},
 * which composes it. Method names, visibility and signatures are unchanged from
 * their previous home on the kernel.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
trait AppModules
{
    /**
     * Load the modules listed in config/modules.php and apply their service
     * definitions.
     *
     * Call before configureServices(): a later configureServices() call wins
     * for the same id, so module definitions sit between the kernel's core
     * services and the application's own config/services.php — exactly the
     * override order a consumer expects. Module boot() hooks run at the end of
     * boot(); pair them by calling resetModules() from your reset().
     */
    public function configureModules(?string $file = null): static
    {
        $this->modules = ModuleRegistry::load($this->baseDir, $file ?? $this->baseDir.'/config/modules.php');

        $configurator = new ServiceConfigurator();
        foreach ($this->modules as $module) {
            $before = array_keys($configurator->definitions);
            $module->services($configurator, ModuleRegistry::configFor($this->baseDir, $module));
            // Remember which module declared each id, so a typo's lookup can
            // say where the near-miss came from.
            foreach (array_diff(array_keys($configurator->definitions), $before) as $id) {
                $this->serviceProvenance[$id] = $module->name();
            }
            // Module controller wiring sits under the application's map: +=
            // keeps existing (app) entries for the same controller id.
            $this->controllers += $module->controllers();
        }

        return $this->configureServices($configurator);
    }

    /**
     * Fan reset() out to the registered modules. Call from your application's
     * reset() so modules drop per-request state under worker runtimes.
     */
    public function resetModules(bool $terminate = false): void
    {
        // One-shot callbacks first: they return per-request leases (pooled
        // connections, buffers) registered by the factories that took them.
        // Run-and-clear — a shared() factory re-registers on its next build.
        $callbacks = $this->resetCallbacks;
        $this->resetCallbacks = [];

        $failure = null;
        foreach ($callbacks as $callback) {
            try {
                $callback($terminate);
            } catch (\Throwable $e) {
                // Keep resetting; a single bad callback must not leak the rest.
                $failure ??= $e;
            }
        }

        foreach ($this->modules as $module) {
            try {
                $module->reset();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if (null !== $failure) {
            throw $failure instanceof \Exception ? $failure : new \RuntimeException($failure->getMessage(), 0, $failure);
        }
    }

    /**
     * Register a one-shot cleanup to run on the next resetModules() call.
     *
     * Intended for shared() factories that take per-request leases (a pooled
     * connection, an output buffer): register the release next to the take, so
     * create and clean up live in one closure. The callback receives true when
     * the worker is terminating rather than finishing a request. Callbacks are
     * cleared after running — a factory that runs again registers again. Do
     * not call from set() factories: they run on every resolve and would pile
     * up callbacks within a single request.
     *
     * @param callable(bool): void $callback
     */
    public function onReset(callable $callback): void
    {
        $this->resetCallbacks[] = $callback;
    }
}
