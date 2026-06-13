# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-06-13

This release hardens the authentication flow and individual authenticators.
Several entries are **behaviour changes** — they fix real gaps, but a request or
token that previously succeeded may now be rejected. Read the ⚠️ items before
upgrading.

### Security

- **Logout now clears remember-me cookies.** `App::logout()` previously
  invalidated the session but left any `REMEMBERME` cookie in the browser. The
  cookie's HMAC is still valid after logout (the password is unchanged), so the
  very next request silently re-authenticated the user — logout did not actually
  log out. Logout now emits an expired `Set-Cookie` for every remember-me
  authenticator configured on the firewall.
  (`src/Core/AppSecurity.php`, `src/Security/Authenticator/RememberMeAuthenticator.php`)

- **CSRF is now enforced on a remember-me-authenticated first request.** CSRF
  validation previously ran only on the restored-session path, which a request
  authenticated purely by a remember-me cookie skips. A state-changing request
  carrying only that ambient cookie therefore executed without a CSRF check.
  Such requests are now CSRF-checked, matching the restored-session path.
  Bearer/API-key tokens remain exempt (the browser does not attach them
  automatically, so they are not forgeable cross-site). (`src/Core/AppSecurity.php`)

- **JWTs without an `exp` claim are now rejected by default.** `firebase/php-jwt`
  only enforces expiry when the claim is present, so a token minted without
  `exp` was valid forever. The `JwtAuthenticator` now rejects tokens that lack
  `exp`. (`src/Security/Authenticator/JwtAuthenticator.php`)

### Changed

- ⚠️ **`JwtAuthenticator` requires the `exp` claim.** New option
  `require_exp`, defaulting to `true`. **If your issuer does not set `exp`, those
  tokens will now be rejected.** Fix the issuer to set `exp` (recommended), or
  opt out per-authenticator with `'require_exp' => false`.

- ⚠️ **Firewall pattern matching is now segment-aware.** A path-prefix firewall
  pattern matches on whole path segments: `/admin` matches `/admin` and
  `/admin/users` but no longer matches `/administrator`. This aligns firewall
  matching with the access-control matcher. The catch-all `/` pattern still
  matches every path. **Review firewalls whose pattern was relying on bare
  string-prefix matching.** (`src/Core/AbstractApplicationState.php`)

- ⚠️ **`checkPostAuth` now runs when a session token is restored.** Previously
  only `checkPreAuth` ran on the restored-session path, so credential-expiry
  (and any post-auth check) was enforced only at login. A user whose credentials
  expire — or whose account becomes locked/disabled — is now rejected on their
  **next** request instead of keeping their existing session. Ensure the route
  that lets a user recover (e.g. password reset) is reachable without tripping
  the check. (`src/Core/AppSecurity.php`)

- ⚠️ **Authenticators now run in the firewall's declared order.** Execution
  previously followed the global authenticator-registry order regardless of the
  order a firewall listed them in. The firewall's `authenticators` list is now
  authoritative. **If you relied on the old global ordering, confirm your
  firewall lists authenticators in the intended precedence** (e.g.
  `['form_login', 'remember_me']`). (`src/Core/AppSecurity.php`)

### Added

- `RememberMeAuthenticator::buildClearCookieHeader()` — builds the expired
  `Set-Cookie` header used to clear a remember-me cookie on logout.
- `JwtAuthenticator` `require_exp` option (default `true`).
