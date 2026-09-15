# Accounts and authentication outcomes

## Authentication failure behaviour

The firewall treats a failed login differently depending on who presented the credential: a person submitting a form (interactive), or the browser attaching a cookie on its own (ambient).

**Interactive failures** — someone submitted a login form — flash the exception's `getMessageKey()` and redirect to the entry point. `getMessageKey()` is the user-safe half of the exception contract: `getMessage()` may carry internal detail destined for logs (*"User not found"*), while the key is always fit to display (*"Invalid credentials."*). Two deliberate obfuscations apply:

- A user that does not exist produces the same message as a wrong password, so responses never reveal whether an email is registered.
- Account-status failures (locked, disabled, expired — thrown by `UserChecker`) are also flashed as *"Invalid credentials."* — a distinct message would confirm to an attacker that the account exists. The original exception is preserved as `getPrevious()` for logging.

**Ambient failures** — the browser presented a remember-me cookie on its own — are silent. Nobody typed anything, so a cookie that no longer validates (expired, password changed, `secret` rotated) is not a failed login attempt; flashing an error would accuse a visitor who never tried, on every request until the cookie expires. Instead the firewall expires the dead cookie on the response (`Max-Age=0`) and the request continues anonymously: remaining authenticators still run, public paths stay reachable, protected paths redirect to the entry point without a message.

A failed interactive login also expires any remember-me cookie riding along on the request, and a successful login wins over the expiry of a stale one — the fresh cookie is always issued after the clearing header.

## Account lifecycle controls

`UserChecker` runs on every login attempt — but only once the authenticator has returned a user, which for form login means *after* the password has been verified (`AppSecurity::tryAuthenticators()`). The firewall then calls `checkPreAuth()` (deleted, disabled, locked or expired account) and `checkPostAuth()` (expired credentials) back to back; the names follow Symfony's convention and say nothing about ordering relative to the credential check. The same two calls run when a session token is restored on each request — a user that fails them is logged out — and on the target of a switch-user.

What the user sees is always the same: on an interactive login the firewall re-wraps every `AccountStatusException` as `BadCredentialsException` before flashing it, so the login page shows *"Invalid credentials."* whatever the real reason (see [Authentication failure behaviour](#authentication-failure-behaviour)). The specific exception is kept as `getPrevious()`, and `UserChecker` writes a warning with the reason to the logger it was given. On a stateless firewall, or one without an `entry_point`, the exception propagates to the exception handler instead.

It covers three opt-in account states. Each is activated by implementing the corresponding interface on your `User` entity.

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

When `isLocked()` returns `true`, `UserChecker` throws `LockedAccountException` — after the password has been verified, not before, so a wrong password on a locked account still fails as a wrong password. The user is never shown the lock: the flash is *"Invalid credentials."*, like every account-status failure. `getLockedReason()` (or a generic sentence when it is `null`) becomes the `LockedAccountException` message, which reaches your logs via `getPrevious()` on the wrapped exception and as `locked_reason` in the checker's own warning entry. An account locked while logged in is logged out on its next request.

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

Set `accountExpiresAt` when creating the account. Once that date passes, login is blocked with `AccountExpiredException` — shown to the user as *"Invalid credentials."* — and an open session is logged out on its next request.

### Expiring credentials

`CredentialsExpirableUserInterface` blocks login after a set period until the password is changed. `UserChecker` checks it in `checkPostAuth()`, immediately after the pre-auth checks — the user authenticated successfully, but no session token is created. The login page still shows the generic *"Invalid credentials."*, so a "please reset your password" prompt has to come from your own flow (a reset link sent when the date passes, say), not from the login error.

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

