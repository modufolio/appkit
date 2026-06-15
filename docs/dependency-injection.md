# Dependency injection

AppKit does not auto-wire dependencies. Every dependency is declared explicitly in config files. This keeps the wiring visible, greppable, and easy to reason about.

Three config files control how services are resolved:

| File | Purpose |
|------|---------|
| `config/controllers.php` | Maps controller classes to their constructor dependencies |
| `config/interfaces.php` | Maps interfaces to the kernel methods that produce them |
| `config/factories.php` | Registers custom service factory closures |

## Wiring a controller

When a controller has constructor dependencies, list the interface or class names in `config/controllers.php`. The array order must match the constructor parameter order.

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

AppKit resolves each interface via `config/interfaces.php` and passes the result to the constructor.

## Available interfaces

`config/interfaces.php` ships pre-wired with all core framework services. These are available to any controller or factory without any extra configuration.

| Interface | Resolved from |
|-----------|---------------|
| `BruteForceProtectionInterface` | `new FileBruteForceProtection(...)` — security default, customise as needed |
| `CsrfTokenManagerInterface` | `$this->csrfTokenManager()` |
| `EntityManagerInterface` | `$this->entityManager()` |
| `Environment` | `$this->environment()` |
| `FlashBagAwareSessionInterface` | `$this->session()` |
| `FlashBagInterface` | `$this->session()->getFlashBag()` |
| `ParameterResolverInterface` | `$this->parameterResolver()` |
| `ResponseFactoryInterface` | `new Psr17Factory()` |
| `ResponseInterface` | `new Response()` |
| `RememberMeAuthenticator` | `new RememberMeAuthenticator(...)` — security default, customise as needed |
| `RouterInterface` | `$this->router()` |
| `SerializerInterface` | `$this->serializer()` |
| `ServerRequestInterface` | `$this->request()` |
| `SessionInterface` | `$this->session()` |
| `TokenStorageInterface` | `$this->tokenStorage()` |
| `UrlGeneratorInterface` | `$this->router()->getUrlGenerator()` |
| `UserCheckerInterface` | `new UserChecker()` |
| `UserPasswordHasherInterface` | `new UserPasswordHasher()` |
| `UserProviderInterface` | `$this->userProvider()` |
| `ValidatorInterface` | `$this->validator()` |

The closures in `config/interfaces.php` use `$this`, which binds to the kernel instance at load time. Do not move these closures outside the file or into a static context.

## Registering a custom service

Add a factory closure to `config/factories.php`. The closure receives the kernel as `$container`.

```php
// config/factories.php
use App\Service\Mailer;
use Doctrine\ORM\EntityManagerInterface;

return [
    Mailer::class => function ($container) {
        return new Mailer(
            $container->get(EntityManagerInterface::class),
            getenv('MAIL_DSN'),
        );
    },
];
```

Then add the class to the controller's entry in `config/controllers.php`:

```php
PostController::class => [
    Mailer::class,
    CsrfTokenManagerInterface::class,
],
```

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

## Binding a custom interface

To inject your own interface (rather than a concrete class), add an entry to `config/interfaces.php`:

```php
use App\Contract\MailerInterface;
use App\Service\Mailer;

return [
    // existing entries...
    MailerInterface::class => fn () => $this->get(Mailer::class),
];
```

The closure binds to the kernel, so `$this->get()` works here.

## The `fileMap` mechanism

`AppFactory` passes two file paths to the kernel via `fileMap`:

```php
fileMap: [
    'doctrine'   => $baseDir . '/config/doctrine.php',
    'interfaces' => $baseDir . '/config/interfaces.php',
],
```

On `boot()`, the kernel loads `config/interfaces.php` as a PHP file that returns an array of closures. Because the file is `require`d inside the kernel, the closures have access to `$this` — which refers to the kernel instance.

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

> **This is not intended behavior.** The reflection fallback exists as a safety net, not a feature. It runs at request time, carries the overhead of `ReflectionClass`, and can silently produce wrong results — for example, a parameter with a default value will receive that default instead of the wired service. Always wire controllers explicitly in `config/controllers.php`. If a controller works without being registered, it is working by accident.

## Circular dependency detection

AppKit detects circular dependencies during resolution and throws a `LogicException`. You cannot inject the kernel itself into a service — this is blocked explicitly to prevent infinite resolution loops.

## The App class as a precompiled container

The kernel is a hand-wired, precompiled container — not an auto-wiring, reflection-based DI system. Every service is explicitly registered. There is no runtime class scanning, no annotation parsing at boot, and no dynamic instantiation. `get()` is a table lookup, not a factory.

When you call `$this->get(SomeInterface::class)`, AppKit walks five lookup tables in order:

1. Interface map (`config/interfaces.php`)
2. Singleton instances (`$this->instances`)
3. Repositories
4. Authenticators
5. Factories (`config/factories.php`)

For services used on every request, the fastest path skips `get()` entirely. Add a direct typed method to your `App` class. AppKit's own `App.php` does this for `csrfTokenManager()`, `userProvider()`, `serializer()`, and `validator()`.

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

```php
public function reset(): void
{
    parent::reset();

    // Only if this service accumulates per-request state:
    $this->mailer = null;
}
```

If you run only PHP-FPM, you never need to touch `reset()`.


