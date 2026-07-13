# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.1] - 2026-07-13

Internal cleanup pass driven by PHPStan, no public behaviour changes.

### Fixed

- **`DataGridResolver` removed.**

- **`ImageProcessor` no longer takes an unused `$storage` argument.** `PhotoLab`
  already resolves storage-based paths through the `File` it builds; forwarding
  `$storage` into `ImageProcessor` again was dead weight. Constructor now takes
  `(FileInterface $file, JobStorageInterface $jobStorage)`.
  (`src/Image/ImageProcessor.php`, `src/Image/PhotoLab.php`)

## [0.3.0] - 2026-06-15

This release continues hardening the framework — informed by a close reading of
Symfony's value-resolver, Doctrine-bridge, and security flows — and renames the
entity route binding. Several entries are **behaviour changes** marked ⚠️; a
request, attribute, or call that previously worked may now behave differently.
Read the ⚠️ items before upgrading.

### Security

- **Output escaping now fails closed.** `Str::esc()` (and the `$this->esc()`
  template helper) previously returned the value **unescaped** when given an
  unrecognised context, so a typo in the context name (`'atrr'`, `'URL'`) was a
  silent XSS hole. An unknown context now throws `InvalidArgumentException`.
  (`src/Toolkit/Str.php`, `src/Template/Template.php`)

- **`UploadedFileErrorHandler::isImage()` no longer accepts SVG.** SVG can carry
  embedded scripts; validating an avatar with `isImage()` and serving it inline
  was a stored-XSS vector. The set is now jpeg/png/gif/webp. Allow SVG explicitly
  with `hasMimeType('image/svg+xml')` only after sanitising. (`src/Http/UploadedFileErrorHandler.php`)

- **Sessions are invalidated when security-relevant user state changes.** On each
  request the session user is reloaded via the provider; the session is now
  dropped if roles or password changed (or `EquatableInterface::isEqualTo()`
  reports a change). Revoking a role or rotating a password now takes effect on
  the user's next request instead of persisting until the session expires.
  (`src/Core/AppSecurity.php`)

- **Passwords are transparently rehashed on login.** After a successful
  form/basic login, an outdated hash is upgraded when the provider implements the
  new `PasswordUpgraderInterface`. Raising the argon2 cost or changing the
  algorithm now migrates existing users automatically.
  (`src/Security/Authenticator/FormLoginAuthenticator.php`, `BasicAuthenticator.php`)

- **HTTP Basic auth can be throttled.** `BasicAuthenticator` accepts an optional
  `BruteForceProtectionInterface` (third argument), and an unknown vs. wrong-
  password attempt now returns the same generic message to avoid user
  enumeration. (`src/Security/Authenticator/BasicAuthenticator.php`)

### Changed

- ⚠️ **`#[FindEntity]` is renamed to `#[MapEntity]`** (attribute, resolver, and
  docs), matching Symfony's naming. The resolver now throws a 404
  (`ResourceNotFoundException`) when the entity is not found **and the parameter
  is non-nullable**, and returns `null` when the parameter is nullable (`?Post`).
  **Update all `#[FindEntity]` usages and any `?` type hints that relied on a
  silent null.** (`src/Attributes/MapEntity.php`, `src/Resolver/MapEntityResolver.php`)

- ⚠️ **`#[IsGranted]` is now AND across stacked attributes, OR within one.**
  Previously every role from class- and method-level `#[IsGranted]` was flattened
  into one OR list, so a method-level `#[IsGranted('ROLE_USER')]` *widened* a
  class-level `#[IsGranted('ROLE_ADMIN')]` — a privilege-escalation footgun. Each
  attribute is now an independent requirement (all must pass); multiple roles in
  a single attribute remain alternatives. **Review controllers that stack
  `#[IsGranted]` on the class and method.** (`src/Routing/Loader/AttributeClassLoader.php`,
  `src/Core/AppSecurity.php`)

- ⚠️ **Authenticated-but-forbidden now returns 403, not 401.** Insufficient roles
  and IP-restriction failures throw the new `AccessDeniedException` (403) instead
  of `AuthenticationException` (401). With a firewall entry point, an
  *unauthenticated* request still redirects to login; an authenticated user who
  lacks a role now gets a hard 403 instead of being bounced to the login page.
  (`src/Core/AppSecurity.php`, `src/Exception/ExceptionHandler.php`)

- ⚠️ **`Str::esc()` throws on an unknown context** (see Security). A context name
  that is not one of `html`, `attr`, `js`, `css`, `url` now raises
  `InvalidArgumentException` instead of returning the raw string.

- **I18n locale/fallback no longer leak across worker requests.** `I18n::locale()`
  and `fallbacks()` previously overwrote their configured Closure with the first
  request's resolved value, pinning every later request on a long-running worker
  to that locale. They now resolve into a local and never mutate the static.
  (`src/Toolkit/I18n.php`)

### Added

- **`#[MapQueryParameter]`** and `MapQueryParameterResolver` — bind a single query
  parameter to a primitive argument (`int`, `float`, `bool`, `string`, `array`,
  a `BackedEnum`, or a `Uid`), coerced with `filter_var()`. Complements the
  object-mapping `#[MapQueryString]` / `#[MapFilter]`.
  (`src/Attributes/MapQueryParameter.php`, `src/Resolver/MapQueryParameterResolver.php`)

- **`DefaultValueResolver`** — terminal pipeline stage that fills any unresolved
  parameter with its signature default, or `null` when nullable. Nullable
  arguments with no default previously caused an `ArgumentCountError` when no
  resolver matched. (`src/Resolver/DefaultValueResolver.php`)

- **`EntityUserProvider`** — reusable Doctrine-backed user provider
  (load/refresh/supports + `PasswordUpgraderInterface`), so apps no longer need to
  hand-roll it on a repository. (`src/Security/User/EntityUserProvider.php`)

- **`PasswordUpgraderInterface`** — opt-in interface for transparent rehash-on-
  login (see Security). (`src/Security/User/PasswordUpgraderInterface.php`)

- **`AccessDeniedException`** — 403 exception for authenticated-but-forbidden
  access, distinct from the 401 `AuthenticationException`.
  (`src/Security/Exception/AccessDeniedException.php`)

- **`#[MapEntity]` lookup options** — `mapping` (route param → entity field),
  `exclude`, `stripNull`, `class`, and a custom `message` for the 404.
  (`src/Attributes/MapEntity.php`, `src/Resolver/MapEntityResolver.php`)

### Fixed

- **`#[MapEntity]` null-id criteria bug.** When no `id` was available the resolver
  still merged `'id' => null` into the criteria, querying `WHERE id IS NULL` and
  never matching — which broke the documented slug lookup. The `id` is now only
  added when present, and empty criteria raise a clear `LogicException`.
  (`src/Resolver/MapEntityResolver.php`)

- **OAuth `last_used_at` write amplification.** `OAuthService::validateAccessToken()`
  flushed a timestamp update on every authenticated API request; the write is now
  throttled (≥ 60s between updates). (`src/Security/OAuth/OAuthService.php`)

- **`F::move()` is null-safe on `stat()` failure.** A failed `stat()` (e.g. a
  race) no longer reads `false['dev']`; the move falls through to copy-and-unlink.
  (`src/Toolkit/F.php`)

- **`UploadedFileErrorHandler::getStoredFilePath()`** return type corrected to
  `?string` (it is `null` before `saveTo()`). (`src/Http/UploadedFileErrorHandler.php`)

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
