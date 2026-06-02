# Routing

AppKit uses Symfony Routing under the hood. Routes are declared with PHP 8 attributes directly on controller methods. The `config/routes.php` file points the loader at your `src/Controller/` directory, so every class in there is scanned automatically.

## Declaring a route

```php
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/about', name: 'about', methods: ['GET'])]
public function index(): ResponseInterface
{
    // ...
}
```

The three most common parameters:

| Parameter | Description |
|-----------|-------------|
| `path` | The URL path to match. |
| `name` | A unique name used for URL generation. |
| `methods` | Array of HTTP verbs. Omit to match all methods. |

## Route parameters

Add `{placeholder}` segments to the path. They are passed as typed method arguments.

```php
#[Route(path: '/posts/{slug}', name: 'post.show', methods: ['GET'])]
public function show(string $slug): ResponseInterface
{
    // $slug contains the value from the URL
}
```

Multiple parameters work the same way:

```php
#[Route(path: '/users/{id}/posts/{slug}', name: 'user.post', methods: ['GET'])]
public function userPost(int $id, string $slug): ResponseInterface
```

## Restricting HTTP methods

Declare multiple routes on the same method to handle different verbs:

```php
#[Route(path: '/login', name: 'login', methods: ['GET'])]
#[Route(path: '/login', name: 'login.post', methods: ['POST'])]
public function login(): ResponseInterface
```

For destructive or state-changing actions, always restrict to `POST`. The skeleton's logout follows this pattern:

```php
#[Route(path: '/logout', name: 'logout', methods: ['POST'])]
```

## Protecting routes with `#[IsGranted]`

Place `#[IsGranted]` on a class or method to require specific roles. AppKit enforces this before the controller method runs.

```php
use Modufolio\Appkit\Attributes\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route(path: '/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(): ResponseInterface { ... }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/dashboard/settings', name: 'dashboard.settings', methods: ['GET'])]
    public function settings(): ResponseInterface { ... }
}
```

`#[IsGranted]` is repeatable — add multiple instances to require all listed roles:

```php
#[IsGranted('ROLE_EDITOR')]
#[IsGranted('ROLE_VERIFIED')]
public function publish(): ResponseInterface
```

The attribute is read by `AttributeClassLoader` at route load time — once on boot, not on every request. Class-level and method-level `#[IsGranted]` attributes are merged, deduplicated, and stored as `_is_granted_roles` in the route's defaults. In production, Symfony serializes the entire route collection to `var/cache/router`, so the required roles are compiled into that cache. At request time, enforcing access control is a single `$route->getDefault('_is_granted_roles')` lookup with no reflection involved.

The Kernel enforces the check during controller resolution, before any controller code executes.

For path-based rules that apply globally (outside individual routes), use `accessControl()` in `config/security.php`. See [Security](security.md).

## Generating URLs

Inside a template, use `$this->url()` to prepend `APP_URL` to a path:

```php
<a href="<?= $this->url('/about') ?>">About</a>
```

Inside a controller, inject `UrlGeneratorInterface` and use `generate()`:

```php
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MyController extends AbstractController
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function redirect(): ResponseInterface
    {
        return Response::redirect($this->urlGenerator->generate('home'));
    }
}
```

`generate()` returns an absolute path by default. Pass `UrlGeneratorInterface::ABSOLUTE_URL` as the third argument for a full URL including scheme and host.

## Debugging routes

List all registered routes with their names, paths, and allowed methods:

```bash
php bin/console router:debug
```

Filter by name or path:

```bash
php bin/console router:debug login
```

## Adding routes manually

For redirects, aliases, or routes that don't need a dedicated controller class, add them directly in `config/routes.php`:

```php
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    // Auto-discover controller attributes
    $routes->import('../src/Controller/', 'attribute');

    // Manual redirect alias
    $routes->add('home.alias', '/start')
        ->methods(['GET']);
};
```

## Route loading

`config/routes.php` uses a `DelegatingLoader` that supports both PHP route files and attribute-scanned directories. `AppFactory` wires this up automatically. You do not need to register new controllers — dropping a class into `src/Controller/` with a `#[Route]` attribute is enough.

AppKit ships additional route loaders for specific use cases:

| Loader | Type string | Use case |
|--------|-------------|----------|
| `AttributeClassLoader` | *(default)* | `#[Route]` attributes on controller classes |
| `ArrayRouteLoader` | `array` | Explicit PHP array route definitions |
| `FlatFileRouteLoader` | `flat_file` | Filesystem-based routing — folder structure maps to URLs |
| `JsonApiRouteLoader` | `json_api` | Auto-generated JSON:API CRUD routes — see [modufolio/json-api](https://github.com/modufolio/json-api) |
