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

`#[IsGranted]` is repeatable, and follows the same logic as Symfony: each attribute is an independent requirement that must pass (**AND** between attributes), while multiple roles listed inside one attribute are alternatives (**OR**). The example below requires the user to hold **both** roles:

```php
#[IsGranted('ROLE_EDITOR')]
#[IsGranted('ROLE_VERIFIED')]
public function publish(): ResponseInterface
```

For an either/or check, list the roles in a single attribute:

```php
#[IsGranted(['ROLE_EDITOR', 'ROLE_ADMIN'])] // editor OR admin
public function edit(): ResponseInterface
```

Because attributes are AND'd, a method-level `#[IsGranted]` *tightens* a class-level one — it cannot widen access. A user must satisfy the class requirement **and** the method requirement.

The attributes are read by `AttributeClassLoader` at route load time — once on boot, not on every request. Each `#[IsGranted]` (class- and method-level) becomes one role group stored as `_is_granted_roles` (a list of groups) in the route's defaults. In production, Symfony serializes the entire route collection to `var/cache/router`, so the groups are compiled into that cache — clear it on deploy after changing access rules. At request time, enforcement is a single `$route->getDefault('_is_granted_roles')` lookup with no reflection involved.

The Kernel enforces the check during controller resolution, before any controller code executes.

For path-based rules that apply globally (outside individual routes), use `accessControl()` in `config/security.php`. See [Security](security.md).

## Generating URLs

Inside a template, use `$this->url()` to prepend the request's base URL (scheme + host + port) to a path:

```php
<a href="<?= $this->url('/about') ?>">About</a>
```

Inside a controller extending `AbstractController`, `$this->urlGenerator` is already
available — it is populated by `setSubscribedServices()`. Do not inject it:

```php
class MyController extends AbstractController
{
    public function redirect(): ResponseInterface
    {
        return Response::redirect($this->urlGenerator->generate('home'));
    }
}
```

> Redeclaring it as a promoted constructor property is a **fatal error**:
> `AbstractController` declares `protected UrlGeneratorInterface $urlGenerator`, and
> PHP does not allow a subclass to narrow an inherited property to `private`.

In a class that does *not* extend `AbstractController`, inject
`UrlGeneratorInterface` through `config/controllers.php` as normal.

`generate()` returns an absolute path by default. Pass `UrlGeneratorInterface::ABSOLUTE_URL` as the third argument for a full URL including scheme and host.

## Debugging routes

List all registered routes with their names, paths, and allowed methods:

```bash
php bin/console debug:router
```

Filter by name or path:

```bash
php bin/console debug:router login
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
