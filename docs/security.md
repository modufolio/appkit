# Security

AppKit's security system is configured through `config/security.php` using a fluent `SecurityConfigurator` API. It covers firewalls, global access control rules, role hierarchy, CSRF protection, and session hardening. The design and flow are inspired by [Symfony Security](https://symfony.com/doc/current/security.html) — with one deliberate exception: CSRF is enforced centrally by the kernel rather than per consumer. [Why the kernel enforces CSRF](security/csrf.md#why-the-kernel-enforces-csrf-and-symfony-doesnt) explains the reasoning.

| Page | What it covers |
|---|---|
| [Firewalls](security/firewalls.md) | `SecurityConfigurator`, every firewall option, multiple firewalls and how one is chosen, `security:validate` |
| [Access control](security/access-control.md) | Path rules, deny-by-default, trust levels (`IS_AUTHENTICATED_FULLY` and friends), role hierarchy |
| [CSRF protection](security/csrf.md) | What the kernel checks for you, the escape valves, and why enforcement lives in the kernel |
| [Sessions](security/sessions.md) | Cookie hardening, fixation defence, idle timeout and the paths that must not count as activity, token deserialization whitelist |
| [Accounts](security/accounts.md) | What a failed login does, locking and expiring accounts, expiring credentials, temporary passwords |
| [Trusted hosts](security/trusted-hosts.md) | The `Host` header allowlist, and why absolute URLs depend on it |
| [Impersonation](security/impersonation.md) | Switch user: POST plus CSRF, switching by hand, exiting, detecting |
| [Rate limiting](security/rate-limiting.md) | Declaring limiters, the firewall's login throttle, `#[RateLimit]` on routes, shared storage |

Authenticators — form login, remember-me, JWT, API key, OAuth, Google sign-in, 2FA — have their own reference in [Authenticators](authenticators.md).

## What the framework does not handle

These are your responsibility — deliberately, not as gaps:

- **Brute-force protection** — `FileBruteForceProtection` and `RedisBruteForceProtection` ship with the framework but must be wired into `FormLoginAuthenticator` yourself, because the right backend (file vs Redis) and thresholds depend on your deployment. See [Authenticators](authenticators.md).
- **HSTS, Content-Security-Policy, X-Frame-Options** — response headers belong at the edge (nginx, Caddy, your CDN), where they also cover static assets and error pages the PHP app never renders, and where they can change without a deploy. If you prefer app-level headers, your `App` is a PSR-15 request handler and implements `handle()` itself, so it can wrap a small header-setting middleware around the kernel flow — or simply add the headers in `handle()` before returning the response.
