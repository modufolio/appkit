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

## How `handle()` works

1. **Match.** Walk the handler registry in insertion order; the first class with `$e instanceof $class` wins. A check for class names ending in `TwoFactorException` runs after the explicit registry — that hook lets the handler stay decoupled from the optional 2FA module (see [Two-factor exceptions](#two-factor-exceptions)). If nothing matches, `defaultData()` produces a generic 500.
2. **Compute.** The matched callable returns an array — minimally `status`, `title`, `detail`; optionally `errors` for JSON:API multi-error payloads.
3. **Negotiate and format.** Resolve the `Accept` header against the registered MIME types, then dispatch the array to the matching formatter.

If a registered handler itself throws, `ExceptionHandler` catches the secondary exception, logs both, and falls back to `defaultData($handlerException)` — see [Handler-of-handler fallback](#handler-of-handler-fallback).

## Built-in exception handlers

Registered by `registerDefaultExceptions()`. The list follows registration order — the first match wins, so register subclasses before their parents when they need different behaviour.

| Exception | Status | Title | Notes |
|-----------|--------|-------|-------|
| `\InvalidArgumentException` | 400 | Bad Request | Echoes `$e->getMessage()`. |
| `\JsonException` | 422 | Invalid JSON payload | Thrown by the body decoder. |
| `PayloadTooLargeException` | 413 | Payload Too Large | Raised by upload guards. |
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

`\LogicException`, `\RuntimeException`, and the unmatched-exception fallback call `shouldShowDetails()`, which returns true only when the environment reports `isDev()` or `isTest()`. In `prod` the detail collapses to `An unexpected error occurred. Please try again later.` The raw message still reaches the logger.

## Two-factor exceptions

Any exception whose class name ends in `TwoFactorException` — including the framework's own `Modufolio\Appkit\Security\TwoFactor\TwoFactorException`, raised when a TOTP lockout is in effect — maps to:

```json
{"status": 422, "title": "Two-Factor Authentication Error", "detail": "<message>"}
```

This match runs *after* the explicit registry, so a more specific handler registered for the concrete class still takes precedence. The name-based check means the core handler never has to import the optional 2FA module.

## Registering custom handlers

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

Later registrations for the same class overwrite earlier ones. The match loop iterates in insertion order, so registering a leaf exception **before** its parent guarantees the leaf handler wins.

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

Three formatters are registered by default:

- `application/vnd.api+json` — JSON:API envelope `{"jsonapi": {"version": "1.0"}, "errors": [...]}`. Used as the **fallback** when negotiation produces nothing, and when the negotiated MIME type has no formatter.
- `application/json` — flat JSON of the data array.
- `text/plain` — `"<title>: <detail>"`.

A formatter is a `callable(array): ResponseInterface`. It owns the response entirely — headers, body encoding, status. The handler does no post-processing; the returned response is passed straight from `App::handle()` to `PrepareResponse`.

## Content negotiation

The handler keeps a `Negotiator` (`willdurand/negotiation`). On each request it reads the `Accept` header and picks the best match from the registered MIME types:

- **No `Accept` header** → `application/vnd.api+json`.
- **`Accept` header present** → the negotiator picks the highest-quality formatter. If none of the registered types match, fall back to `application/vnd.api+json`.
- **Negotiated type with no formatter** → same fallback (defensive — should not happen, since the priorities come from the formatter map).

The default is JSON:API because the framework targets API-first applications. Register an HTML formatter and clients sending `Accept: text/html` receive HTML instead, with no change to the handlers — the same data array drives both.

## Handler-of-handler fallback

A bug in a custom handler — a typo, a missing dependency, a circular call into a service that itself throws — would normally cascade and crash the request mid-response. `handle()` wraps the match loop in a `try / catch (\Throwable)`:

```php
try {
    foreach ($this->handlers as $class => $handler) {
        if ($e instanceof $class) {
            $data = $handler($e, $request);
            $matchedClass = $class;
            break;
        }
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
- `PayloadTooLargeException` — extends `\RuntimeException`. Thrown by upload and body-size guards. Registered to produce 413.
- `RuntimeCommandException` — extends `\RuntimeException` and implements Symfony Console's `ExceptionInterface`. The CLI-side equivalent for command failures that should be reported through the console error formatter rather than HTTP.

## HTML error pages

For server-rendered apps, register a formatter that renders an error template via `Template`. The data array is associative state, so it passes straight into the template:

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

Wire the handler and the HTML formatter where the kernel is assembled — typically inside a `bootExceptionHandler()` hook on the `App`:

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
        templatePaths: [$app->baseDir() . '/site/templates'],
        layoutPaths:   [$app->baseDir() . '/site/layouts'],
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
