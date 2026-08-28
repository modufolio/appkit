# Security

AppKit's security system is configured through `config/security.php` using a fluent `SecurityConfigurator` API. It covers firewalls, global access control rules, role hierarchy, CSRF protection, and session hardening. The design and flow are inspired by [Symfony Security](https://symfony.com/doc/current/security.html) — with one deliberate exception: CSRF is enforced centrally by the kernel rather than per consumer. [Why the kernel enforces CSRF (and Symfony doesn't)](#why-the-kernel-enforces-csrf-and-symfony-doesnt) explains the reasoning.

## The `SecurityConfigurator`

`config/security.php` returns a closure that receives a `SecurityConfigurator` instance.

```php
// config/security.php
use Modufolio\Appkit\Security\SecurityConfigurator;

return function (SecurityConfigurator $security): void {
    $security->firewall('main', [
        'pattern'        => '/',
        'authenticators' => ['form_login'],
        'entry_point'    => '/login',
        'logout'         => [
            'path'   => '/logout',
            'target' => '/',
        ],
    ]);

    $security->roleHierarchy([
        'ROLE_ADMIN' => ['ROLE_USER'],
    ]);
};
```

## Defining a firewall

Each firewall covers a path pattern and configures how authentication works for those routes.

```php
$security->firewall('api', [
    'pattern'        => '/api',
    'authenticators' => ['jwt'],
    'stateless'      => true,
]);
```

Firewall options:

| Key | Type | Description |
|-----|------|-------------|
| `pattern` | `string` | Path prefix to guard. `/admin` matches `/admin` and everything below it. |
| `authenticators` | `string[]` | Named authenticators from `config/authenticators.php`. |
| `entry_point` | `string` | Where unauthenticated users are redirected. |
| `stateless` | `bool` | `true` for API-style firewalls with no session. |
| `security` | `bool` | Set to `false` to disable security for this firewall entirely. |
| `methods` | `string[]` | Restrict the firewall to these HTTP methods. |
| `host` | `string` | Restrict the firewall to this host (case-insensitive, plain match). |
| `ips` | `string[]` | Restrict the firewall to these client IPs / CIDR ranges. |
| `logout.path` | `string` | POST to this URL to log out. Requires a CSRF token — see below. |
| `logout.target` | `string` | Redirect destination after logout. |
| `two_factor_path` | `string` | Path for the 2FA code entry form. Defaults to `/2fa`. |
| `csrf_delegated_paths` | `string[]` | Paths (firewall pattern syntax) whose controller validates its own CSRF token — the kernel check is skipped there. See below. |
| `csrf_form_tokens` | `array<string,string>` | Symfony-form token shapes the kernel accepts: form name → token id, e.g. `['contact' => 'contact_form']` accepts `contact[_token]`. See below. |

> **Firewall restrictions (Symfony-style).** A firewall handles a request only
> when *all* of its declared restrictions match — `pattern` **and** `methods`
> **and** `host` **and** `ips`. A request that fails any one of them falls
> through to the next firewall whose restrictions do match. This makes a
> method-scoped public firewall safe: a `security => false` firewall limited to
> `methods => ['GET']` exposes only reads, while writes to the same path fall
> through to an authenticated firewall.
>
> ```php
> $security->firewalls([
>     // Public GET-only API for the menu tree.
>     'menu_read' => ['pattern' => '/api/menu', 'methods' => ['GET'], 'security' => false],
>     // Everything else under /api (incl. writes to /api/menu) needs a token.
>     'api'       => ['pattern' => '/api', 'authenticators' => ['jwt'], 'stateless' => true],
> ]);
> ```
>
> Invalid firewall configuration is rejected at boot (in `dev`/`test`) against a
> schema — see [Validating configuration](#validating-configuration) below.

> **Logout is CSRF-protected.** Two equivalent proofs are accepted, mirroring
> the general CSRF layer:
>
> - **HTML forms** POST a `_csrf_token` field generated with the intention id
>   `logout`. This is a different id from login (`authenticate`) — a token
>   minted for one will not validate the other.
> - **fetch/XHR clients** (SPAs, Inertia apps) send the firewall's session
>   token (`csrf_token_id`, default `csrf`) via the `X-CSRF-Token` or
>   `X-XSRF-Token` header — the same header they already attach to every
>   other state-changing request.
>
> Without either, `AuthenticationException` is thrown.
>
> ```php
> $token = $csrfTokenManager->getToken('logout')->getValue();
> ```
>
> ```html
> <form method="post" action="/logout">
>   <input type="hidden" name="_csrf_token" value="<?= $token ?>">
> </form>
> ```
>
> A GET request to the logout path is not handled and leaves the session
> authenticated.

Pattern syntax uses plain string matching, not regex. This prevents ReDoS attacks. Two forms:

- `/admin` — matches any path that starts with `/admin`
- `api:0` — matches paths where the first segment equals `api`

## Multiple firewalls

You can register several firewalls. AppKit matches each request to the first firewall whose pattern fits.

```php
$security->firewalls([
    'api' => [
        'pattern'        => '/api',
        'authenticators' => ['jwt'],
        'stateless'      => true,
    ],
    'main' => [
        'pattern'        => '/',
        'authenticators' => ['form_login'],
        'entry_point'    => '/login',
        'logout'         => ['path' => '/logout', 'target' => '/'],
    ],
]);
```

## Validating configuration

Firewall configuration is checked against a schema
(`FirewallConfiguration`) whenever it is loaded. Type errors and keys that would
silently fail open are rejected with a clear message — for example a `methods`
value that is not a list, or a non-callable `csrf_validator`.

Validation runs in `dev` and `test` (where config is authored) but is **skipped
in `prod`** for performance: the schema is not re-built on every production
request. To catch a bad config before it ships, run the check in CI or at deploy
time:

```bash
php bin/console security:validate
```

It validates both firewalls and access-control rules and exits non-zero on the
first problem. To inspect the resolved configuration — firewalls, their
restrictions, access-control rules, and the role hierarchy — use:

```bash
php bin/console debug:firewall            # list everything
php bin/console debug:firewall main       # detail one firewall
```

## Global access control

Define path-based rules that apply before any controller runs.

```php
$security->accessControl('/admin', ['ROLE_ADMIN']);
$security->accessControl('/api/users', ['ROLE_ADMIN'], ['DELETE']);
```

Parameters:

1. Path pattern (same syntax as firewall patterns)
2. Required roles (array)
3. Methods (optional) — restrict the rule to specific HTTP verbs
4. Options (optional) — `ips`, `requires_channel`

Restrict by IP range:

```php
$security->accessControl('/metrics', ['ROLE_ADMIN'], null, [
    'ips' => ['127.0.0.1', '10.0.0.0/8'],
]);
```

Require HTTPS:

```php
$security->accessControl('/checkout', [], null, [
    'requires_channel' => 'https',
]);
```

An `http` request to an `https`-required path is **redirected** to the same URL
over `https` (preserving path and query), not hard-denied — the same request
over `https` is legitimate, so bouncing the user to an error page would be
wrong. The redirect is carried by `InsecureChannelException` and issued by the
exception handler.

Register multiple rules at once:

Unlike `accessControl()`, the bulk method stores each rule verbatim, so the rules must use associative keys (`path`, `roles`, optional `methods`) — positional arrays will silently match nothing and leave the paths unprotected:

```php
$security->accessControlRules([
    ['path' => '/admin', 'roles' => ['ROLE_ADMIN']],
    ['path' => '/api',   'roles' => ['ROLE_USER'], 'methods' => ['GET', 'POST']],
]);
```

For route-level access control, use `#[IsGranted]` instead. See [Routing](routing.md).

### Deny by default

By default a request that matches **no** access-control rule is allowed through
(the firewall still governs authentication). To flip this to fail-closed — deny
anything not explicitly allowed by a rule — opt in:

```php
$security->denyUnmatchedRequests();
```

With this on, a request matching no rule is refused: an unauthenticated visitor
is sent to the entry point to log in, an authenticated one gets a hard `403`.
Make sure every legitimately public path (assets, health checks, the login page)
has a matching `publicPath()` / `accessControl()` rule before enabling it.

### Trust-level access

Alongside ordinary `ROLE_*` attributes, rules and `#[IsGranted]` accept
trust-level attributes decided by *how* the request authenticated rather than by
the user's roles:

| Attribute | Granted when |
|-----------|--------------|
| `IS_AUTHENTICATED` | any authenticated token (including remember-me) |
| `IS_AUTHENTICATED_REMEMBERED` | a full login **or** a remember-me cookie |
| `IS_AUTHENTICATED_FULLY` | an interactive login this session (not remember-me) |
| `IS_IMPERSONATOR` | the request is impersonating another user (switch-user) |

```php
use Modufolio\Appkit\Security\AuthenticationTrustResolverInterface as Trust;

// Reachable from a remember-me cookie...
$security->accessControl('/account', [Trust::IS_AUTHENTICATED_REMEMBERED]);
// ...but changing the password needs a fresh, full login.
$security->accessControl('/account/password', [Trust::IS_AUTHENTICATED_FULLY], ['POST']);
```

When a rule requires `IS_AUTHENTICATED_FULLY` but the visitor is only
remembered, they are sent to log in again (step-up) rather than hard-denied —
the distinction between "authenticate more strongly" and "you may not do this"
is preserved.

## Role hierarchy

Users with a higher role automatically have all roles below it.

```php
$security->roleHierarchy([
    'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
    'ROLE_ADMIN'       => ['ROLE_USER'],
    'ROLE_USER'        => ['ROLE_GUEST'],
]);
```

AppKit caches up to 256 role combinations to keep role resolution fast in long-running workers.

## CSRF protection

`CsrfTokenManager` generates and validates CSRF tokens stored in the session.

**Generating a token in a controller:**

```php
// Inject CsrfTokenManagerInterface via config/controllers.php
$token = $this->csrfTokenManager->getToken('my-form')->getValue();
```

**Using it in a template:**

```html
<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
```

**Validating manually:**

```php
$valid = $this->csrfTokenManager->validateToken('my-form', $request->getParsedBody()['_csrf_token'] ?? '');
```

The `FormLoginAuthenticator` validates the CSRF token on `POST /login` automatically — but your login form must still render the token. Generate it with the token id `authenticate` and submit it in the `_csrf_token` field (both are configurable via the authenticator's `csrf_token_id` / `csrf_parameter` options):

```php
$token = $this->csrfTokenManager->getToken('authenticate')->getValue();
```
```html
<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
```

Token details:
- 32 random bytes (64 hex characters)
- Validated with `hash_equals()` — timing-safe
- Maximum 50 tokens per session (FIFO eviction)
- Rotated automatically on successful login

### What the kernel checks for you

On every **session-backed** (non-stateless) firewall, state-changing requests
(anything but `GET`/`HEAD`/`OPTIONS`/`TRACE`) must carry a valid CSRF token —
either the firewall token (`csrf_token_id`, default `csrf`) in the
`X-CSRF-Token`/`X-XSRF-Token` header, or a `_csrf_token` body field. This is
enforced for:

- restored sessions (the usual logged-in browser),
- first requests authenticated by a remember-me cookie,
- **anonymous requests to public paths** — an anonymous session cookie (a guest
  cart, wizard progress) is just as ambient as an authenticated one, so a
  cross-site POST against it is forgeable in exactly the same way,
- `POST {two_factor_path}/cancel` — cancelling a pending 2FA login wipes
  session state, so the route is POST-only and CSRF-checked by the kernel.

Besides the header and `_csrf_token` proofs, a firewall can declare
**Symfony-form-shaped tokens** the kernel should accept: a form named
`contact` posts its token as `contact[_token]`, keyed by the form type's
`csrf_token_id` — invisible to the flat extraction. Declare the pair and the
kernel validates it as an additional accepted proof (falling through to the
usual checks when absent):

```php
'csrf_form_tokens' => ['contact' => ContactFormType::CSRF_TOKEN_ID],
```

Not checked by the kernel:

- **stateless firewalls** — no session, nothing ambient to forge,
- bearer/API-key/JWT requests — the browser does not attach those credentials
  automatically,
- `POST /login` (the `entry_point`) and `POST {two_factor_path}` — the
  authenticator and your 2FA controller validate their own token ids
  (`authenticate`, and e.g. `2fa_verify`) there,
- paths listed in `csrf_delegated_paths` — for controllers that validate
  their own token, typically a Symfony form with form-level CSRF. The kernel
  steps aside so the form layer can answer with its own failure shape (a
  re-rendered form with a field error, a 422) instead of a hard 403 — the
  right response for a public form whose visitor's session expired
  mid-compose. The delegated controller MUST actually validate; delegation
  hands it the responsibility, not an exemption:

  ```php
  ->firewall('contact', [
      'pattern' => '/contact',
      // ContactFormType checks contact[_token] itself and re-renders on failure.
      'csrf_delegated_paths' => ['/contact'],
  ])
  ```
- firewalls that opt out with `csrf => false`, or handle special shapes
  (webhook receivers) with a `csrf_validator` callable.

### Why the kernel enforces CSRF (and Symfony doesn't)

AppKit's security design is inspired by Symfony, but CSRF is the one place it
deliberately departs. Symfony has **no firewall-level CSRF check**: each
consumer protects itself — the Form component validates its own token, login
and logout opt in via config, and a plain controller calls
`isCsrfTokenValid()` (or uses `#[IsCsrfTokenValid]`) by hand. That model is
fail-open per route: a state-changing controller that forgets to validate is
silently unprotected, and nothing in the framework will ever tell you.

AppKit takes the Laravel/Rails posture instead — one enforcement point in the
kernel, on by default, fail-closed: a state-changing request on a
session-backed firewall is rejected unless *some* accepted proof passes. A
forgotten check surfaces as a 403 in development, not as a silent hole in
production. This matches the rest of the framework's direction
(deny-by-default access control, generated write routes that are never
silently ungated).

The cost of a default-on check is that request shapes the kernel cannot see —
and layers that already validate — need a way to say so. That is exactly what
the escape valves above are, from most to least declarative:

| Option | Meaning | Symfony equivalent |
|--------|---------|--------------------|
| `csrf_form_tokens` | "Also accept this Symfony-form-shaped token" — the kernel still validates | none needed (the form is the only checker) |
| `csrf_delegated_paths` | "This path's controller validates its own token; step aside" | none needed — but the *contract* mirrors it: the delegate must validate, like every Symfony controller must |
| `csrf_validator` | Custom callable for shapes neither option expresses | `isCsrfTokenValid()` in a listener |
| `csrf => false` | No CSRF on this firewall at all | the Symfony default, everywhere |

Rule of thumb: reach for the options in that order. Each step down trades
declarativeness for flexibility, and `csrf => false` should be reserved for
firewalls that are genuinely immune (stateless APIs already skip the check
without it).

## Session security

AppKit applies these session protections by default:

- `HttpOnly` — JavaScript cannot read the session cookie
- `SameSite=Lax` — mitigates most CSRF scenarios in modern browsers
- Session migration on login — the session ID is rotated after authentication and the pre-login session storage is destroyed, so a fixed ID cannot be replayed as an authenticated session (OWASP A07:2021)
- CSRF tokens are cleared at login so any pre-authentication tokens become invalid
- Session invalidation on user change — on each request the session user is reloaded via the user provider, and the session is dropped if security-relevant state changed (revoked roles or a changed password). Implement `EquatableInterface` on your `User` to control exactly which attributes trigger this; otherwise roles, password, and identifier are compared.

Add the `Secure` flag in production by setting `COOKIE_SECURE=true` in your environment.

If you also issue a remember-me cookie, read the same variable for its `cookie_secure` option (`env()->getBool('COOKIE_SECURE', true)`). Symfony's remember-me inherits this from the session config; AppKit's authenticators are configured independently, so nothing stops the two cookies from drifting apart. A remember-me cookie left with `Secure` on a plain-HTTP dev site is simply never sent back, and the opposite pairing leaks the credential over HTTP.

## Authentication failure behaviour

The firewall treats a failed login differently depending on who presented the credential: a person submitting a form (interactive), or the browser attaching a cookie on its own (ambient).

**Interactive failures** — someone submitted a login form — flash the exception's `getMessageKey()` and redirect to the entry point. `getMessageKey()` is the user-safe half of the exception contract: `getMessage()` may carry internal detail destined for logs (*"User not found"*), while the key is always fit to display (*"Invalid credentials."*). Two deliberate obfuscations apply:

- A user that does not exist produces the same message as a wrong password, so responses never reveal whether an email is registered.
- Account-status failures (locked, disabled, expired — thrown by `UserChecker`) are also flashed as *"Invalid credentials."* — a distinct message would confirm to an attacker that the account exists. The original exception is preserved as `getPrevious()` for logging.

**Ambient failures** — the browser presented a remember-me cookie on its own — are silent. Nobody typed anything, so a cookie that no longer validates (expired, password changed, `secret` rotated) is not a failed login attempt; flashing an error would accuse a visitor who never tried, on every request until the cookie expires. Instead the firewall expires the dead cookie on the response (`Max-Age=0`) and the request continues anonymously: remaining authenticators still run, public paths stay reachable, protected paths redirect to the entry point without a message.

A failed interactive login also expires any remember-me cookie riding along on the request, and a successful login wins over the expiry of a stale one — the fresh cookie is always issued after the clearing header.

## Token deserialization whitelist

AppKit's `TokenUnserializer` only deserialises a whitelist of classes from session-stored tokens. This prevents remote code execution via PHP unserialisation gadget chains.

Register your `User` entity before calling `boot()`:

```php
// In AppFactory::create()
TokenUnserializer::register(User::class);
```

After `boot()` is called, the whitelist is frozen. No further classes can be added.

## Account lifecycle controls

`UserChecker` runs pre-auth and post-auth checks on every login attempt. It covers three opt-in account states. Each is activated by implementing the corresponding interface on your `User` entity.

### Locking accounts

`LockableUserInterface` lets you block login for administratively suspended users.

```php
use Modufolio\Appkit\Security\User\LockableUserInterface;

class User implements LockableUserInterface
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(nullable: true)]
    private ?string $lockedReason = null;

    public function isLocked(): bool          { return $this->lockedAt !== null; }
    public function getLockedAt(): ?\DateTimeImmutable { return $this->lockedAt; }
    public function getLockedReason(): ?string { return $this->lockedReason; }

    public function lock(string $reason): void
    {
        $this->lockedAt    = new \DateTimeImmutable();
        $this->lockedReason = $reason;
    }

    public function unlock(): void
    {
        $this->lockedAt    = null;
        $this->lockedReason = null;
    }
}
```

When `isLocked()` returns `true`, `UserChecker` throws `LockedAccountException` before credentials are checked. The `getLockedReason()` string is surfaced in the exception message shown to the user.

### Expiring accounts

`ExpirableUserInterface` blocks login after a fixed date. Use this for contractor accounts, trial periods, or time-limited access.

```php
use Modufolio\Appkit\Security\User\ExpirableUserInterface;

class User implements ExpirableUserInterface
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accountExpiresAt = null;

    public function isAccountExpired(): bool
    {
        return $this->accountExpiresAt !== null
            && $this->accountExpiresAt < new \DateTimeImmutable();
    }

    public function getAccountExpiresAt(): ?\DateTimeImmutable
    {
        return $this->accountExpiresAt;
    }
}
```

Set `accountExpiresAt` when creating the account. Once that date passes, login is blocked with `AccountExpiredException`.

### Expiring credentials

`CredentialsExpirableUserInterface` forces a password change after a set period. `UserChecker` checks this after credentials are verified — the user authenticated successfully, but the session is not established until they reset their password.

```php
use Modufolio\Appkit\Security\User\CredentialsExpirableUserInterface;

class User implements CredentialsExpirableUserInterface
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $credentialsExpireAt = null;

    public function isCredentialsExpired(): bool
    {
        return $this->credentialsExpireAt !== null
            && $this->credentialsExpireAt < new \DateTimeImmutable();
    }

    public function getCredentialsExpireAt(): ?\DateTimeImmutable
    {
        return $this->credentialsExpireAt;
    }
}
```

A typical policy: extend `credentialsExpireAt` by 90 days on every successful password change.

### Generating a temporary password

`SecurityHelper::generatePassword()` creates a cryptographically random password. It guarantees at least one character from each class: lowercase, uppercase, digit, and special character.

```php
use Modufolio\Appkit\Security\SecurityHelper;

$temporaryPassword = SecurityHelper::generatePassword(16); // length clamped to 8–64
```

Pair it with `CredentialsExpirableUserInterface` when creating accounts on behalf of users:

```php
$password = SecurityHelper::generatePassword();
$user->setPassword($hasher->hashPassword($user, $password));
$user->setCredentialsExpireAt(new \DateTimeImmutable()); // expired immediately

$entityManager->flush();

// email $password to the user — they must change it on first login
```

## What the framework does not handle

These are your responsibility — deliberately, not as gaps:

- **Brute-force protection** — `FileBruteForceProtection` and `RedisBruteForceProtection` ship with the framework but must be wired into `FormLoginAuthenticator` yourself, because the right backend (file vs Redis) and thresholds depend on your deployment. See [Authenticators](authenticators.md).
- **HSTS, Content-Security-Policy, X-Frame-Options** — response headers belong at the edge (nginx, Caddy, your CDN), where they also cover static assets and error pages the PHP app never renders, and where they can change without a deploy. If you prefer app-level headers, your `App` is a PSR-15 request handler and implements `handle()` itself, so it can wrap a small header-setting middleware around the kernel flow — or simply add the headers in `handle()` before returning the response.

## Impersonation (switch user)

AppKit provides `SwitchUserToken` for programmatic user impersonation. There is no automatic query-parameter mechanism — you control the switch and exit yourself in controller actions.

### Switching to another user

Protect the switch route with `#[IsGranted]` so only authenticated admins can reach it. This is the same pattern Symfony's `SwitchUserListener` relies on — the firewall handles unauthenticated users before any switch logic runs, so a null-token check inside the controller is neither necessary nor appropriate (it would produce a 500 instead of a proper login redirect).

The `string $firewall` parameter is injected automatically by the Kernel — it contains the name of the active firewall for the current request. `$this->session` in these examples is a `SessionInterface` injected through the constructor (`AbstractController` does not provide one) — wire it in `config/controllers.php`:

```php
use Modufolio\Appkit\Attributes\IsGranted;
use Modufolio\Appkit\Security\Token\SwitchUserToken;

#[IsGranted('ROLE_ADMIN')]
#[Route(path: '/users/{id}/switch', name: 'users_switch', methods: ['POST'])]
public function switchUser(
    #[MapEntity] User $targetUser,
    string $firewall,
): ResponseInterface {
    // $this->tokenStorage->getToken() is guaranteed non-null here:
    // #[IsGranted] already verified the user is authenticated.
    $currentToken = $this->tokenStorage->getToken();

    $refreshedTarget = $this->userProvider->refreshUser($targetUser);

    $switchToken = new SwitchUserToken(
        user:          $refreshedTarget,
        firewallName:  $firewall,
        roles:         $refreshedTarget->getRoles(),
        originalToken: $currentToken,
    );

    $this->tokenStorage->setToken($switchToken);
    $this->session->set('_security_' . $firewall, serialize($switchToken));

    return Response::redirect($this->urlGenerator->generate('dashboard'));
}
```

### Exiting impersonation

Check that the current token is a `SwitchUserToken`, retrieve the original token with `getOriginalToken()`, and restore it the same way.

```php
use Modufolio\Appkit\Security\Token\SwitchUserToken;

#[Route(path: '/users/switch/exit', name: 'users_switch_exit', methods: ['POST'])]
public function exitSwitchUser(string $firewall): ResponseInterface
{
    $currentToken = $this->tokenStorage->getToken();

    if (!$currentToken instanceof SwitchUserToken) {
        return Response::redirect($this->urlGenerator->generate('dashboard'));
    }

    $originalToken = $currentToken->getOriginalToken();
    $this->tokenStorage->setToken($originalToken);
    $this->session->set('_security_' . $firewall, serialize($originalToken));

    return Response::redirect($this->urlGenerator->generate('dashboard'));
}
```

### Detecting impersonation

`SwitchUserToken` exposes two ways to check whether the current session is impersonating:

```php
use Modufolio\Appkit\Security\Token\SwitchUserToken;

$token = $this->tokenStorage->getToken();

$token instanceof SwitchUserToken;       // true when impersonating
$token->isImpersonating();              // same check via method
$token->getAttribute('ROLE_PREVIOUS_ADMIN'); // true — set as an ATTRIBUTE, not a role
$token->getOriginalToken()->getUser();  // the original admin user
```

To gate a route or path on impersonation, use the `IS_IMPERSONATOR`
[trust-level attribute](#trust-level-access) rather than checking the token
type by hand — e.g. an "exit impersonation" banner action reachable only while
impersonating.

### `SwitchUserToken` constructor

```php
new SwitchUserToken(
    user:          UserInterface $user,          // the user to impersonate
    firewallName:  string $firewallName,         // must not be empty
    roles:         array $roles,                 // roles for the impersonated session
    originalToken: TokenInterface $originalToken, // the token to restore on exit
)
```
