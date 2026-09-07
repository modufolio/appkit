# Modules

A module is a self-contained package that plugs its own services, controllers, entities, migrations, templates and translations into an application without living under the app's namespace. Use one when a feature is a reusable unit — a user directory, a blog, a media library — that more than one application should be able to install by adding a single line to a manifest.

The contract borrows the *shape* of a bundle system while staying on AppKit's container: a module contributes closures to the same `ServiceConfigurator` your `config/services.php` uses, with no compile step and nothing to cache-clear. It is called a module rather than a bundle because that is all it has to be. An application that puts [the Symfony container behind the kernel](dependency-injection.md#the-symfony-container-behind-the-kernel) gets the other half too: the same module then contributes to a `ContainerBuilder` like a bundle would — see [Modules as bundles](#modules-as-bundles).

## Registering modules

List modules in `config/modules.php`. An entry is either a bare class name or a `class => config` pair:

```php
// config/modules.php
return [
    \Modufolio\User\UserModule::class,                    // defaults only
    \Acme\Blog\BlogModule::class => ['per_page' => 20],   // with config
];
```

Then load them in your app factory, **before** `configureServices()`:

```php
$app->configureModules()                        // config/modules.php
    ->configureServices($serviceConfigurator)   // config/services.php — wins for shared ids
    ->configureSecurity($securityConfigurator)
    ->boot();
```

The override order is: kernel core services → module definitions → the application's `config/services.php`. Your app can re-declare any id a module set, the same way it overrides a kernel core service. `configureModules()` accepts an explicit manifest path for layouts where config does not live at `<baseDir>/config`.

### Per-environment modules

The reserved config key `envs` limits an entry to the environments it names — the case for a profiler or a fixture loader that must not exist in prod:

```php
return [
    \Modufolio\User\UserModule::class,                       // every environment
    \Modufolio\Clockwork\ClockworkModule::class => [
        'envs' => ['dev', 'test'],                             // only here
        'max_profiles' => 100,                                 // ordinary config
    ],
];
```

The key is the manifest's, not the module's: it is read and removed before the config reaches `defaultConfig()`, so a module never sees it. An entry outside its environments is not instantiated at all — no services, routes, entities or migrations — as if the line were not there; a module listed for every environment that `requires()` a dev-only one therefore fails in prod with the usual "not listed" error, which is the right outcome. `modules:list` shows each module's gate and the entries left out of the current environment. The environment is the kernel's (`APP_ENV`); the Symfony container's cache hash covers the resolved set, so a prod dump can never include a dev module.

## Writing a module

Extend `AbstractModule` and everything is derived from the class's location on disk; a typical module is an empty subclass. Override any method to opt out of a convention.

```
blog/
├── BlogModule.php          ← the module class; path() = this directory
├── config/services.php     ← service definitions, loaded automatically
├── Controller/             ← controllerPaths()
├── Entity/                 ← entityPaths()
├── Migrations/             ← migrationPaths()
├── templates/              ← templatePaths()
└── translations/           ← translationPaths()
```

```php
namespace Acme\Blog;

use Modufolio\Appkit\Module\AbstractModule;

final class BlogModule extends AbstractModule
{
    protected function defaultConfig(): array
    {
        return ['per_page' => 10];
    }
}
```

- `name()` is the class short name minus a trailing `Module`, lowercased (`BlogModule` → `blog`). Names must be unique across the manifest.
- The declared config from `config/modules.php` is merged **over** `defaultConfig()` with `array_replace_recursive()`. When `defaultConfig()` declares keys, an unknown declared key fails loudly — merge-under semantics would otherwise accept a typo silently. Override `validateConfig()` to accept free-form config alongside defaults.
- The merged config is published as the kernel parameter `module.<name>`, so untyped constructor arguments and templates can reach it.
- The manifest is validated as a whole: every mistake (unknown class, wrong interface, duplicate name, unsatisfied `requires()`) is collected and reported in **one aggregate error**, so a broken manifest is fixed in one round trip.

### Dependencies between modules

A module may declare what it builds on:

```php
public function requires(): array
{
    return [MediaModule::class];
}
```

The registry never loads or reorders anything — the manifest order stays authoritative. It only *verifies* that each required module is listed, and listed **before** the requirer, and refuses the manifest otherwise. Since `config/modules.php` is plain PHP, conditional module sets beyond the [`envs` key](#per-environment-modules) are just code — return a different list for an HTTP worker than for a queue worker.

### The phase contract

Every module's `services()` runs before **any** module's `boot()`:

- In `services()`, register closures only. Never resolve a service or assume another module is wired — even one listed earlier.
- In `boot(App)`, the whole container is assembled. Resolving services and reading merged config from any module is safe, regardless of manifest position.

This turns "manifest order matters" into "manifest order matters only for overrides and `requires()`".

### Module services

A module's `config/services.php` returns a closure like the application's, with the merged module config as a second argument:

```php
// blog/config/services.php
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;

return function (ServiceConfigurator $services, array $config): void {
    $services->shared(BlogRepository::class, fn ($app) => new BlogRepository(
        $app->entityManager(),
        $config['per_page'],
    ));
};
```

A module may also ship a `config/controllers.php` in the application's shape — a map of controller class to constructor dependencies. Module entries land underneath the application's map, so the app can rewire any controller a module ships:

```php
// blog/config/controllers.php
return [
    \Acme\Blog\Controller\PostController::class => [
        \Acme\Blog\Model\PostModel::class,   // resolved through the container
        '@thumbnailGenerator',               // an App accessor method
    ],
];
```

For wiring that needs PHP logic a config file shouldn't hold, override the programmatic hook instead (it runs after the file):

```php
protected function loadServices(ServiceConfigurator $services, array $config): void
{
    $services->set(FeedRenderer::class, fn () => new FeedRenderer($config['per_page']));
}
```

### Modules as bundles

When the application opted in to [the Symfony container behind the
kernel](dependency-injection.md#the-symfony-container-behind-the-kernel), a
module is also a bundle: the factory loads `<module>/config/container.php`
with the Symfony PHP DSL, and a module that implements the optional
`DependencyInjection\Symfony\ContainerBuilderAwareInterface` gets `build()`
called for programmatic definitions and compiler passes — the Symfony-side
counterparts of `config/services.php` and `loadServices()`. The module
contract and `AbstractModule` know nothing of Symfony: a module that ships
only `container.php` needs no interface and no dependency.

```php
// modules/blog/config/container.php
use Modufolio\Blog\Service\Feed;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(Feed::class)
            ->autowire()
            ->args(['$perPage' => '%module.blog.per_page%']);
};
```

```php
use Modufolio\Appkit\DependencyInjection\Symfony\ContainerBuilderAwareInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BlogModule extends AbstractModule implements ContainerBuilderAwareInterface
{
    public function build(ContainerBuilder $container, array $config): void
    {
        $container->addCompilerPass(new RegisterFeedFormatsPass());
    }
}
```

A module that owns the users tags its provider, and the application's
`userProvider()` fetches it by the published id without naming the module:

```php
// modules/user/config/container.php
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(UserRepository::class)
            ->factory([service(EntityManagerInterface::class), 'getRepository'])
            ->args([User::class])
            ->tag('appkit.user_provider');
};
```

```php
// src/App.php
public function userProvider(): UserProviderInterface
{
    return $this->get('appkit.user_provider', UserProviderInterface::class);
}
```

See [Tags the framework owns](dependency-injection.md#tags-the-framework-owns)
for the full list.

The module's merged configuration arrives as the parameters `module.<name>`
and `module.<name>.<key>`. A module that ships no `container.php` and no
`build()` costs nothing, and a module that does is ignored by an application
that never calls `configureContainer()`.

Phase contract: `build()` runs during `Kernel::boot()`, after every module's
`services()` and before any module's `boot()`, and the container is compiled
once the last module's `build()` returns — so register definitions, never
resolve. In `boot()` the finished container is reachable through the kernel.

### Lifecycle

`boot(AppInterface $app)` runs once per process at the end of `Kernel::boot()` — core services are wired, and token classes may still be registered (the unserialize whitelist freezes afterwards). `reset()` is for worker runtimes: call `resetModules()` from your application's `reset()` so modules drop per-request state alongside yours. Both are no-ops by default.

For state tied to one service rather than the whole module, register the cleanup next to the creation with `onReset()` — a one-shot callback run and cleared by `resetModules()`:

```php
$services->shared(ReportBuffer::class, function (App $app): ReportBuffer {
    $buffer = new ReportBuffer();
    $app->onReset(fn (bool $terminate) => $buffer->flush());

    return $buffer;
});
```

The callback receives `true` when the worker is terminating rather than finishing a request — the moment to close a pooled connection for good instead of returning the lease. Register `onReset()` only from `shared()` factories: a `set()` factory runs on every resolve and would pile up callbacks within one request.

**Pool ownership convention:** worker-lifetime state (a connection pool, a compiled template cache) lives in plain `set()`/accessor singletons owned by the module that provides it. Per-request leases from that pool are `shared()`. `reset()` and `onReset` callbacks return leases — they never destroy the pool.

## Routes, entities and migrations

The kernel wires services and boots modules; paths are pulled by the config files that own each concern, through `ModuleRegistry`, so everything stays in lock-step with the manifest:

```php
// config/routes.php — scan module controllers
use Modufolio\Appkit\Module\ModuleRegistry;

foreach (ModuleRegistry::controllerPaths(BASE_DIR) as $dir) {
    $routes->import($dir, 'attribute');
}
```

```php
// config/doctrine.php — module entities and migrations
$paths = [BASE_DIR.'/src/Entity', ...ModuleRegistry::entityPaths(BASE_DIR)];

$migrationsPaths = ['App\Migrations' => BASE_DIR.'/database/migrations']
    + ModuleRegistry::migrationNamespaces(BASE_DIR);
```

`ModuleRegistry::templatePaths()` and `translationPaths()` aggregate the same way for template loaders and translators.

## Library-first splitting

Keep the reusable domain logic in a plain library package with zero framework dependencies, and let the module be the thin integration seam that wires it into AppKit — entities, controllers, service definitions. Applications depend on the module; other frameworks (or plain scripts) can depend on the library alone.
