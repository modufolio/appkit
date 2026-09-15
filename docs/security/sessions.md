# Sessions

## Session security

AppKit applies these session protections by default:

- `HttpOnly` — JavaScript cannot read the session cookie
- `SameSite=Lax` — mitigates most CSRF scenarios in modern browsers
- Session migration on login — the session ID is rotated after authentication and the pre-login session storage is destroyed, so a fixed ID cannot be replayed as an authenticated session (OWASP A07:2021)
- CSRF tokens are cleared at login so any pre-authentication tokens become invalid
- Session invalidation on user change — on each request the session user is reloaded via the user provider, and the session is dropped if security-relevant state changed (revoked roles or a changed password). Implement `EquatableInterface` on your `User` to control exactly which attributes trigger this; otherwise roles, password, and identifier are compared.

Add the `Secure` flag in production by setting `COOKIE_SECURE=true` in your environment. Everything else about the cookie — its name, `SameSite`, path, domain, lifetime, and the server-side `gc_maxlifetime` — is a `SessionConfiguration` declared in `config/services.php`; see [Session storage and cookie](#session-storage-and-cookie).

If you also issue a remember-me cookie, read the same variable for its `cookie_secure` option (`env()->getBool('COOKIE_SECURE', true)`). Symfony's remember-me inherits this from the session config; AppKit's authenticators are configured independently, so nothing stops the two cookies from drifting apart. A remember-me cookie left with `Secure` on a plain-HTTP dev site is simply never sent back, and the opposite pairing leaks the credential over HTTP.

## Session storage and cookie

Two process-level choices shape every request's session, both declared in
`config/services.php` and both optional.

**Where session data lives** is a `\SessionHandlerInterface`. The default is
PHP's file handler under `var/sessions`, which is fine for one node. Behind a
load balancer, or with several RoadRunner workers on several machines, declare
a shared store — Symfony ships handlers for Redis, Memcached, PDO, MongoDB and
more, all behind the same interface:

```php
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

$services->set(\SessionHandlerInterface::class, function (): \SessionHandlerInterface {
    $redis = new \Redis();
    $redis->connect(env()->getString('REDIS_HOST', '127.0.0.1'));

    return new RedisSessionHandler($redis, ['prefix' => 'sess:', 'ttl' => 86_400]);
});
```

The kernel builds the handler once per worker and hands it to every request's
state, so a handler may keep its connection open. Sticky sessions are then
unnecessary.

**How the cookie is issued** is a `SessionConfiguration`:

```php
use Modufolio\Appkit\Core\SessionConfiguration;

$services->set(SessionConfiguration::class, fn () => new SessionConfiguration(
    name: 'APPSESSID',
    cookieSecure: true,
    cookieSameSite: 'Strict',
    cookieLifetime: 0,        // a session cookie; seconds otherwise
    gcMaxLifetime: 86_400,    // server-side expiry, null keeps php.ini's
));
```

Without a declaration the defaults apply — `PHPSESSID`, `HttpOnly`,
`SameSite=Lax`, strict mode — with `Secure` read from `COOKIE_SECURE` once per
process. `SameSite=None` is refused without `Secure`, since browsers drop such a
cookie. The remember-me cookie is configured on its authenticator and does not
inherit from this; keep the two `Secure` flags in step.

## Idle timeout

`idle_timeout` terminates an authenticated session that has gone unused for
that many seconds. Symfony ships no equivalent — it gives you
`MetadataBag::getLastUsed()` and PHP's `session.gc_maxlifetime`, and leaves
enforcement to the application.

```php
->firewall('main', [
    'pattern'           => '/panel',
    'entry_point'       => '/panel/login',
    'idle_timeout'      => 900,                        // 15 minutes
    'idle_ignore_paths' => ['/panel/session/status'],
])
```

Every request through the firewall moves the deadline to `now + idle_timeout`.
Once it passes, the session is invalidated, any remember-me cookie is cleared —
otherwise it would sign the visitor straight back in and the timeout would mean
nothing — and the response redirects to `logout.target` (falling back to
`entry_point`), flashing `SessionIdleStatus::TIMEOUT_MESSAGE` as `info` so the
login page can say why.

The deadline is stored in the session under `SessionIdleStatus::DEADLINE_KEY`,
per firewall. A session that predates the setting is stamped rather than
expired, so enabling this does not sign out everyone who is currently online.

### Paths that do not count as activity

This is the part that is easy to get wrong. A browser that asks "how long have
I got left?" is making a request, and a request renews the deadline — so a page
polling its own session status keeps that session alive forever and the timeout
never fires.

`idle_ignore_paths` says it explicitly: the path is served normally, with the
session readable, but the deadline is left where it was.

Read the remaining time by injecting `SessionIdleStatus`:

```php
#[Route('/panel/session/status', methods: ['GET'])]
public function status(SessionIdleStatus $status): ResponseInterface
{
    return Response::json(['remaining' => $status->secondsRemaining]);
}
```

`secondsRemaining` is `null` when the firewall sets no `idle_timeout` or nobody
is signed in, and never negative. `App::idleSecondsRemaining()` is the same
value if you already hold the kernel.

### Extending a session

Anything *not* on the ignore list stamps a fresh deadline, so there is no
"extend" API to call: a button that POSTs to an ordinary path is enough. Extend
only on something the user did — never on a timer, which would defeat the
timeout as surely as a polling status endpoint.

## Token deserialization whitelist

AppKit's `TokenUnserializer` only deserialises a whitelist of classes from session-stored tokens. This prevents remote code execution via PHP unserialisation gadget chains.

Register your `User` entity before calling `boot()`:

```php
// In your application factory or bootstrap, before boot()
TokenUnserializer::register(User::class);
```

After `boot()` is called, the whitelist is frozen. No further classes can be added.

