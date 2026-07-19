# Security

AppKit's security system is configured through `config/security.php` using a fluent `SecurityConfigurator` API. It covers firewalls, global access control rules, role hierarchy, CSRF protection, and session hardening. The design and flow are inspired by [Symfony Security](https://symfony.com/doc/current/security.html).

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
| `logout.path` | `string` | POST to this URL to log out. Requires a CSRF token — see below. |
| `logout.target` | `string` | Redirect destination after logout. |
| `two_factor_path` | `string` | Path for the 2FA code entry form. Defaults to `/2fa`. |

> **Logout is CSRF-protected.** The request must POST a `_csrf_token` field
> generated with the intention id `logout`, or `AuthenticationException` is thrown.
> This is a different id from login (`authenticate`) — a token minted for one will
> not validate the other.
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

Register multiple rules at once:

Unlike `accessControl()`, the bulk method stores each rule verbatim, so the rules must use associative keys (`path`, `roles`, optional `methods`) — positional arrays will silently match nothing and leave the paths unprotected:

```php
$security->accessControlRules([
    ['path' => '/admin', 'roles' => ['ROLE_ADMIN']],
    ['path' => '/api',   'roles' => ['ROLE_USER'], 'methods' => ['GET', 'POST']],
]);
```

For route-level access control, use `#[IsGranted]` instead. See [Routing](routing.md).

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

## Session security

AppKit applies these session protections by default:

- `HttpOnly` — JavaScript cannot read the session cookie
- `SameSite=Lax` — mitigates most CSRF scenarios in modern browsers
- Session migration on login — the session ID is rotated after authentication to prevent session fixation (OWASP A07:2021)
- CSRF tokens are cleared at login so any pre-authentication tokens become invalid
- Session invalidation on user change — on each request the session user is reloaded via the user provider, and the session is dropped if security-relevant state changed (revoked roles or a changed password). Implement `EquatableInterface` on your `User` to control exactly which attributes trigger this; otherwise roles, password, and identifier are compared.

Add the `Secure` flag in production by setting `COOKIE_SECURE=true` in your environment.

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

These are your responsibility:

- **Brute-force protection** — `FileBruteForceProtection` and `RedisBruteForceProtection` exist but must be wired manually into `FormLoginAuthenticator`. See [Authenticators](authenticators.md).
- **HSTS** — set `Strict-Transport-Security` in nginx, Caddy, or your CDN.
- **Content Security Policy** — set `Content-Security-Policy` at the edge.
- **X-Frame-Options** — set in your reverse proxy configuration.

## Impersonation (switch user)

AppKit provides `SwitchUserToken` for programmatic user impersonation. There is no automatic query-parameter mechanism — you control the switch and exit yourself in controller actions.

### Switching to another user

Protect the switch route with `#[IsGranted]` so only authenticated admins can reach it. This is the same pattern Symfony's `SwitchUserListener` relies on — the firewall handles unauthenticated users before any switch logic runs, so a null-token check inside the controller is neither necessary nor appropriate (it would produce a 500 instead of a proper login redirect).

The `string $firewall` parameter is injected automatically by the Kernel — it contains the name of the active firewall for the current request.

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
        firewallName:  'main',
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

### `SwitchUserToken` constructor

```php
new SwitchUserToken(
    user:          UserInterface $user,          // the user to impersonate
    firewallName:  string $firewallName,         // must not be empty
    roles:         array $roles,                 // roles for the impersonated session
    originalToken: TokenInterface $originalToken, // the token to restore on exit
)
```
