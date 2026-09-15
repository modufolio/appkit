# CSRF protection

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
- Cleared on successful login — every token minted before authentication stops validating; the next `getToken()` mints a fresh one

## What the kernel checks for you

On every **session-backed** (non-stateless) firewall, state-changing requests
(anything but `GET`/`HEAD`/`OPTIONS`/`TRACE`) must carry a valid CSRF token —
either the firewall token (`csrf_token_id`, default `csrf`) in the
`X-CSRF-Token`/`X-XSRF-Token` header, or a `_csrf_token` body field. This is
enforced for:

- restored sessions (the usual logged-in browser),
- requests authenticated by an **ambient credential** — a remember-me cookie,
  or HTTP Basic, whose cached realm the browser re-sends on its own. The
  kernel keys this on the authenticator implementing
  `AmbientCredentialInterface` (`RememberMeAuthenticator` and
  `BasicAuthenticator` do), not on the token class. Session-backed
  firewalls only — see the stateless note below,
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

- **stateless firewalls**, on every branch — `stateless => true` implies no
  kernel CSRF check. No session is ever restored there, so no token was
  minted for the client to present and a check could only fail. That
  includes HTTP Basic on a stateless firewall: the browser still re-sends a
  cached realm on its own, so a stateless Basic API *is* driveable
  cross-site from a browser that has authenticated to it. Keep such an API
  for non-browser clients (curl, server-to-server), or make the firewall
  session-backed so the ambient-credential check above applies,
- bearer/API-key/JWT/OAuth requests — the page attaches those credentials
  deliberately; no browser sends them unprompted, and their authenticators do
  not implement `AmbientCredentialInterface`. HTTP Basic is *not* in this
  group,
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

## Why the kernel enforces CSRF (and Symfony doesn't)

AppKit's security design is inspired by Symfony, but CSRF is the one place it
deliberately departs. Symfony has **no firewall-level CSRF check**: each
consumer protects itself — the Form component validates its own token, login
and logout opt in via config, and a plain controller calls
`isCsrfTokenValid()` (or uses `#[IsCsrfTokenValid]`) by hand. That model is
fail-open per route: a state-changing controller that forgets to validate is
silently unprotected, and nothing in the framework will ever tell you.

AppKit takes the opposite posture — one enforcement point in the
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

