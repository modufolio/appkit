# Controllers

Controllers are PHP classes in `src/Controller/` that handle HTTP requests. Each public method with a `#[Route]` attribute becomes a route handler.

## Creating a controller

Extend `AbstractController`. No registration is required — the route scanner picks up any class in `src/Controller/` automatically.

```php
// src/Controller/AboutController.php
namespace App\Controller;

use Modufolio\Appkit\Core\AbstractController;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Attribute\Route;

class AboutController extends AbstractController
{
    #[Route(path: '/about', name: 'about', methods: ['GET'])]
    public function index(): ResponseInterface
    {
        return new Response(body: '<h1>About</h1>');
    }
}
```

## What `AbstractController` provides

`AbstractController` receives framework services via `setSubscribedServices()`, called automatically by the Kernel on instantiation. These protected properties are available in every controller method:

| Property | Type | Description |
|----------|------|-------------|
| `$entityManager` | `EntityManagerInterface` | Doctrine entity manager |
| `$flashBag` | `FlashBagInterface` | Session flash messages |
| `$tokenStorage` | `TokenStorageInterface` | Current authentication token |
| `$urlGenerator` | `UrlGeneratorInterface` | Route URL generator |
| `$userProvider` | `UserProviderInterface` | Load users by identifier |
| `$validator` | `ValidatorInterface` | Symfony validator |

One method is available for getting the current user:

```php
$user = $this->getUser(); // returns UserInterface|null
```

## Rendering a template

Construct a `Template`, pass it data, and wrap the result in a `Response`.

```php
use Modufolio\Appkit\Template\Template;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ServerRequestInterface;

#[Route(path: '/', name: 'home', methods: ['GET'])]
public function index(ServerRequestInterface $request): ResponseInterface
{
    $template = new Template(
        name: 'home',
        templatePaths: [dirname(__DIR__, 2) . '/resources/views'],
        layoutPaths:   [dirname(__DIR__, 2) . '/resources/views/layouts'],
        request:       $request,
    );

    return new Response(body: $template->render([
        'title' => 'Home',
        'user'  => $this->getUser(),
    ]));
}
```

See [Templates](templates.md) for the full template API.

## Accessing the request

Typehint `ServerRequestInterface` in your method signature. It is injected automatically.

```php
#[Route(path: '/search', name: 'search', methods: ['GET'])]
public function search(ServerRequestInterface $request): ResponseInterface
{
    $query = $request->getQueryParams()['q'] ?? '';
    // ...
}
```

## Returning responses

`Modufolio\Psr7\Http\Response` provides static factory methods for the most common response types. Every controller method must return a `ResponseInterface`.

```php
use Modufolio\Psr7\Http\Response;

// Redirect
return Response::redirect($this->urlGenerator->generate('home'));
return Response::redirect('/login', 302);

// JSON — json(string|array $body, ?int $code = null, ?bool $pretty = null, array $headers = [])
return Response::json(['status' => 'ok', 'id' => $entity->getId()]);
return Response::json($errors, 422);
return Response::json($data, 200, true, ['X-Total' => '42']); // pretty-print + headers

// HTML
return Response::html('<h1>Hello</h1>');
return Response::html($template->render($data), 200);

// Empty (204 No Content)
return Response::empty();

// Error shortcuts
return Response::unauthorized('Login required');
return Response::unavailable('Down for maintenance');
return Response::tooManyRequests('Slow down');
```

All redirect status codes are validated. Allowed values: 301, 302, 303, 307, 308.

For full control, construct a response directly:

```php
return new Response(
    status:  200,
    headers: ['Content-Type' => 'text/csv'],
    body:    $csvContent,
);
```

## Parameter attributes

AppKit's parameter resolver reads PHP attributes on method parameters and injects values automatically. The design is inspired by [php-di/invoker](https://github.com/PHP-DI/Invoker) and Symfony's argument resolver system.

### `#[CurrentUser]`

Injects the authenticated user directly into the method. Returns `null` if no user is authenticated.

```php
use Modufolio\Appkit\Attributes\CurrentUser;

public function dashboard(#[CurrentUser] UserInterface $user): ResponseInterface
```

### `#[MapEntity]`

Loads a Doctrine entity from the database using route parameters as criteria. Throws a 404 if the entity is not found and the parameter is non-nullable. Returns `null` if the parameter is nullable (`?Post`).

```php
use Modufolio\Appkit\Attributes\MapEntity;

#[Route(path: '/posts/{id}', name: 'post.show', methods: ['GET'])]
public function show(#[MapEntity] Post $post): ResponseInterface
```

Map a route parameter to an entity field with `mapping` (route param name => field name):

```php
#[Route(path: '/posts/{slug}', name: 'post.show', methods: ['GET'])]
public function show(#[MapEntity(mapping: ['slug' => 'slug'])] Post $post): ResponseInterface
```

Add fixed criteria, exclude keys, or strip nulls:

```php
#[MapEntity(criteria: ['status' => 'published'], stripNull: true)] Post $post
```

### `#[MapRequestPayload]`

Deserialises and validates the request body into a typed object.

```php
use Modufolio\Appkit\Attributes\MapRequestPayload;

#[Route(path: '/api/posts', name: 'api.posts.create', methods: ['POST'])]
public function create(#[MapRequestPayload] CreatePostDto $dto): ResponseInterface
```

By default, validation failures throw a `422` exception. Set `throwOnError: false` to receive a `ValidationResult` instead:

```php
public function create(
    #[MapRequestPayload(throwOnError: false)] CreatePostDto $dto,
    ValidationResult $result
): ResponseInterface {
    if ($result->hasErrors()) {
        return Response::json(['errors' => $result->errors()], 422);
    }
    // ...
}
```

See [Forms](forms.md) for DTO definition and validation constraints.

### `#[MapQueryString]`

Same as `#[MapRequestPayload]` but reads from the URL query string.

```php
public function search(#[MapQueryString] SearchQuery $query): ResponseInterface
```

### `#[MapQueryParameter]`

Binds a single query parameter to a primitive argument (`int`, `float`, `bool`, `string`, `array`, a `BackedEnum`, or a `Uuid`/`Ulid`), coercing the value with `filter_var()`. The argument name is used as the parameter name unless you pass `name`.

```php
#[Route(path: '/posts', name: 'posts.index', methods: ['GET'])]
public function index(
    #[MapQueryParameter(name: 'q')] ?string $search = null,
    #[MapQueryParameter] int $page = 1,
    #[MapQueryParameter] SortDirection $sort = SortDirection::Desc,
): ResponseInterface
```

A missing parameter falls back to the argument default, or `null` if the argument is nullable; otherwise a `400` is thrown. An invalid value throws a `400` unless `FILTER_NULL_ON_FAILURE` is set in `flags`. Use `filter`, `flags`, and `options` for finer control (e.g. `#[MapQueryParameter(options: ['min_range' => 1])]`).

Use this for individual scalars; reach for `#[MapQueryString]` or `#[MapFilter]` when you want the whole query string mapped to an object.

### `#[MapFilter]`

Builds a filter object from query parameters. Your filter class needs a `fromArray()` static method.

```php
public function list(#[MapFilter] PostFilter $filter): ResponseInterface
```

### `#[DataGrid]`

Injects a pre-built, paginated, sortable, filterable data grid.

```php
use Modufolio\Appkit\Attributes\DataGrid;

public function index(#[DataGrid(schema: PostGrid::class, source: Post::class)] $grid): ResponseInterface
```

## Constructor dependencies

If your controller needs services beyond what `AbstractController` provides, declare them as constructor arguments and wire them in `config/controllers.php`.

```php
class PostController extends AbstractController
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}
}
```

```php
// config/controllers.php
return [
    PostController::class => [
        MailerInterface::class,
        CsrfTokenManagerInterface::class,
    ],
];
```

The order of the array must match the constructor parameter order. See [Dependency injection](dependency-injection.md).
