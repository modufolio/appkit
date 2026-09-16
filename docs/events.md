# Events

AppKit's kernel tells the application what just happened: a user logged in,
a login failed, an upload was stored, a request was handled. It says so
through a PSR-14 event dispatcher, and it says so *after* the fact. Nothing a
listener does changes what the kernel decided — that is what makes these
notifications rather than hooks, and it is why they exist at all. A "new
sign-in" mail, an audit trail, a virus scan queued for a stored file, an
error reporter: each is a listener on one event, and none of them belongs in
the kernel.

This is not an extension system. Authenticators, user checkers, CSRF
validators and package contracts are still declared as interfaces in
`config/services.php`, and the authentication flow still reads top to bottom
in `handleAuthentication()`. The dispatcher sits beside that flow, not in
front of it.

## Wiring a dispatcher

The kernel dispatches through whatever implements
`Psr\EventDispatcher\EventDispatcherInterface` in `config/services.php`. With
nothing declared it uses a `NullEventDispatcher`, and dispatching costs
nothing. Symfony's dispatcher is a dependency of AppKit, so the usual
declaration is:

```php
use Modufolio\Appkit\Event\Security\UserLoggedInEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

return function (ServiceConfigurator $services): void {
    $services->set(EventDispatcherInterface::class, function (App $app): EventDispatcherInterface {
        $dispatcher = new EventDispatcher();

        $dispatcher->addListener(UserLoggedInEvent::class, function (UserLoggedInEvent $event) use ($app): void {
            $app->get(NewSignInNotifier::class)->notify($event);
        });

        return $dispatcher;
    });
};
```

The kernel builds it once per worker and keeps it: listeners are
process-level wiring, like routes. A module can install one from `boot()`
with `setEventDispatcher()`, and controllers and services receive it by
type-hinting the interface. Whether you use Symfony's dispatcher, another
PSR-14 implementation, or a class of your own is not the kernel's concern.

Symfony's `#[AsEventListener]` attribute and subscriber classes work as they
do anywhere: register them on the dispatcher in the same factory.

## The rules

Every event the framework dispatches follows these, and listeners can rely
on them:

- **Past tense, after commit.** `UserLoggedInEvent` is dispatched once the token
  is stored and the session saved; `TwoFactorEnabledEvent` once the row is
  flushed; `RequestHandledEvent` once the response is final. A listener that
  reads the session or the token storage sees the state the event reports.
- **Immutable, and carrying facts.** Events are `final readonly` classes of
  scalars: identifiers, names, paths, an IP. Never the user object, never a
  token, never a password or a secret. A listener that needs the user loads
  it through the provider.
- **A failing listener changes nothing.** The kernel dispatches through
  `Kernel::notify()`, which logs an exception from a listener and carries
  on: the login that just succeeded does not become a 500 because the mail
  server is down. `PrepareResponse` and the exception handler do the same.
  Application code that dispatches its own events calls the dispatcher
  directly and chooses its own policy.
- **Dispatch calls are inline.** Grep for `notify(` in the kernel traits
  and you have the complete list of moments; nothing is dispatched from
  somewhere you cannot read.
- **No kernel event from inside a listener.** `notify()` refuses a nested
  dispatch and logs an error naming both events. A listener on
  `UserLoggedInEvent` that called `logout()` would otherwise dispatch
  `UserLoggedOutEvent`, whose listener could log in again; the guard makes
  that cycle impossible rather than merely discouraged.

## The events

All under `Modufolio\Appkit\Event`.

### Security

| Event | When | Carries |
|-------|------|---------|
| `Security\UserLoggedInEvent` | An authenticator succeeded and the session carries the user; before the controller runs. A remember-me restore is a login too, with `viaRememberMe` set | `userIdentifier`, `firewallName`, `authenticator` (the configured name, `form_login`), `roles`, `viaRememberMe`, `clientIp` |
| `Security\LoginFailedEvent` | An authenticator that supported the request threw. Not for a stale remember-me cookie, not for a request nothing supported | `userIdentifier` (when the authenticator implements `AttemptedIdentifierInterface`; the form and Basic ones do), `firewallName`, `authenticator`, `reason` (the exception class), `clientIp` |
| `Security\UserLoggedOutEvent` | The kernel's logout ran for a signed-in user: on request, or because the account no longer checks out or the session idled out | `userIdentifier`, `firewallName`, `clientIp` |
| `Security\RememberMeCookieTheftDetectedEvent` | A persistent remember-me series was replayed with a stale value. Every series of the user is already revoked | `userIdentifier`, `firewallName`, `clientIp` |
| `Security\ImpersonationStartedEvent` | A switch-user request succeeded and the session now carries the target | `impersonatorIdentifier`, `targetIdentifier`, `firewallName`, `clientIp` |
| `Security\ImpersonationEndedEvent` | `_exit` returned the session to the impersonator | `impersonatorIdentifier`, `impersonatedIdentifier`, `firewallName`, `clientIp` |
| `Security\AccessDeniedEvent` | A path rule or `#[IsGranted]` refused an authenticated request; the 403 follows | `path`, `method`, `userIdentifier`, `firewallName`, `reason`, `clientIp` |
| `Security\RateLimitExceededEvent` | A firewall's login throttle or a route's `#[RateLimit]` refused a request; the 429 follows | `limiter`, `key` (`ip:…` or `user:…`), `path`, `method`, `userIdentifier`, `clientIp`, `retryAfterSeconds` |
| `Security\TwoFactorEnabledEvent` | `TotpService::enableTwoFactor()` confirmed a secret and flushed | `userIdentifier` |
| `Security\TwoFactorDisabledEvent` | `TotpService::disableTwoFactor()` removed a secret and flushed | `userIdentifier` |
| `Security\PasswordChangedEvent` | **Dispatched by your application** after it persists a new hash; the framework never changes a password itself and does not dispatch this for a rehash on login | `userIdentifier`, `origin`, `clientIp` |

`clientIp` is `REMOTE_ADDR` as the server reported it, or null — the same
value firewall selection and `ips` rules trust. A forwarded header is the
application's to interpret, in the listener.

`TotpService` dispatches when it is built with a dispatcher; pass
`$app->eventDispatcher()` as its `events` argument in your `App`.

### Everything else

| Event | When | Carries |
|-------|------|---------|
| `Http\RequestHandledEvent` | The response is final, after the profiler collected; for every request, error responses included | `request`, `response`, `durationMs` (from `REQUEST_TIME_FLOAT`, null when the SAPI has none) |
| `ExceptionCaughtEvent` | A throwable reached the exception handler, before it is mapped to a response; a 404 as much as a bug | `exception`, `request` |
| `Http\UploadStoredEvent` | `Upload::saveTo()` wrote the file | `path`, `filename`, `clientFilename`, `size`, `mimeType` (sniffed, not the client's claim) |

`Upload` dispatches when it is given the dispatcher:
`Upload::from($file, $app->eventDispatcher())`, or `->notifying($dispatcher)`
on an existing one.

## What is not an event

- **Doctrine's lifecycle.** Persisting, updating and removing entities have
  Doctrine's own events; the kernel does not mirror them.
- **A pre-request hook that can answer the request.** That is middleware.
  Your `App` implements `handle()` and can wrap a middleware stack around the
  kernel flow when a project needs one; see [Kernel](kernel.md).
- **Console and jobs mode.** Those callers own their flow and dispatch what
  they choose.

## What this is not for

The dispatcher is a place to be told, not a place to wire the application.
Three uses look natural and are the wrong tool:

- **Module-to-module messaging.** `OrderService` should call
  `InvoiceService`, declared in `config/services.php`, where the dependency
  can be read, typed and stepped through. An `OrderPlaced` event with an
  invoicing listener hides that call, and the next listener hides the next
  one, until the order of operations lives in registration order and a
  change in one listener breaks a module it never named. That is the
  "circular hell" an event bus invites, and the reason AppKit went without
  one for so long.
- **Listeners that call the kernel's security methods.** Logging a user out,
  switching users, or invalidating a session from a listener turns a
  notification into control flow. The re-entrancy guard refuses the kernel
  event such a call would dispatch, but the call itself is still the wrong
  shape: decide before the moment, in an authenticator or a user checker,
  not after it in a listener.
- **Listeners that dispatch kernel events.** The framework's events are
  dispatched by the framework, from the code paths that own them. An
  application never constructs one.

A listener does one of three things: records, notifies someone, or queues
work for later. If it needs to do more than that, it is a service, and the
code that has the fact should call it.

## An audit trail in one listener

```php
final class SecurityAuditListener
{
    public function __construct(private readonly LoggerInterface $audit)
    {
    }

    public function __invoke(object $event): void
    {
        $this->audit->info($event::class, get_object_vars($event));
    }
}

foreach ([
    UserLoggedInEvent::class, LoginFailedEvent::class, UserLoggedOutEvent::class,
    RememberMeCookieTheftDetectedEvent::class, ImpersonationStartedEvent::class,
    ImpersonationEndedEvent::class, AccessDeniedEvent::class,
    TwoFactorEnabledEvent::class, TwoFactorDisabledEvent::class, PasswordChangedEvent::class,
] as $eventClass) {
    $dispatcher->addListener($eventClass, $listener);
}
```

Every event is scalars, so `get_object_vars()` is a complete, PII-bounded
record. Where it goes — a file, a SIEM, an append-only table — is the
listener's business, and the deployment's.

## Testing

Declare a recording dispatcher in the test app's `services.php` and assert
on what was dispatched:

```php
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
```

The framework's own `tests/Unit/Event` does exactly this.
