# The Kernel

The Kernel is the heart of every AppKit application. It acts as the HTTP request handler, the service container, and the boot coordinator. Your application's `App` class extends it.

The class itself stays small: it owns the state (every property lives on the Kernel), the boot/reset lifecycle, and the core service accessors. Behavior is composed from one trait per concern — `AppContainer` (service resolution, repositories, parameters), `AppControllers` (controller wiring and instantiation), `AppModules` (module lifecycle), `AppRouting` (router and URL generation), and `AppSecurity` (authentication flow and firewall configuration). Traits hold no state of their own, and every method kept its name, visibility and signature when it moved — from your `App` subclass, nothing changed. Read the trait for the concern you care about; the Kernel file tells you the order things happen in.

## Extending the Kernel

```php
namespace App;

use Modufolio\Appkit\Core\Kernel;

class App extends Kernel
{
    public function handle(ServerRequestInterface $request): ResponseInterface { ... }
    public function reset(): void { ... }
    public function serializer(): SerializerInterface { ... }
    public function parameterResolver(): ParameterResolverInterface { ... }
    public function validator(): ValidatorInterface { ... }
    public function userProvider(): UserProviderInterface { ... }
}
```

These six abstract methods are your integration points. The skeleton's `src/App.php` provides a complete implementation you can use as a reference.

## The request lifecycle

1. `public/index.php` calls `AppFactory::create($baseDir)` — this instantiates `App`, loads config files, and calls `boot()`.
2. `boot()` applies error-output hardening for the environment (see [Exception handling](exception-handling.md#error-output-hardening)), wires the kernel core services (or loads a legacy `config/interfaces.php` when mapped), sets up the router cache directory, and freezes the token unserializer whitelist.
3. `handle(ServerRequestInterface $request)` is called. It creates a fresh `NativeApplicationState` for the request, then calls `handleAuthentication()`.
4. `handleAuthentication()` determines the active firewall, attempts session token restoration, runs authenticators if needed, and either calls `controllerResolver()` or returns an authentication response.
5. `controllerResolver()` enforces global access control, matches the route, enforces attribute-level access control (`#[IsGranted]`), instantiates the controller, resolves method parameters, and calls the controller method.
6. The controller returns a `ResponseInterface`. `prepareResponse()` finalises headers and cookies.
7. The response is emitted to the client. `reset()` clears request-scoped state.

## Environment

`Modufolio\Appkit\Core\Environment` is an enum with three cases.

```php
use Modufolio\Appkit\Core\Environment;

Environment::DEV   // 'dev'
Environment::TEST  // 'test'
Environment::PROD  // 'prod'
```

Helper methods:

```php
$env->isDev();   // bool
$env->isTest();  // bool
$env->isProd();  // bool
```

The current environment is read from `APP_ENV`. It affects router caching (`var/cache/prod/router` in prod), debug mode, error-output handling (see [Exception handling](exception-handling.md#error-output-hardening)), and exception detail visibility. Persistent caches live under `Kernel::cacheDir()` — `var/cache/<env>` — so environments never share a cache.

Access the environment from anywhere you have the kernel:

```php
$this->environment()->isProd(); // inside App or a class with access to the kernel
```

## Application state

`NativeApplicationState` is created once per request and holds request-scoped data: the current `ServerRequestInterface`, the session, the token storage, firewall cache, and controller instances. The concept is inspired by [Axum's `State` extractor](https://docs.rs/axum/latest/axum/extract/struct.State.html) from Rust.

After the response is sent, `reset()` clears this state. This makes AppKit compatible with RoadRunner, where the same process handles many requests.

Note that `AbstractApplicationState::reset()` covers only session, session storage, token storage, request instances and the firewall cache. `Kernel::reset()` is abstract — your `App` is responsible for the rest, including the router (which holds a **static** compiled-route cache) and the entity manager. See [Deployment](deployment.md#the-reset-contract).

Session cookies are set with `HttpOnly` and `SameSite=Lax` by default. Set `COOKIE_SECURE=true` in your environment to add the `Secure` flag.

## The service container

The Kernel implements `ContainerInterface`. Call `get(string $id)` to resolve a service.

Resolution order:

1. Service definitions (`config/services.php`)
2. Kernel core services (or the legacy `config/interfaces.php` map)
3. Singleton instances (already-created services)
4. Repositories (Doctrine entity repositories)
5. Authenticators (`config/authenticators.php`)
6. Legacy factories (`config/factories.php`)
7. `NotFoundException` if nothing matched

```php
use Doctrine\ORM\EntityManagerInterface;

$em = $this->get(EntityManagerInterface::class);
```

You rarely call `get()` directly. Dependencies are wired through config files and injected into controllers. See [Dependency injection](dependency-injection.md).

## Core service accessors

The Kernel exposes lazy-loaded services as methods. Use these inside `App` or config closures.

| Method | Returns |
|--------|---------|
| `entityManager()` | `EntityManagerInterface` |
| `environment()` | `Environment` |
| `router()` | `RouterInterface` |
| `session()` | `FlashBagAwareSessionInterface` |
| `tokenStorage()` | `TokenStorageInterface` |
| `userProvider()` | `UserProviderInterface` |
| `validator()` | `ValidatorInterface` |
| `serializer()` | `SerializerInterface` |
| `parameterResolver()` | `ParameterResolverInterface` |
| `exceptionHandler()` | `ExceptionHandlerInterface` |
| `csrfTokenManager()` | `CsrfTokenManagerInterface` |
| `request()` | `ServerRequestInterface` (the current request) |
| `urlGenerator()` | `UrlGeneratorInterface` |
| `logger()` | `LoggerInterface` |
| `emitter()` | `EmitterInterface` |

Your `App` continues this pattern for its own services — a typed method per service, lazily constructed with `??=`, cleared in `reset()` when request-scoped. This is the primary way services are defined in AppKit; see [Dependency injection](dependency-injection.md#the-app-class-as-a-precompiled-container).

## URL helpers

```php
$this->url('/login');     // https://example.com/login  (base derived from the current request)
$this->baseUrl();         // https://example.com  (scheme://host[:port] of the request)
$this->generateUrl('home');                                    // /
$this->generateUrl('post.show', ['slug' => 'hello']);          // /posts/hello
```

`generateUrl()` wraps Symfony's `UrlGenerator`. The third argument accepts `UrlGeneratorInterface::ABSOLUTE_URL` to force a full URL.

## Parameter bag

Store and retrieve scalar configuration values.

```php
$this->setParameter('app.name', 'My App');
$this->getParameter('app.name'); // 'My App'
$this->hasParameter('app.name'); // true
```

Parameters are available inside controller config as `%app.name%` strings.

## Booting the application

`AppFactory::create(string $baseDir)` is the standard entry point. The factory pattern is inspired by [Slim PHP](https://www.slimframework.com/). It:

1. Registers `User::class` with `TokenUnserializer` (whitelist-based session deserialization).
2. Creates a route loader that scans `src/Controller/` for `#[Route]` attributes and also loads PHP route files.
3. Reads `config/security.php` into a `SecurityConfigurator` and `config/services.php` into a `ServiceConfigurator`.
4. Passes all config arrays to `App`, calls `configureServices()` and `configureSecurity()`, and calls `boot()`.

You can create your own factory if you need a different setup.
