# Dependency injection

**In AppKit, your App class is the container.** Symfony compiles a container class you never read; AppKit skips the compiler because you write that class yourself — services are typed methods on `App`, lazily constructed and cached in properties you can see. The config files described below are the container's *edges*: `config/controllers.php` says what each controller receives, and `config/services.php` answers `get()` calls (controller dependencies, package interfaces), mostly by delegating to your `App` methods.

AppKit does not auto-wire dependencies. Every dependency is declared explicitly — as an `App` method or a config entry. This keeps the wiring visible, greppable, and easy to reason about.

Two config files control how services are resolved:

| File | Purpose |
|------|---------|
| `config/controllers.php` | Maps controller classes to their constructor dependencies |
| `config/services.php` | Declares the application's services via `ServiceConfigurator` |

The kernel pre-wires its own core services (router, session, entity manager, CSRF, serializer, …), so `config/services.php` only declares what the application adds on top — and can override any core service by re-declaring its id.

> **Legacy layout.** Older applications split service definitions across `config/interfaces.php` (closures bound to the kernel via `$this`) and `config/factories.php` (closures receiving the container). That split was stylistic, not architectural — both files fed the same lookup, and nothing enforced which file a service belonged in. The layout still works: pass `interfaces` in `fileMap` and `factories` to the constructor as before. New applications should use `config/services.php`; a migrated app drops both files, the `interfaces` fileMap entry, and the `factories` argument.

## Failure diagnostics

The container invests in its error paths so a wiring mistake is a one-glance fix:

- **Unknown ids suggest near-misses.** `get()` on a misspelled id lists close matches from every source the container knows — and when a match was registered by a module, says which one: `Did you mean "Modufolio\Blog\Model\PostModel" (from module "blog")?`. When the miss happens inside another service's factory, the message also names the requester: `(needed by "App\Service\Mailer")`.
- **Circular dependencies print the full chain.** Two factories resolving each other — even indirectly, through any number of `get()` calls — fail with `Circular dependency detected: A -> B -> C -> A` instead of a stack overflow.
- **Service ids can be deprecated.** Renaming a service while keeping the old id alive for one release:

```php
$services
    ->set(MediaRepository::class, fn (App $app) => /* ... */)
    ->alias(MediaStore::class, MediaRepository::class)
    ->deprecate(MediaStore::class, 'The "MediaStore" id is deprecated, use "MediaRepository".');
```

Resolving the deprecated id still works but triggers an `E_USER_DEPRECATED` warning once per process. This is the intended migration path for module service renames.

## Wiring a controller

When a controller has constructor dependencies, list the interface or class names in `config/controllers.php`. In a plain list the array order must match the constructor parameter order.

```php
// config/controllers.php
use App\Controller\PostController;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

return [
    PostController::class => [
        CsrfTokenManagerInterface::class,
        SessionInterface::class,
    ],
];
```

AppKit resolves each id through the container — kernel core services and `config/services.php` definitions alike — and passes the result to the constructor.

### Naming the arguments

Keying an entry by constructor parameter name passes the dependencies as named
arguments, so their order in the file no longer matters:

```php
return [
    PostController::class => [
        'session' => SessionInterface::class,
        'csrf'    => CsrfTokenManagerInterface::class,
    ],
];
```

Prefer this for anything with more than two dependencies. A positional list that
drifts out of sync with the constructor fails *silently* when the mismatched
parameters share a type — two strings in the wrong order are still two valid
strings, and the mistake only surfaces as wrong behaviour at runtime. With named
keys the arguments cannot be transposed, and a key that does not match any
parameter raises `Unknown named parameter` at construction.

Keys must match the parameter names exactly, without the `$`. Mixing the two
styles in one entry is not supported — use either a plain list or an entry where
every element is named.

Controllers that are *not* listed in `config/controllers.php` are wired by
reflection instead, which reads the parameter names straight from the
constructor. Those are always passed by name.

## Kernel core services

The kernel wires these itself — every interface backed by a kernel accessor or a dependency-free construction. They are available to any controller or factory with no configuration at all, and an entry in `config/services.php` with the same id overrides the default.

| Interface | Resolved from |
|-----------|---------------|
| `CsrfTokenManagerInterface` | `csrfTokenManager()` |
| `DebugStack` | the kernel's debug stack |
| `EntityManagerInterface` | `entityManager()` |
| `Environment` | `environment()` |
| `FlashBagAwareSessionInterface` | `session()` |
| `FlashBagInterface` | `session()->getFlashBag()` |
| `ParameterResolverInterface` | `parameterResolver()` |
| `ResponseFactoryInterface` | `new Psr17Factory()` |
| `ResponseInterface` | `new Response()` |
| `RouterInterface` | `router()` |
| `SerializerInterface` | `serializer()` |
| `ServerRequestInterface` | `request()` |
| `SessionInterface` | `session()` |
| `TokenStorageInterface` | `tokenStorage()` |
| `UrlGeneratorInterface` | `urlGenerator()` |
| `UserCheckerInterface` | `new UserChecker()` |
| `UserPasswordHasherInterface` | `new UserPasswordHasher()` |
| `UserProviderInterface` | `userProvider()` |
| `ValidatorInterface` | `validator()` |

## Declaring services

`config/services.php` returns a closure that receives a `ServiceConfigurator` — the same shape as `config/security.php` and its `SecurityConfigurator`. Every factory closure receives the application as its only argument (omit the parameter when nothing is needed); type it as your concrete `App` for IDE completion on your accessors.

```php
// config/services.php
use App\App;
use App\Service\Mailer;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;

return function (ServiceConfigurator $services): void {
    $services
        ->set(Mailer::class, fn (App $app) => new Mailer(
            $app->entityManager(),
            env('MAIL_DSN'),
        ));
};
```

`AppFactory` applies it before boot, alongside the security configurator:

```php
$serviceConfigurator = new ServiceConfigurator();
(require $baseDir . '/config/services.php')($serviceConfigurator);

$app->configureServices($serviceConfigurator)
    ->configureSecurity($securityConfigurator)
    ->boot();
```

Then add the class to the controller's entry in `config/controllers.php`:

```php
PostController::class => [
    Mailer::class,
    CsrfTokenManagerInterface::class,
],
```

### `set()`, `shared()`, `alias()`

**`set()`** runs the factory on every `get()` — a fresh instance each time, the semantics service definitions have always had. Keep it for anything callers expect fresh (a `Response`, for example).

**`shared()`** is a request-scoped singleton: the factory runs once, the result is cached in the kernel's instance table, and `reset()` clears it after the response. Use it for services that are expensive to build — one that parses a config file, say — or that must be the same object everywhere within a request:

```php
$services->shared(JsonApiRegistry::class, fn (App $app) => new JsonApiRegistry(
    $app->baseDir . '/config/json_api.php',
));
```

**`alias()`** points one id at another, resolved through the container. Use it to answer a package's interface with an application service without repeating its wiring:

```php
$services
    ->set(DefaultProps::class, fn (App $app) => new DefaultProps(/* … */))
    // The panel asks for SharedPropsInterface; this app answers with DefaultProps:
    ->alias(SharedPropsInterface::class, DefaultProps::class);
```

Aliasing a `shared()` target returns the same cached instance; aliasing a `set()` target builds fresh, like any other `get()`.

## Wiring repositories

Every Doctrine repository must be registered in `config/repositories.php`. The key is the repository class; the value is the entity class it manages.

```php
// config/repositories.php
use App\Entity\Post;
use App\Repository\PostRepository;

return [
    PostRepository::class => Post::class,
];
```

AppKit passes the entity class to Doctrine's `EntityManager::getRepository()` and returns the result.

## The `fileMap` mechanism

`AppFactory` passes the Doctrine config path to the kernel via `fileMap`:

```php
fileMap: [
    'doctrine' => $baseDir . '/config/doctrine.php',
],
```

An `interfaces` entry is the legacy path: when present, `boot()` `require`s that file *inside the kernel* — its closures see `$this` as the kernel — and its map **replaces** the kernel core services entirely, so such a file must carry the full core table itself. When absent, the kernel wires its core services and `config/services.php` supplies the rest.

## Parameter bag

The parameter bag stores scalar configuration values that can be referenced by name.

```php
// Setting a parameter (in AppFactory or boot logic)
$this->setParameter('upload.maxSize', 5 * 1024 * 1024);

// Reading a parameter anywhere you have kernel access
$max = $this->getParameter('upload.maxSize');
```

Reference a parameter in a controller's dependency list using `%name%` syntax:

```php
// config/controllers.php
UploadController::class => [
    '%upload.maxSize%',
],
```

## Reflection fallback

If a controller class is not listed in `config/controllers.php`, AppKit falls back to resolving its constructor arguments by reflection at runtime. Class-typed parameters are resolved from the container; `string` parameters are matched by name against the parameter bag.

> **Treat this as a safety net, not a wiring strategy.** It exists so a freshly scaffolded controller runs before you have wired it — nothing more. It runs reflection at request time, and it can silently produce wrong results: a parameter with a default value receives that default instead of the wired service. Every request that takes the fallback **logs a warning** naming the unwired controller, so the miss is visible in your logs rather than silent. Wire every controller explicitly in `config/controllers.php`; a controller that only works through the fallback is working by accident.

## Circular dependency detection

AppKit detects circular dependencies during resolution and throws a `RuntimeException`. Separately, you cannot inject the kernel itself into a service — that is blocked explicitly and throws a `LogicException`.

## The App class as a precompiled container

The kernel is a hand-wired, precompiled container — not an auto-wiring, reflection-based DI system. Every service is explicitly registered. There is no runtime class scanning, no annotation parsing at boot, and no dynamic instantiation. `get()` is a table lookup, not a factory.

When you call `$this->get(SomeInterface::class)`, AppKit walks the lookup tables in order:

1. Service definitions (`config/services.php`) — checked first, so they override everything below
2. Kernel core services (or the legacy `config/interfaces.php` map)
3. Singleton instances (`$this->instances`)
4. Repositories
5. Authenticators
6. Legacy factories (`config/factories.php`)

For services used on every request, the fastest path skips `get()` entirely. Add a direct typed method to your `App` class. AppKit's own `App.php` does this for `csrfTokenManager()`, `userProvider()`, `serializer()`, and `validator()`.

### Method or services.php? The rule

- **Typed method on `App`** when *your own code* calls the service — application composition, hot paths. This is the primary registry: typed, IDE-navigable, verified by static analysis.
- **`config/services.php` entry** when *something asks the container* for it — a controller constructor dependency, a framework interface, a package contract. These entries usually delegate to the method: `->set(TotpService::class, fn (App $app) => $app->totpService())`.

One service, one construction site: the method. The `services.php` entry is just its container-facing name.

### Overrides do not intercept method calls

A `services.php` definition wins inside `get()` — but `$this->mailer()` called directly on `App` (or via the `@mailer` syntax below) never touches the container. Overriding `Mailer::class` in `services.php` changes what controllers receive through `Mailer::class`; App-internal callers and `@mailer` still get the original. To replace a method-backed service everywhere, override the method — subclass `App`, as `RoadRunnerApp` does. This asymmetry is by design: direct calls stay direct.

### The lazy loading pattern

Declare a nullable property, then initialize it once using `??=`:

```php
// src/App.php
private ?Mailer $mailer = null;

public function mailer(): Mailer
{
    return $this->mailer ??= new Mailer(
        $this->entityManager(),
        env('MAIL_DSN'),
    );
}
```

`??=` means: if the property is null, evaluate the right-hand side, assign it, and return it. On every subsequent call, the already-constructed instance is returned directly — no lookup, no closure, no type check.

### Wiring with the `@` string

Once you have a direct method on `App`, reference it by name in `config/controllers.php` using the `@` prefix. This calls the method directly on the kernel — no `get()` resolution chain, no interface map lookup:

```php
// config/controllers.php
return [
    PostController::class => [
        '@mailer',            // calls $this->mailer() directly
        '@entityManager',     // calls $this->entityManager() directly
        '%upload.maxSize%',   // reads from the parameter bag
    ],
];
```

The three dependency syntaxes in `config/controllers.php`:

| Syntax | Resolved via |
|--------|-------------|
| `SomeInterface::class` | `$this->get(SomeInterface::class)` — walks the lookup tables |
| `'@methodName'` | `$this->methodName()` — direct call on the kernel |
| `'%paramName%'` | `$this->getParameter('paramName')` — parameter bag |

Use `@method` for any service that has a direct accessor on `App`. Reserve `InterfaceClass::class` for third-party services wired only through the interface map.

### `reset()` — RoadRunner and FrankenPHP only

Under PHP-FPM or the built-in dev server, each request spawns a fresh PHP process. The kernel is constructed, handles one request, and is discarded. `reset()` is never called and never matters.

Under RoadRunner or FrankenPHP, a single worker process handles many requests in sequence. The kernel persists. `reset()` is called after every response to clear objects whose lifecycle should match a single request rather than the lifetime of the worker.

Only add a service to `reset()` when it holds state that must not bleed into the next request — an accumulated log, a unit-of-work that tracked changes, an object constructed with the current user. Configuration, validators, serializers, and anything built purely from env vars or config files stay out of `reset()` and live for the entire worker lifetime.

`Kernel::reset()` is **abstract** — there is no framework implementation to inherit.
Your concrete `App` writes the whole method:

```php
// src/App.php — extends Kernel
public function reset(): void
{
    $this->state?->reset();
    $this->state = null;

    $this->entityManagerFactory?->reset();
    $this->instances = []; // also expires shared() services from config/services.php

    // Only services that accumulate per-request state:
    $this->mailer = null;
}
```

Subclasses of your `App` (a `RoadRunnerApp`, say) *can* call `parent::reset()`,
because at that point the parent is concrete. Calling it directly from a class that
extends `Kernel` is a fatal error.

See [Deployment](deployment.md#the-reset-contract) for what
`AbstractApplicationState::reset()` covers and what it leaves to you.

If you run only PHP-FPM, you never need to touch `reset()`.


