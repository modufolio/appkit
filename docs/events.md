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

## Listeners the kernel wires

`config/events.php` declares them, the way `config/routes.php` declares
routes: a resource, and the type of the loader that reads it.

```php
use Modufolio\Appkit\Event\EventConfigurator;

return function (EventConfigurator $events): void {
    // Every class under the directory, read for #[AsEventListener] and for
    // EventSubscriberInterface.
    $events->import('src/Listener/', 'attribute');

    // One class, without walking a directory.
    $events->import(App\Listener\OrderMailer::class, 'attribute');

    // A file returning a literal declaration, in the shape a route array
    // file uses: a name, an event, a [class, method] pair and a priority.
    $events->import('config/listeners.php', 'array');
};
```

Which types exist is up to the loaders the application registered, exactly as
it is for routes: `attribute` and `array` ship with the framework, and a
loader of your own becomes usable here by being added to the resolver the
application hands the kernel — no change to `EventConfigurator`.

Nothing is scanned per request. The imports are resolved once and cached
beside the router's routes, checked for staleness outside prod and trusted in
it. The listener itself stays lazy: the dispatcher holds a closure that builds
it the first time its event is dispatched, and that instance lives for the
request, like a controller's.

`$events->on(SomeEvent::class, $callable)` is the one declaration that is not
an import, because a closure cannot come out of a loader — it is neither
cached nor lazy.

## Listeners the container wires

An application running the [Symfony container
layer](dependency-injection.md#the-symfony-container-behind-the-kernel) does not
write that factory. The container declares the dispatcher itself, under
Symfony's own id `event_dispatcher` and aliased to
`Psr\EventDispatcher\EventDispatcherInterface`, and a listener is a class
with an attribute on it:

```php
use Modufolio\Appkit\Event\Security\UserLoggedInEvent;
use Modufolio\Appkit\Event\Security\UserLoggedOutEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class SecurityAuditListener
{
    public function __construct(private readonly LoggerInterface $audit)
    {
    }

    #[AsEventListener]
    public function onLogin(UserLoggedInEvent $event): void
    {
        $this->audit->info('login', ['user' => $event->userIdentifier]);
    }

    #[AsEventListener]
    public function onLogout(UserLoggedOutEvent $event): void
    {
        $this->audit->info('logout', ['user' => $event->userIdentifier]);
    }
}
```

`load()` over the directory in `config/container.php` is the whole
registration, provided the defaults there `autoconfigure()`:

```php
$services = $container->services()->defaults()->autowire()->autoconfigure();
$services->load('App\\Listener\\', '../src/Listener/');
```

The event comes from the parameter type, so neither the attribute nor the
method name has to repeat it. On a class the attribute goes on an invokable
`__invoke()`; on a method it goes on the method, and naming a `method:` there
too is a contradiction rather than a preference. It is repeatable, so one
class can take the same event twice at different priorities:

```php
#[AsEventListener(event: UserLoggedInEvent::class, method: 'first', priority: 10)]
#[AsEventListener(event: UserLoggedInEvent::class, method: 'last', priority: -10)]
```

`EventSubscriberInterface` is autoconfigured the same way, so a subscriber
class needs no tag either.

None of this is discovery: Symfony's `RegisterListenersPass` resolves every
attribute and tag into `addListener()` calls on the dispatcher definition
while the container compiles, and in prod those calls are baked into the
dumped container. Nothing scans a directory or parses an attribute at boot,
which is the same bargain the rest of AppKit makes. The listener service
itself is still lazy: the dispatcher holds a closure that builds it the first
time its event is actually dispatched.

Precedence is worth stating once. An explicit
`EventDispatcherInterface` in `config/services.php` wins over the container's,
and `setEventDispatcher()` wins over both, so an application that wants the
dispatcher back can take it — at the cost of the attribute wiring, which lives
on the one it left behind. Short event *names* rather than classes (Symfony's
own `kernel.request` and friends) need the alias map; pass it to the factory
as `new ContainerFactory(eventAliases: [...])`.

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
