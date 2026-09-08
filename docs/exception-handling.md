# Exception handling

Every thrown exception becomes an HTTP response through one place: `ExceptionHandler`. It owns a registry of per-class handlers that compute a `status / title / detail` array, a registry of formatters keyed by MIME type, content negotiation via `willdurand/negotiation`, and a logging policy that separates 5xx from 4xx. Your `App::handle()` wraps the request pipeline in a single `try / catch (\Throwable)` and delegates everything to `$exceptionHandler->handle($e, $request)` — there is no other error path.

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    try {
        $response = $this->handleAuthentication($request);
    } catch (\Throwable $e) {
        $response = $this->exceptionHandler()->handle($e, $request);
    }

    return $this->prepareResponse()->prepare($request, $response);
}
```

The handler is constructed with an `Environment` and a PSR-3 logger. Both are optional: without them it reads `APP_ENV` from the environment and discards log calls into a `NullLogger`.

## Error-output hardening

PHP warnings and notices are not exceptions — left alone, they bypass `ExceptionHandler` entirely. Worse, with `display_errors` on, the first warning PHP prints becomes the first output byte, which commits the response headers with a **200** status; the real response (often a 500) then loses its status line and every header to "headers already sent". `Kernel::boot()` closes both holes via `Modufolio\Appkit\Core\Debug`:

| Environment | Behaviour |
|-------------|-----------|
| `dev` | `Debug::enable()` — warnings and notices are thrown as `\ErrorException`, so they route through the `try / catch` above and become a clean 500 with the warning's message as detail. `display_errors` is forced off for web SAPIs. Deprecations and `@`-silenced errors are left to PHP's logger. |
| `prod` | `Debug::harden()` — `display_errors` is forced off for web SAPIs, as defence in depth against a dev `php.ini` serving a prod app. Warnings are logged by PHP, never printed into the response. |
| `test` | Untouched — PHPUnit keeps its own error handling. |

CLI SAPIs (including RoadRunner workers) never have `display_errors` touched — stderr is the right place for errors there. The emitter is defensive on top of this: when headers have already been sent it skips the header phase entirely instead of cascading "Cannot modify header information" warnings.

## How `handle()` works

1. **Match.** Of every registered class or interface the exception is an `instanceof`, the most specific wins: the exception's own class, then its parent, and so on up the chain, with an interface counted at the level of the class that introduces it. Two matches at the same distance keep registration order. If nothing matches, `defaultData()` produces a generic 500.
2. **Compute.** The matched callable returns an array — minimally `status`, `title`, `detail`; optionally `errors` for JSON:API multi-error payloads.
3. **Negotiate and format.** Resolve the `Accept` header against the registered MIME types, then dispatch the array to the matching formatter. An Inertia request skips negotiation and always gets JSON:API — see [Content negotiation](#content-negotiation).

One exception never reaches these steps: `InsecureChannelException`, thrown when a firewall requires HTTPS and the request arrived over HTTP, is answered with a `301` redirect to the `https` URL before any matching, formatting or logging — it is a redirect, not an error payload.

If a registered handler itself throws, `ExceptionHandler` catches the secondary exception, logs both, and falls back to `defaultData($handlerException)` — see [Handler-of-handler fallback](#handler-of-handler-fallback).

## Built-in exception handlers

Registered by `registerDefaultExceptions()`. The three broad entries — `\InvalidArgumentException`, `\LogicException`, `\RuntimeException` — are catch-alls: an application exception extending one of them reaches its own handler when one is registered, and falls back to the catch-all otherwise.

| Exception | Status | Title | Notes |
|-----------|--------|-------|-------|
| `\InvalidArgumentException` | 400 | Bad Request | Echoes `$e->getMessage()`. |
| `\JsonException` | 422 | Invalid JSON payload | Thrown by the body decoder. |
| `PayloadTooLargeException` | 413 | Payload Too Large | Raised by upload guards. |
| `UntrustedHostException` | 400 | Bad Request | Detail is **always** the literal `The request host is not allowed.` — the rejected host is attacker input and goes to the log only. Loggable. |
| `UnresolvableServiceException` | 500 | Service configuration error | A service factory whose constructor call is missing an argument. Detail hidden in prod. Loggable. |
| `\LogicException` | 500 | Logic error | Detail hidden in prod. Loggable. |
| `ResourceNotFoundException` | 404 | Resource not found | Raised by the router. |
| `MethodNotAllowedException` | 405 | Method not allowed | The Symfony message already lists the allowed methods. |
| `ValidationFailedException` | 422 | — | Produces a JSON:API `errors` array, one entry per violation with a `source.pointer`. |
| `AuthenticationException` | 401 | Authentication failed | Detail is **always** the literal `Authentication required.` — never `$e->getMessage()`. See below. |
| `AccessDeniedException` | 403 | Access denied | The user **is** authenticated but lacks the required roles or fails an access rule (`#[IsGranted]`, `accessControl()`, IP restriction). |
| `\RuntimeException` | 500 | Runtime error | Detail hidden in prod. Loggable. |

### Why 401 is generic

`AuthenticationException` deliberately suppresses `$e->getMessage()`. The message is useful in the logger — `"JWT signature invalid"`, `"Account is locked"`, `"Token expired"` — but those are reconnaissance signals for an attacker probing the boundary between *no account*, *wrong password*, *locked account*, and *expired token*. The client receives a flat `401 Authentication required.` and the detail goes to the log only. This is the same reasoning behind the timing-safe, enumeration-resistant login failures described in [Authenticators](authenticators.md).

Authenticators that need a richer response — for example a `WWW-Authenticate: Bearer realm=…` challenge — should return one from their own `unauthorizedResponse()` before the exception reaches the handler.

### Production vs development detail

`UnresolvableServiceException`, `\LogicException`, `\RuntimeException`, and the unmatched-exception fallback call `shouldShowDetails()`, which returns true only when the environment reports `isDev()` or `isTest()`. In `prod` the detail collapses to `An unexpected error occurred. Please try again later.` The raw message still reaches the logger.

## Two-factor exceptions

An exception implementing `Modufolio\Appkit\Security\TwoFactor\TwoFactorExceptionInterface` — including the framework's own `Modufolio\Appkit\Security\TwoFactor\TwoFactorException`, raised when a TOTP lockout is in effect — maps to:

```json
{"status": 422, "title": "Two-Factor Authentication Error", "detail": "<message>"}
```

The message reaches the client **verbatim, in production too**. That is the point: "try again in 40 seconds" is the one thing the person at the keyboard needs, and the generic 500 that other runtime errors collapse into would strip it.

Implementing the interface is how an exception opts into that trust — it is a promise that the message carries no internal state, no identifiers, nothing an anonymous caller should not read. Exceptions that cannot make that promise simply do not implement it, and fall through to the `RuntimeException` / `LogicException` handlers, which hide their detail outside dev.

> Earlier versions matched on the class *name* instead, mapping anything ending in `TwoFactorException` to this response. That extended the trust to classes that never asked for it — an app exception named `BillingTwoFactorException` had its raw message published — while the framework's own `TwoFactorException` never reached the branch at all, because it extends `RuntimeException` and the catch-all matched first. Both are fixed; see the changelog.

The interface is the closer match, so it beats the `RuntimeException` catch-all `TwoFactorException` would otherwise reach. To change the mapping, register your own handler under the **interface** id, which replaces the entry in place:

```php
$handler->registerException(
    TwoFactorExceptionInterface::class,
    fn (\Throwable $e): array => ['status' => 429, 'title' => 'Slow down', 'detail' => $e->getMessage()],
);
```

## Registering custom handlers

Register them from `configureExceptionHandler()` on your `App`. The kernel calls it once, lazily, the first time `exceptionHandler()` is used, and keeps whatever it returns — the handler itself after a few registrations, or a decorator around it:

```php
use Modufolio\Appkit\Exception\ExceptionHandlerInterface;

protected function configureExceptionHandler(ExceptionHandlerInterface $handler): ExceptionHandlerInterface
{
    $handler->registerException(MaintenanceModeException::class, /* … */);
    $handler->registerFormatter('text/html', /* … */);

    return $handler;
}
```

```php
$handler->registerException(
    \App\Exception\MaintenanceModeException::class,
    fn (\App\Exception\MaintenanceModeException $e, ServerRequestInterface $request): array => [
        'status' => 503,
        'title'  => 'Service Unavailable',
        'detail' => 'The service is temporarily offline for maintenance.',
    ],
    loggable: true,
);
```

Signature:

```php
public function registerException(
    string $exceptionClass,
    callable $handler,           // fn(\Throwable, ServerRequestInterface): array
    bool $loggable = false,
): void;
```

The callable receives the original throwable and the current PSR-7 request, which is useful when the response varies by route or correlation header. The return array is `['status' => int, 'title' => string, 'detail' => string]`, optionally with `errors` for JSON:API multi-error payloads or any extra keys the formatters consume.

Later registrations for the same class overwrite earlier ones. Registration order otherwise does not matter: the handler closest to the exception's own class wins, so an application exception extending `\RuntimeException` reaches its own handler even though the `\RuntimeException` catch-all was registered first.

> Earlier versions matched in insertion order, first match wins. Because the defaults are registered in the constructor, the three broad catch-alls shadowed every application exception extending them — the `PaymentDeclinedException` example below silently produced the `\RuntimeException` 500. See the changelog.

### Logging policy

`$loggable` is a per-class opt-in. When true, the handler derives the PSR-3 level from the response status:

| Status | Level |
|--------|-------|
| `>= 500` | `error` |
| `>= 400` | `warning` |
| anything else | `info` |

Two cases sit outside this table:

- **Unmatched 5xx** — any exception that falls through the registry to the 500 fallback is logged at `error` regardless of `$loggable`. Unknown server errors are never silent.
- **Handler-of-handler failure** — the secondary exception is logged at `error` with both class names in context (see below).

When `$loggable` is false (the default), the exception produces a response but no log line. That is appropriate for routine client errors — `InvalidArgumentException`, `ValidationFailedException`, `ResourceNotFoundException` — where logging every 404 only adds noise.

## Registering custom formatters

```php
$handler->registerFormatter('text/html', function (array $data): ResponseInterface {
    return new Response(
        $data['status'] ?? 500,
        ['Content-Type' => 'text/html; charset=utf-8'],
        '<h1>' . htmlspecialchars($data['title'] ?? 'Error') . '</h1>'
        . '<p>' . htmlspecialchars($data['detail'] ?? '') . '</p>'
    );
});
```

Four formatters are registered by default:

- `application/vnd.api+json` — JSON:API envelope `{"jsonapi": {"version": "1.0"}, "errors": [...]}`. Used as the **fallback** when negotiation produces nothing, and when the negotiated MIME type has no formatter.
- `application/json` — flat JSON of the data array.
- `text/plain` — `"<title>: <detail>"`.
- `text/html` — a self-contained error page: status, title and detail in one card, no assets, no links. It is what a browser's default `Accept` header negotiates to, so a hard page load that errors — an address-bar visit, an old bookmark — reads as a page rather than a JSON blob. Registering your own `text/html` formatter replaces it; see [HTML error pages](#html-error-pages).

A formatter is a `callable(array): ResponseInterface`. It owns the response entirely — headers, body encoding, status. The handler does no post-processing; the returned response is passed straight from `App::handle()` to `PrepareResponse`.

## Content negotiation

The handler keeps a `Negotiator` (`willdurand/negotiation`). On each request it reads the `Accept` header and picks the best match from the registered MIME types:

- **`X-Inertia` header present** → `application/vnd.api+json`, whatever `Accept` says. The Inertia client sends `Accept: text/html, application/xhtml+xml` on its XHRs too, so that a framework can route them through ordinary negotiation; taken literally it would hand every Inertia error the HTML page meant for a hard load. An Inertia visit is a JSON exchange, and its errors keep the JSON:API body a client-side handler can read.
- **No `Accept` header** → `application/vnd.api+json`.
- **`Accept: */*`** (curl, most HTTP clients) → `application/vnd.api+json`: every registered type matches equally, and the first registered wins.
- **`Accept` header present** → the negotiator picks the highest-quality formatter. A browser's default header lists `text/html` first and gets the HTML page. If none of the registered types match, fall back to `application/vnd.api+json`.
- **Negotiated type with no formatter** → same fallback (defensive — should not happen, since the priorities come from the formatter map).

The default is JSON:API because the framework targets API-first applications. The same data array drives every formatter, so registering or replacing one never touches the handlers.

## Handler-of-handler fallback

A bug in a custom handler — a typo, a missing dependency, a circular call into a service that itself throws — would normally cascade and crash the request mid-response. `handle()` wraps the match loop in a `try / catch (\Throwable)`:

```php
try {
    $matchedClass = $this->match($e);

    if (null !== $matchedClass) {
        $data = $this->handlers[$matchedClass]($e, $request);
    }
    // …
} catch (\Throwable $handlerException) {
    $this->logger->error('Exception handler failed', [
        'handler_exception'        => $handlerException->getMessage(),
        'original_exception'       => $e->getMessage(),
        'original_exception_class' => $e::class,
    ]);
    $data = $this->defaultData($handlerException);
}
```

The original exception, the handler exception, and the offending class are all logged. The client sees a generic 500. This invariant — *exception handling cannot fail visibly* — is why `App::handle()` needs no second `try / catch` around `$exceptionHandler->handle()`.

## Custom exception classes in `src/Exception/`

- `NotFoundException` — implements PSR-11's `NotFoundExceptionInterface`. Thrown by the container when a service or factory cannot be resolved. **Not** a router 404 (that is Symfony's `ResourceNotFoundException`). No default handler is registered for it; uncaught, it falls through to the 500 fallback. Register one to surface container misses differently in dev.
- `UnresolvableServiceException` — extends `\LogicException`, implements PSR-11's `ContainerExceptionInterface`. Thrown by the container when a service factory raises `\ArgumentCountError`: the constructor it calls has a required argument the factory did not pass — a wiring bug in `services.php` or a console runner, never client input. Carries the service id as `$serviceId` and the original error as `getPrevious()`. Mapped to 500 with the wiring named in dev and hidden in prod.
- `PayloadTooLargeException` — extends `\RuntimeException`. Thrown by upload and body-size guards. Registered to produce 413.
- `RuntimeCommandException` — extends `\RuntimeException` and implements Symfony Console's `ExceptionInterface`. The CLI-side equivalent for command failures that should be reported through the console error formatter rather than HTTP.

## HTML error pages

The built-in `text/html` formatter is deliberately plain: one document with no links, because the handler cannot know where the application's home is. For server-rendered apps, register a formatter that renders an error template via `Template`. The data array is associative state, so it passes straight into the template:

```php
use Modufolio\Appkit\Template\Template;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;

$handler->registerFormatter('text/html', function (array $data) use ($baseDir): ResponseInterface {
    $status = $data['status'] ?? 500;

    $template = new Template(
        name: 'errors/' . $status,
        templatePaths: [$baseDir . '/site/templates'],
        layoutPaths:   [$baseDir . '/site/layouts'],
        data: [
            'status' => $status,
            'title'  => $data['title']  ?? 'Error',
            'detail' => $data['detail'] ?? '',
        ],
    );

    try {
        $body = $template->render();
    } catch (\RuntimeException) {
        // Template missing — fall back to a generic page.
        $body = '<h1>' . htmlspecialchars((string) $status) . ' '
              . htmlspecialchars((string) ($data['title'] ?? 'Error')) . '</h1>';
    }

    return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
});
```

Two notes on the lookup. First, the missing-template branch matters: `Template::render()` throws `\RuntimeException` when the file is absent, and that exception is itself a registered handler — without the inner `try`, a missing `errors/404.php` would loop back into the handler and trigger the handler-of-handler fallback (which works, but logs noise). Second, build the `Template` fresh inside the formatter; do not capture one from the outer scope. Templates carry per-render state, and sharing them across requests in a long-running worker leaks sections — see [Templates](templates.md).

## End-to-end example: `PaymentDeclinedException`

A domain exception that maps to HTTP 402 with both JSON and HTML representations.

```php
namespace App\Billing\Exception;

final class PaymentDeclinedException extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,    // e.g. 'insufficient_funds'
        public readonly ?string $providerRef,
        string $message = 'Payment was declined.',
    ) {
        parent::__construct($message);
    }
}
```

Wire the handler and the HTML formatter in `configureExceptionHandler()` on the `App`:

```php
use App\Billing\Exception\PaymentDeclinedException;
use Modufolio\Appkit\Template\Template;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$handler = $app->exceptionHandler();

// 1. Per-class handler: throwable -> data array.
$handler->registerException(
    PaymentDeclinedException::class,
    fn (PaymentDeclinedException $e, ServerRequestInterface $request): array => [
        'status'      => 402,
        'title'       => 'Payment Required',
        'detail'      => $e->getMessage(),
        'reason_code' => $e->reasonCode,
        'provider'    => $e->providerRef,
    ],
    loggable: true,   // -> warning (status 4xx)
);

// 2. HTML formatter: data array -> ResponseInterface.
$handler->registerFormatter('text/html', function (array $data) use ($app): ResponseInterface {
    $template = new Template(
        name: 'errors/' . ($data['status'] ?? 500),
        templatePaths: [$app->baseDir . '/site/templates'],
        layoutPaths:   [$app->baseDir . '/site/layouts'],
        data: $data,
    );

    return new Response(
        $data['status'] ?? 500,
        ['Content-Type' => 'text/html; charset=utf-8'],
        $template->render(),
    );
});
```

A browser request to a route that throws `PaymentDeclinedException` now negotiates to `text/html` and renders `site/templates/errors/402.php` with `$status`, `$title`, `$detail`, `$reason_code`, and `$provider` in scope. An API client sending `Accept: application/vnd.api+json` gets:

```json
{
  "jsonapi": {"version": "1.0"},
  "errors": [
    {"status": "402", "title": "Payment Required", "detail": "Payment was declined."}
  ]
}
```

The JSON:API formatter strips the extra `reason_code` / `provider` keys — it only consumes `status`, `title`, `detail`, and `errors`. To carry those fields on the wire, register a JSON:API formatter that copies them into each error's `meta` block, or surface them through a custom MIME type.

The log line lands at `warning` because the status is 402 and the handler was registered with `loggable: true`. Raising the status into the 500 range pushes it to `error`; leaving `loggable` off silences it entirely.
