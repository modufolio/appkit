# Firewalls

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
| `pattern` | `string` | Path prefix to guard, matched on whole segments: `/admin` matches `/admin` and `/admin/users`, not `/administrator`. |
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
| `switch_user` | `array` | User impersonation. Off unless `enabled` is `true` — see [Impersonation](impersonation.md). |
| `csrf_delegated_paths` | `string[]` | Paths (firewall pattern syntax) whose controller validates its own CSRF token — the kernel check is skipped there. See below. |
| `csrf_form_tokens` | `array<string,string>` | Symfony-form token shapes the kernel accepts: form name → token id, e.g. `['contact' => 'contact_form']` accepts `contact[_token]`. See below. |
| `csrf` | `bool` | `false` turns the kernel CSRF check off for this firewall. Defaults to `true`. See below. |
| `idle_timeout` | `int` | Seconds of inactivity after which the session is terminated. Absent or `0` disables it. See [Idle timeout](sessions.md#idle-timeout). |
| `rate_limit` | `string` | The name of a limiter from `rateLimiter()`; every credential presented to this firewall counts against it, per client address. See [Rate limiting](rate-limiting.md). |
| `idle_ignore_paths` | `string[]` | Paths (firewall pattern syntax) that are served without counting as activity. See [Idle timeout](sessions.md#idle-timeout). |
| `csrf_token_id` | `string` | Id of the session token fetch/XHR clients send in the `X-CSRF-Token` header. Defaults to `csrf`. |
| `csrf_validator` | `callable` | `function ($request, $tokenManager): ?bool` for token shapes the options above cannot express: `true` accepts, `false` rejects, `null` falls through to the default check. Must be callable — config validation refuses anything else. |

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

- `/admin` — prefix match on whole path segments: `/admin` and `/admin/...`, but not `/administrator`
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

It checks the firewalls and then the access-control rules. Each section stops
at its first problem, so a run reports at most one firewall error and one
rule error, and exits non-zero if either was found. To inspect the resolved configuration — firewalls, their
restrictions, access-control rules, and the role hierarchy — use:

```bash
php bin/console debug:firewall            # list everything
php bin/console debug:firewall main       # detail one firewall
```

