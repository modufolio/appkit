<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Module;

use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;

/**
 * A module is a self-contained, namespaced package that plugs its own
 * services, controllers/routes, entities, migrations, templates and
 * translations into an application without living under the app's namespace.
 *
 * Modules are listed in `config/modules.php` and integrated by the kernel
 * (via {@see \Modufolio\Appkit\Core\Kernel::configureModules()}), while
 * `config/routes.php` and `config/doctrine.php` pull path contributions from
 * {@see ModuleRegistry} so module services, routes, entities and migrations
 * stay in lock-step.
 *
 * The contract borrows the shape of a bundle system, trimmed to AppKit's
 * scale and container — deliberately *not* named "bundle" because it is not a
 * Symfony bundle and takes no ContainerBuilder:
 *   - wiring seam       → services(ServiceConfigurator, config)
 *   - runtime lifecycle → boot()/reset()          (for worker models)
 *   - path conventions  → *Paths() methods        (resolved from path())
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ModuleInterface
{
    /**
     * Short, unique module identifier (e.g. "user"). Also the key under which
     * the merged module config is published as the kernel parameter
     * "module.<name>".
     */
    public function name(): string;

    /**
     * Absolute path to the module root directory (contains Entity/,
     * Controller/, config/, Migrations/, templates/, translations/, …).
     */
    public function path(): string;

    /**
     * Wiring seam: contribute service definitions to the application's
     * configurator. Receives the module's declared configuration (the value
     * from config/modules.php); implementations merge it over their own
     * defaults. Definitions land underneath the application's own
     * config/services.php, so the app can override any id a module sets.
     *
     * Phase contract: every module's services() runs before ANY module's
     * boot(). Inside services(), register closures only — never resolve a
     * service or assume another module is wired. Inside boot(), the whole
     * container is assembled: resolving services and reading config from any
     * module is safe regardless of manifest position.
     *
     * @param array<string, mixed> $config
     */
    public function services(ServiceConfigurator $services, array $config): void;

    /**
     * Modules this one depends on, as module class names. The registry does
     * not load or reorder anything: it verifies that each required module is
     * present in config/modules.php and listed BEFORE this one, and fails
     * loudly otherwise. The manifest order stays authoritative.
     *
     * @return list<class-string<ModuleInterface>>
     */
    public function requires(): array;

    /**
     * The module's effective configuration: defaults merged with the values
     * declared in config/modules.php. Before services() has run, only the
     * defaults are available.
     *
     * @return array<string, mixed>
     */
    public function config(): array;

    /**
     * Controller dependency map, in the same shape as the application's
     * config/controllers.php. Entries land underneath the application's own
     * map, so the app can rewire any controller a module ships.
     *
     * @return array<class-string, array<int|string, mixed>>
     */
    public function controllers(): array;

    /**
     * Runtime lifecycle hook, called once per process at the end of
     * Kernel::boot(). Core services are wired at that point; token classes may
     * still be registered (the unserialize whitelist freezes afterwards). In a
     * long-running worker this is where per-worker warmup belongs; pair it
     * with reset() to clear per-request state.
     */
    public function boot(AppInterface $app): void;

    /**
     * Runtime lifecycle hook, called between requests in a worker model to
     * drop per-request state. A no-op under request-per-process servers.
     */
    public function reset(): void;

    /**
     * Directories containing Doctrine entities (any namespace, resolved by PSR-4).
     *
     * @return list<string>
     */
    public function entityPaths(): array;

    /**
     * Directories to scan for attribute-routed controllers.
     *
     * @return list<string>
     */
    public function controllerPaths(): array;

    /**
     * Directories containing Doctrine migration classes.
     *
     * @return list<string>
     */
    public function migrationPaths(): array;

    /**
     * Directories containing view templates.
     *
     * @return list<string>
     */
    public function templatePaths(): array;

    /**
     * Directories containing translation catalogues.
     *
     * @return list<string>
     */
    public function translationPaths(): array;
}
