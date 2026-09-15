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

A check can be scoped to specific HTTP methods with `methods:`, which is useful when one route serves both a safe read and a mutating write. Listing `GET` implicitly covers `HEAD`, and an omitted `methods` applies the check to every method:

```php
#[Route(path: '/posts', name: 'posts', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_ADMIN', methods: ['POST'])] // reading is open, writing is not
public function posts(): ResponseInterface
```

Because attributes are AND'd, a method-level `#[IsGranted]` *tightens* a class-level one — it cannot widen access. A user must satisfy the class requirement **and** the method requirement.

The attributes are read by `AttributeClassLoader` at route load time — once on boot, not on every request. Each `#[IsGranted]` (class- and method-level) becomes one role group stored as `_is_granted_roles` (a list of groups) in the route's defaults. A group is a plain list of roles, or a `['roles' => …, 'methods' => …]` map when it is method-scoped. The compiled routes are cached in `var/cache/<env>/router` in every environment, and the groups ride along in that dump. In production nothing checks the cache for staleness, so clear it on deploy after changing access rules; in development it rebuilds itself when a controller's signature changes. See [Deployment](deployment.md#the-router-cache). At request time, enforcement is a single `$route->getDefault('_is_granted_roles')` lookup with no reflection involved.

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

`generate()` returns an absolute path by default. Pass `UrlGeneratorInterface::ABSOLUTE_URL` as the third argument for a full URL including scheme and host. The host is the request's `Host` header, so absolute URLs that leave the response (password-reset emails, canonical links) need the [trusted-hosts allowlist](security/trusted-hosts.md) configured, or a spoofed header ends up in the link.

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

For routes that don't need a dedicated controller class, add them directly in `config/routes.php`. A route added this way needs a `_controller` default — `add()` on its own declares a path with nothing to run, and matching it produces a 404:

```php
use App\Controller\HomeController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    // Auto-discover controller attributes
    $routes->import('../src/Controller/', 'attribute');

    // A second path served by an existing controller method
    $routes->add('home.alias', '/start')
        ->controller([HomeController::class, 'index'])
        ->methods(['GET']);
};
```

For redirects use the [redirect loader](#redirects), not a manual route.

## Deriving data from the routes

Anything that needs a *view* of the routes rather than a match — a menu, a list
of route names, a permission map — should ask `cachedRouteData()` instead of
walking `getRouteCollection()`.

```php
$menu = $router->cachedRouteData(
    'panel_menu',
    static fn (RouteCollection $routes): array => MyMenu::fromRoutes($routes),
);
```

The projection is dumped next to the compiled matcher and generator, in
`var/cache/<env>/router/route_data_<key>.php`, and tracks the same resources —
so it rebuilds exactly when they do and is cleared by the same
`rm -rf var/cache/<env>/router/`.

Why it matters: `getRouteCollection()` is a **build-time** artifact. Calling it
reloads every route from source, which on an attribute-driven application means
tokenising and reflecting every controller — tens of milliseconds. The compiled
matcher and generator exist precisely so that a request never has to. A caller
that reaches for the collection at request time steps around that whole
mechanism, and pays for it on every request, including ones that never use the
result.

`$project` is called only on a cache miss, so it must not depend on the current
request, and it must return an array `var_export()` can round-trip. `$key` is
used as a filename: `[a-z0-9_-]` only. With no `cache_dir` configured the
projection is built per request and memoised, matching how the matcher and
generator behave in that mode.

Watch for this in constructor arguments in particular — PHP evaluates them
eagerly, so `new Thing($router->getRouteCollection())` pays the full cost even
when the object is never used.

## Route loading

Your application builds a `DelegatingLoader` over a `LoaderResolver` and hands it to the `App`; the resolver picks the loader whose `supports()` accepts the type string. Symfony's `PhpFileLoader` reads `config/routes.php` and its `AttributeDirectoryLoader` scans directories with AppKit's `AttributeClassLoader`, so you never register controllers — dropping a class into `src/Controller/` with a `#[Route]` attribute is enough.

> **Application code.** `AppFactory` is not part of the framework. The skeleton (`modufolio/appkit-skeleton`) ships a starting version in `src/AppFactory.php` — `create(string $baseDir): AppInterface`, which builds the route loader, loads the config files and boots `App` — and the framework's test application keeps its own in `tests/App/AppFactory.php`; it is yours to change. Only the loaders that factory puts in the resolver are available — the skeleton registers `PhpFileLoader` and `AttributeDirectoryLoader`, the framework's test app adds `ArrayRouteLoader` and `JsonApiRouteLoader`; add `RedirectRouteLoader` and `FlatFileRouteLoader` there the same way when you use them.

AppKit ships these route loaders:

| Loader | Type string | Use case |
|--------|-------------|----------|
| `AttributeClassLoader` | `attribute` (through Symfony's `AttributeDirectoryLoader`) | `#[Route]` attributes on controller classes — see [Attribute routes](#attribute-routes) |
| `ArrayRouteLoader` | `array` | Explicit PHP array route definitions — see [Array routes](#array-routes) |
| `FlatFileRouteLoader` | `flat_file` | Filesystem-based routing — see [Flat-file routes](#flat-file-routes) |
| `JsonApiRouteLoader` | `json_api` | Auto-generated JSON:API CRUD routes — see [JSON:API routes](#jsonapi-routes) |
| `RedirectRouteLoader` | `redirect` | Redirects declared through `RedirectConfigurator` — see [Redirects](#redirects) |

One subsection per loader follows, in the order of the table.

### Attribute routes

`#[Route]` attributes on controller classes are the default and are covered at the top of this guide, from [Declaring a route](#declaring-a-route) through [Protecting routes with `#[IsGranted]`](#protecting-routes-with-isgranted). Symfony's `AttributeDirectoryLoader` scans the imported directory and hands each class to AppKit's `AttributeClassLoader`, which sets the `_controller` default and folds the `#[IsGranted]` attributes into the route.

### Array routes

For routes you would rather read in one file than find across controller attributes — a small site, a legacy URL map, routes that point at controllers you do not own — declare them as a PHP array and import it with the `array` type:

```php
// config/routes/site.php
use App\Controller\PageController;

return [
    'home' => [
        'pattern' => '/',
        'controller' => [PageController::class, 'home'],
    ],
    'page' => [
        'pattern' => '/{slug}',
        'controller' => [PageController::class, 'show'],
        'requirements' => ['slug' => '[a-z0-9-]+'],
    ],
    'contact' => [
        'pattern' => '/contact',
        'methods' => ['GET', 'POST'],
        'controller' => [PageController::class, 'contact'],
    ],
];
```

```php
// config/routes.php
$routes->import('routes/site.php', 'array');
```

Each entry becomes one Symfony `Route`:

| Key | Required | Meaning |
|-----|----------|---------|
| `pattern` | yes | The path, with `{placeholders}` as in `#[Route]`. |
| `controller` | yes | Becomes the route's `_controller` default — `[Class::class, 'method']`, resolved like any attribute route. Not a closure: the compiled route collection is cached in production, and a closure cannot be serialized. |
| `methods` | no | HTTP verbs; defaults to `['GET']`, unlike `#[Route]`, which matches every method when omitted. |
| `requirements` | no | Placeholder patterns, `['slug' => '[a-z0-9-]+']`. |

The array key is the route name, used by `generate()` and `#[IsGranted]` alike; an entry without a string key is named after its pattern. Other keys — defaults, options, host, schemes — are not read; a route that needs them belongs in `#[Route]` or in `config/routes.php` through `$routes->add()`. The file is located through the loader's `FileLocator`, so the path is relative to your config directory, and it is `include`d, so it may compute its entries.

`ArrayRouteLoader` is shipped, not pre-registered: the skeleton's `AppFactory` registers only the attribute and PHP-file loaders, so add `new ArrayRouteLoader($locator)` to its `LoaderResolver` first (see above).

### Flat-file routes

`FlatFileRouteLoader` maps a content directory to URLs: each folder becomes a
route, nested folders nest, and the folder named `home` (configurable) serves
`/`. A numeric ordering prefix on a folder name (`1_about`, `2_blog`) sets the
sort order and is stripped from the slug.

**Slug collisions.** Because the prefix is stripped, `1_about` and `2_about`
both become `/about` and the same route name. The later folder wins without
warning. Keep the part after the prefix unique.

### JSON:API routes

`JsonApiRouteLoader` generates the CRUD and relationship routes for the resources declared in your JSON:API config; the resource model itself is documented in [modufolio/json-api](https://github.com/modufolio/json-api). What the loader adds on top is authorization.

Roles declared on a JSON:API resource are written to its generated routes as
`_is_granted_roles` — the same default `#[IsGranted]` produces — so the kernel
enforces them (with the role hierarchy) before the controller runs. Two shapes
are accepted:

```php
// One gate for every route of the entity — any listed role grants access.
$api->resource(Article::class)->roles(['ROLE_API_USER']);

// Split by operation kind. The fluent roles() accepts this shape since
// modufolio/json-api 0.7; on 0.6 declare it via setResourceConfig() instead.
$api->resource(Article::class)->roles([
    'read'  => ['ROLE_USER'],   // index/show/related (GET|HEAD)
    'write' => ['ROLE_ADMIN'],  // create/update/delete
]);
```

Generated write endpoints are never silently ungated: an entity that exposes
`create`/`update`/`delete` routes but declares no write roles gets
`IS_AUTHENTICATED` stamped on the write methods. To deliberately leave a side
open, declare it *present but empty* — `'read' => []` means public reads, and
`'roles' => ['read' => [], 'write' => []]` is the explicit opt-in for fully
public writes.
### Redirects

Redirects have their own loader. Declare them in a file that returns a closure over `RedirectConfigurator`, and import it with the `redirect` type:

```php
// config/redirects.php
use Modufolio\Appkit\Routing\RedirectConfigurator;

return function (RedirectConfigurator $redirects): void {
    $redirects
        // Literal target — for external URLs and paths outside the app.
        ->redirect('/home', '/', 301)
        // Named route — the URL is generated when the redirect is served,
        // so renames propagate and an unknown name throws RouteNotFoundException.
        ->redirectToRoute('/old-blog', 'blog.index', [], 302);
};
```

```php
// config/routes.php
$routes->import('redirects.php', 'redirect');
```

The file is looked up through the `FileLocator` the loader was built with (your config directory). Both methods take the status code last and default to `301`; only `301`, `302`, `303`, `307` and `308` are accepted, anything else is an `InvalidArgumentException` at configure time. Each entry becomes a route named `redirect_<hash>` (hash of source and target, so names are stable across loads) whose `_controller` is `RedirectController::redirect`, which answers with the `Location` header and a small HTML body. A source without a leading slash is normalised to one. Loops between literal-path redirects (`/a -> /b -> /a`) are refused at load time with the full chain in the message; chains that do not cycle load fine.

Keep literal targets static. The moment request data reaches `redirect()`, the route is an open redirect.

`RedirectRouteLoader` is shipped, not pre-registered: it has to be in the `LoaderResolver` your application builds (see above).
