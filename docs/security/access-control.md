# Access control

## Global access control

Define path-based rules that apply before any controller runs.

```php
$security->accessControl('/admin', ['ROLE_ADMIN']);
$security->accessControl('/api/users', ['ROLE_ADMIN'], ['DELETE']);
```

Parameters:

1. Path pattern (same syntax as firewall patterns)
2. Required roles (array)
3. Methods (optional) — the verbs the path accepts. A request to the path
   with any other verb is answered `405`, for everyone, before roles are
   checked. This is deliberately not Symfony's "the rule only applies to
   these verbs": a method list on a role rule closes the path to other
   verbs rather than leaving them unguarded. To exempt a verb instead, use
   `publicPath($path, [$verb])`, whose method list *is* a scope.
4. Options (optional) — `ips`, `requires_channel`, `firewall`

Restrict by IP range:

```php
$security->accessControl('/metrics', ['ROLE_ADMIN'], null, [
    'ips' => ['127.0.0.1', '10.0.0.0/8'],
]);
```

Require HTTPS (the scheme is read from the request URI; behind a proxy that
terminates TLS the runtime must rewrite the scheme from `X-Forwarded-Proto`
for a trusted proxy, or every request is upgraded again in a loop):

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

For route-level access control, use `#[IsGranted]` instead. See [Routing](../routing.md).

### Deny by default

By default a request that matches **no** access-control rule is allowed through
(the firewall still governs authentication). To flip this to fail-closed — deny
anything not explicitly allowed by a rule — opt in:

```php
$security->denyUnmatchedRequests();
```

With this on, a request matching no rule is refused: an unauthenticated visitor
is sent to the entry point to log in, an authenticated one gets a hard `403`.
A path a `publicPath()` rule matches counts as explicitly allowed, so the
login page and assets stay reachable.
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

