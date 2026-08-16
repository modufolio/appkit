# Authenticators

AppKit ships with six authenticators. Each handles a different authentication strategy. Configure them in `config/authenticators.php` and reference them by name in `config/security.php`.

## Available authenticators

| Authenticator | Use case |
|---------------|----------|
| `FormLoginAuthenticator` | Username/password form login |
| `BasicAuthenticator` | HTTP Basic authentication |
| `ApiKeyAuthenticator` | Static API key in a header |
| `JwtAuthenticator` | JSON Web Token in `Authorization: Bearer` |
| `OAuthAuthenticator` | OAuth 2.1 access tokens |
| `RememberMeAuthenticator` | Persistent login cookie |

## Form login

The most common authenticator. Checks `POST /login` for email and password fields, validates a CSRF token, looks up the user, and verifies the password.

```php
// config/authenticators.php
use App\Repository\UserRepository;
use Modufolio\Appkit\Security\Authenticator\FormLoginAuthenticator;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;
use Modufolio\Appkit\Security\User\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

return [
    'form_login' => function ($container) {
        return new FormLoginAuthenticator(
            userProvider:    $container->get(UserProviderInterface::class),
            csrfTokenManager: $container->get(CsrfTokenManagerInterface::class),
            session:         $container->get(FlashBagAwareSessionInterface::class),
            passwordHasher:  $container->get(UserPasswordHasherInterface::class),
            options: [
                'check_path'          => '/login',
                'login_path'          => '/login',
                'username_parameter'  => 'email',
                'password_parameter'  => 'password',
            ],
        );
    },
];
```

Security details:
- CSRF token required on every login attempt
- Timing-safe dummy password verification prevents user enumeration — invalid usernames take the same time as valid ones
- Inertia.js requests receive `303` redirects automatically

### Adding brute-force protection

Pass a `BruteForceProtectionInterface` to the constructor.

```php
use Modufolio\Appkit\Security\BruteForce\FileBruteForceProtection;

new FormLoginAuthenticator(
    // ...
    bruteForce: new FileBruteForceProtection(
        storageDir:      $baseDir . '/storage/brute-force',
        maxAttempts:     5,
        lockoutDuration: 900,   // 15 minutes
        windowDuration:  300,   // 5-minute sliding window
    ),
);
```

Or use Redis:

```php
use Modufolio\Appkit\Security\BruteForce\RedisBruteForceProtection;

new FormLoginAuthenticator(
    // ...
    bruteForce: RedisBruteForceProtection::fromDsn('redis://localhost:6379'),
);
```

`RedisBruteForceProtection::fromDsn()` accepts `redis://`, `rediss://`, and Unix socket DSNs. It requires the phpredis extension.

`BasicAuthenticator` accepts the same optional `BruteForceProtectionInterface` as its third constructor argument, so password-based HTTP Basic endpoints can be throttled the same way.

## Brute-force protection interface

Both implementations satisfy `BruteForceProtectionInterface`:

```php
$protection->recordFailure(string $identifier, ?string $ipAddress = null): void
$protection->recordSuccess(string $identifier, ?string $ipAddress = null): void
$protection->isLocked(string $identifier, ?string $ipAddress = null): bool
$protection->getFailureCount(string $identifier, ?string $ipAddress = null): int
$protection->getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int
$protection->reset(string $identifier, ?string $ipAddress = null): void
```

`FileBruteForceProtection` stores attempt data in JSON files under `storageDir`, using atomic file locking (`LOCK_EX`) for safety.

## JWT authenticator

Use this for stateless API firewalls where each request carries a signed JSON Web Token.

```php
// config/security.php
$security->firewall('api', [
    'pattern'        => '/api',
    'authenticators' => ['jwt'],
    'stateless'      => true,
]);
```

```php
// config/authenticators.php
'jwt' => function ($container) {
    return new JwtAuthenticator(
        userProvider: $container->get(UserProviderInterface::class),
        options: [
            'algorithm'  => 'HS256',
            'secret_key' => env()->getRequired('JWT_SECRET'), // HMAC: fills both signing and verification keys
        ],
    );
},
```

The authenticator extracts the token from `Authorization: Bearer <token>` and validates the signature. `secret_key` is only valid for HMAC algorithms (HS256/HS384/HS512); for asymmetric algorithms (RS*/ES*) pass `signing_key` and `verification_key` separately.

## API key authenticator

For simple machine-to-machine requests where a static key is passed in a header.

The constructor takes two parameters — `userProvider` and an `options` array. The
individual settings are keys inside `options`, not constructor parameters of their
own, so `header_name` goes in the array rather than being passed as `headerName:`.

```php
'api_key' => function ($container) {
    return new ApiKeyAuthenticator(
        userProvider: $container->get(UserProviderInterface::class),
        options: [
            'api_keys'    => ['key-for-service-a' => 'service-a@example.com'],
            'header_name' => 'X-API-KEY',   // default
        ],
    );
},
```

| Option | Default | Description |
|---|---|---|
| `api_keys` | `[]` | **Required.** An empty array throws `InvalidArgumentException` at construction. |
| `header_name` | `X-API-KEY` | Header carrying the key |
| `query_parameter` | `null` | Optional query-string fallback |

## Remember me

`RememberMeAuthenticator` issues a long-lived cookie that authenticates the user on return visits. The cookie is HMAC-signed and tied to the user's current password hash, so rotating the password immediately invalidates all outstanding remember-me cookies — no revocation table required.

### Configuring the authenticator

```php
// config/authenticators.php
use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;

return [
    'remember_me' => function ($container) {
        return new RememberMeAuthenticator(
            userProvider: $container->get(UserProviderInterface::class),
            options: [
                'secret'          => env()->getRequired('APP_SECRET'), // shared HMAC secret
                'cookie_name'     => 'REMEMBERME',
                'cookie_lifetime' => 2592000, // 30 days
                // Mirror the session cookie so both follow one HTTP/HTTPS
                // policy, as Symfony's remember-me inherits framework.session.
                'cookie_secure'   => env()->getBool('COOKIE_SECURE', true),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ],
        );
    },
];
```

Add it to your firewall after `form_login` so it runs when the session token is absent:

```php
// config/security.php
$security->firewall('main', [
    'pattern'        => '/',
    'authenticators' => ['form_login', 'remember_me'],
    'entry_point'    => '/login',
    'logout'         => ['path' => '/logout', 'target' => '/'],
]);
```

### Issuing the cookie after login

The firewall issues the cookie automatically. When an interactive login (e.g. `form_login`) succeeds and the login request carries the opt-in parameter, the firewall attaches the signed `Set-Cookie` header to the response itself — no controller code required.

The opt-in parameter defaults to `_remember_me` and is read from the login POST body first, then the query string. Any usual truthy encoding counts (`1`, `true`, `on`, or boolean `true`); an absent or falsey value means no cookie is issued, so ordinary logins are unaffected. A typical login form just adds:

```html
<label>
    <input type="checkbox" name="_remember_me" value="1"> Remember me
</label>
```

Rename the parameter via the authenticator's `remember_parameter` option:

```php
new RememberMeAuthenticator(
    userProvider: $container->get(UserProviderInterface::class),
    options: [
        'secret'             => env()->getRequired('APP_SECRET'),
        'remember_parameter' => 'remember', // default: '_remember_me'
        // ...
    ],
);
```

Auto-issue only requires the `remember_me` authenticator to be listed on the firewall (as shown above). It is skipped for stateless firewalls, and a request authenticated purely by an existing remember-me cookie does not re-issue the cookie — only a fresh interactive login does.

#### Manual issuance (advanced)

If you need to mint the cookie outside the interactive login flow — for example right after programmatic registration — you can still build it yourself:

```php
use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;

// Retrieve the registered authenticator from the container
$rememberMe = $this->get(RememberMeAuthenticator::class);

$response = Response::redirect($urlGenerator->generate('dashboard'));

// buildRememberMeCookieHeader() produces the complete Set-Cookie value
return $response->withAddedHeader(
    'Set-Cookie',
    $rememberMe->buildRememberMeCookieHeader($user),
);
```

For full control over the header, `generateRememberMeCookie()` returns just the signed value and `getCookieOptions()` the configured attributes:

```php
$cookieValue   = $rememberMe->generateRememberMeCookie($user);
$cookieOptions = $rememberMe->getCookieOptions();

return $response->withAddedHeader('Set-Cookie', sprintf(
    '%s=%s; Expires=%s; Path=%s; SameSite=%s%s%s',
    $rememberMe->getCookieName(),
    $cookieValue,
    gmdate('D, d M Y H:i:s T', $cookieOptions['expires']),
    $cookieOptions['path'],
    $cookieOptions['samesite'],
    $cookieOptions['secure']   ? '; Secure'   : '',
    $cookieOptions['httponly'] ? '; HttpOnly' : '',
));
```

### How the cookie works

The cookie value is `base64(identifier:expires:hmac)`. The HMAC covers the identifier, the expiry timestamp, and a SHA-256 fingerprint of the user's stored password hash. This means:

- Changing the user's password invalidates the cookie without touching the database.
- The expiry is checked before the HMAC, so expired cookies fail fast.
- `hash_equals()` is used for the signature comparison — timing-safe.

> **Secret rotation**: if you rotate `APP_SECRET`, all existing remember-me cookies are invalidated. Users will need to log in again.

### Persistent tokens (theft detection)

The default mode above is stateless: nothing is stored server-side, so a stolen
cookie stays valid until it expires or the password changes, and there is no
per-device revocation. For higher-value applications you can opt into
**persistent tokens**, which add server-side theft detection and rotation
(Symfony's series+value scheme).

Pass a `RememberMeTokenProviderInterface` as the third constructor argument:

```php
use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Security\RememberMe\FileTokenProvider;

new RememberMeAuthenticator(
    userProvider: $container->get(UserProviderInterface::class),
    options: ['secret' => env()->getRequired('APP_SECRET'), /* cookie_* … */],
    tokenProvider: new FileTokenProvider(storageDir: $baseDir . '/var/remember-me'),
);
```

With a provider configured, each cookie carries a **series** id and a one-time
**value**:

- On every successful use the value is **rotated** — a fresh value is stored and
  re-issued in the cookie, so a captured cookie is good for at most one request.
- If a cookie presents a known series with the **wrong** value, that is the
  fingerprint of a stolen-and-replayed cookie: all tokens in the series are
  deleted (logging the device out everywhere) and `CookieTheftException` is
  raised.

`FileTokenProvider` (filesystem) and `InMemoryTokenProvider` (tests) ship with
the framework; implement `RememberMeTokenProviderInterface` to back tokens with
your own store (e.g. a database table). Deleting a user's tokens
(`deleteTokensByUserIdentifier()`) revokes remember-me on all their devices.

### When the cookie stops validating

A remember-me cookie that fails validation is not treated as a failed login: the firewall silently expires it on the response and serves the request anonymously — no error message, no flash. Without this, a browser holding a dead cookie (password changed, secret rotated) would see *"Invalid credentials."* on every request until the cookie expired. See [Authentication failure behaviour](security.md#authentication-failure-behaviour).

## OAuth 2.1

`OAuthService` handles token issuance, validation, refresh, and revocation. You provide a Doctrine entity that implements `OAuthAccessTokenInterface`.

### Issuing a token

```php
use Modufolio\Appkit\Security\OAuth\OAuthService;

$tokenService = new OAuthService(
    entityManager:       $this->entityManager(),
    tokenRepository:     $this->get(OAuthAccessTokenRepositoryInterface::class),
    accessTokenEntityClass: AccessToken::class,
);

$token = $tokenService->createAccessToken(
    user:               $user,
    clientId:           'mobile-app',
    grantType:          'password',
    scopes:             ['read', 'write'],
    includeRefreshToken: true,
);

return Response::json($tokenService->formatTokenResponse($token));
```

### Validating a token

```php
$token = $tokenService->validateAccessToken($bearerToken);

if ($token === null) {
    return Response::json(['error' => 'Unauthorized'], 401);
}
```

### Refreshing a token

`refreshAccessToken()` implements refresh token rotation: each use issues a new access token and a new refresh token. The old refresh token is invalidated.

```php
$newToken = $tokenService->refreshAccessToken($refreshToken, $clientId, $request);
```

### Token storage

Access tokens and refresh tokens are hashed with SHA-256 before storage. The entity also stores the IP address and User-Agent of the issuing request. Token lifetimes: access tokens expire after 1 hour, refresh tokens after 30 days.

Your `AccessToken` entity must implement `OAuthAccessTokenInterface`. Use `OAuthAccessTokenRepositoryInterface` for the repository.

## Two-factor authentication (TOTP)

AppKit includes a full TOTP implementation via `TotpService`.

### Setting up 2FA for a user

```php
$totpService = new TotpService(
    entityManager:          $this->entityManager(),
    totpSecretRepository:   $this->get(UserTotpSecretRepositoryInterface::class),
    twoFactorEntityClass:   TotpSecret::class,
    clock:                  $this->get(Psr\Clock\ClockInterface::class),
    issuer:                 'My App',
);

// Generate and persist a secret
$secret = $totpService->generateSecret($user);

// Show the QR code to the user
$qrCodeDataUri = $totpService->generateQrCode($secret);

// Or get the provisioning URI for a custom QR generator
$uri = $totpService->getProvisioningUri($secret);
```

### Enabling 2FA after QR scan

```php
$enabled = $totpService->enableTwoFactor($secret, $codeFromAuthApp);
```

The user must submit a valid TOTP code to confirm they scanned the QR code correctly.

### Verifying a code during login

The `FormLoginAuthenticator` handles the 2FA flow automatically when a `TwoFactorServiceInterface` is passed to it. If the user has 2FA enabled, authentication pauses and they are redirected to the `two_factor_path` (default `/2fa`) to enter their code.

### Backup codes

```php
$codes = $totpService->regenerateBackupCodes($secret); // returns 10 codes, format XXXX-XXXX
$valid = $totpService->verifyBackupCode($secret, $enteredCode);
```

Backup codes are single-use. Each verified code is removed from the stored list.

### Disabling 2FA

```php
$totpService->disableTwoFactor($user);
```

### Your entity

Your `TotpSecret` entity must implement `UserTotpSecretInterface` (which extends `TwoFactorSecret`). The repository must implement `UserTotpSecretRepositoryInterface`.

## User model requirements

All authenticators require your `User` class to implement `Modufolio\Appkit\Security\User\UserInterface`:

```php
getId(): mixed               // primary key, used to reload the user
getEmail(): string           // email address
getUserIdentifier(): string  // unique identifier, typically email
getRoles(): array            // e.g. ['ROLE_USER']
isEnabled(): bool            // return false to block login
eraseCredentials(): void     // clear any transient sensitive data
```

### Loading users

Authenticators look users up through a `UserProviderInterface`. You can implement it on your Doctrine repository, or use the built-in `EntityUserProvider` as a drop-in instead of hand-rolling `loadUserByIdentifier`/`refreshUser`/`supportsClass`:

```php
use Modufolio\Appkit\Security\User\EntityUserProvider;

'user_provider' => fn ($container) => new EntityUserProvider(
    entityManager:      $container->get(EntityManagerInterface::class),
    entityClass:        App\Entity\User::class,
    identifierProperty: 'email',
);
```

It reloads the user from the database on refresh (so revoked roles / changed passwords are picked up) and implements `PasswordUpgraderInterface`, so transparent rehashing works out of the box when the entity has a `setPassword()` method.

Optional interfaces unlock additional checks in `UserChecker`:

| Interface | Methods | Behaviour |
|-----------|---------|-----------|
| `PasswordAuthenticatedUserInterface` | `getPassword()` | Required for form login |
| `LockableUserInterface` | `isLocked()`, `getLockedAt()`, `getLockedReason()` | Blocks login when locked |
| `ExpirableUserInterface` | `isAccountExpired()`, `getAccountExpiresAt()` | Blocks login when expired |
| `CredentialsExpirableUserInterface` | `isCredentialsExpired()`, `getCredentialsExpireAt()` | Forces password change |

## Passwords

`UserPasswordHasher` uses Argon2id by default.

```php
use Modufolio\Appkit\Security\User\UserPasswordHasher;

$hasher = new UserPasswordHasher();

$hash = $hasher->hashPassword($user, $plainPassword);
$valid = $hasher->isPasswordValid($user, $plainPassword);
$needs = $hasher->needsRehash($user); // true when algorithm changed
```

The `hashPassword()` and `isPasswordValid()` methods use PHP 8.4's `#[\SensitiveParameter]` on the plaintext argument, so it is excluded from stack traces and error logs.

### Transparent password rehashing

When you raise the hashing cost or change the algorithm, existing users still have the old hash stored. `FormLoginAuthenticator` and `BasicAuthenticator` upgrade it automatically after a successful login — but only when the user provider can persist the new hash. Implement `PasswordUpgraderInterface` on your provider to opt in:

```php
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\PasswordUpgraderInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;

class UserRepository extends ServiceEntityRepository implements UserProviderInterface, PasswordUpgraderInterface
{
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
```

After each successful login the authenticator calls `needsRehash()`; if the hash is outdated and the provider implements `PasswordUpgraderInterface`, it rehashes the supplied password and calls `upgradePassword()`. Providers that do not implement the interface are left untouched.
