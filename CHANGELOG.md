# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.7.0] - 2026-08-07

### Upgrading

- `bootstrap.php` must now publish the environment, or `.env` is never read and
  every variable silently falls back to its default:

  ```php
  (new Env())->fromFile(__DIR__ . '/.env')->freeze();
  ```

### Security

- **`env()` returned the string `"false"` as a truthy value.** The documented
  `"true"`/`"false"` boolean cast only ran for values parsed out of the `.env`
  file, so a real environment variable — `COOKIE_SECURE=false` via
  `fastcgi_param`, a container env, or `$_SERVER` — came through as the string
  `"false"`, which is truthy. Any caller writing `(bool) env('COOKIE_SECURE')`
  silently got `true`. Casting now applies to every source. (`src/Core/Env.php`)

- **The remember-me cookie no longer drifts from the session cookie.** Its
  `cookie_secure` option was hardcoded while the session cookie read
  `COOKIE_SECURE`, so the two could disagree about HTTP vs HTTPS. Both now read
  the same variable, matching how Symfony's `RememberMeFactory` inherits the
  flag from `framework.session`. (`docs/security.md`)

### Added

- **`Env`, a typed reader for environment variables.** `env()` with no arguments
  returns it: `getBool()`, `getInt()`, `getFloat()`, `getString()`, `has()`, and
  `getRequired()` for secrets, modelled on Symfony's env var processors. A value
  that cannot be read as the requested type raises rather than being coerced to
  `0`/`false`, so a typo in `.env` fails at boot instead of quietly disabling a
  setting. (`src/Core/Env.php`)

### Changed

- **A malformed `.env` now fails at boot instead of vanishing.** `parse_ini_file()`
  rejects the entire file on one bad line, and the previous code swallowed that
  into an empty result — so a single unquoted newline dropped every variable and
  resurfaced as a misleading "required variable is not set" for a secret that was
  present in the file. The parse error is now thrown, naming the file and the
  offending line. `export FOO=bar` is also handled: the prefix used to end up in
  the key name (`"export FOO"`), making the variable unreachable. (`src/Core/Env.php`)

- **The environment is loaded explicitly and frozen.** `bootstrap.php` now calls
  `(new Env())->fromFile(__DIR__ . '/.env')->freeze()`; `Env` no longer sniffs
  the `BASE_DIR` constant to find the file. Chain `fromFile()` to layer files
  (later wins) before freezing — afterwards the reader is immutable and
  published process-wide. A process without a bootstrap still resolves `$_ENV`
  and `$_SERVER`. `env('KEY')` is unchanged. (`src/Core/Env.php`, `bootstrap.php`)

## [0.6.1] - 2026-08-03

### Security

- **Encoded paths could bypass the firewall.** The Symfony URL matcher
  `rawurldecode()`s the path before routing, but the security layer matched the
  raw, still-encoded path — so `GET /%61pi/me` slipped past a firewall guarding
  `/api` yet still reached the controller. All security-side path reads now go
  through a `securityPath()` helper that decodes exactly as the router does.
  (`src/Core/AppSecurity.php`)

- **A broad `publicPath()` could waive login for a stricter firewall.** Since
  `/` prefix-matches every path, `publicPath('/')` for a public site also
  disabled the login redirect for `/panel/*` on a separate firewall. Rules can
  now be scoped with a `firewall` option (see below). (`src/Core/AppSecurity.php`,
  `src/Security/SecurityConfigurator.php`)

### Added

- **Scope a public/access-control rule to one firewall.** Pass
  `['firewall' => 'site']` to `publicPath()` or `accessControl()` so a broad
  pattern cannot leak its exemption into another firewall. Unset, nothing
  changes.

## [0.6.0] - 2026-08-02

### Added

- **Public pages inside a firewall: `publicPath()`.** Access-control rules
  could only add restrictions; there was no way to say "this path needs no
  login". Making one page public meant a second firewall with
  `security => false`, which also switched off the authenticators and CSRF
  enforcement for it. `SecurityConfigurator` now has a `PUBLIC_ACCESS`
  constant and a shorthand: `$security->publicPath('/contact')` (any method)
  or `$security->publicPath('/feed', ['GET'])` (readable anonymously, writing
  still needs a login). Only the entry-point redirect is waived — the
  authenticators still run first, so a remember-me cookie signs the visitor in
  on a public page, and an authenticated session keeps `getUser()` and CSRF
  enforcement. The rule itself is skipped by access control, so it neither
  grants nor restricts anything; later rules still apply.

  **CSRF on a public path is the payload handler's job**, by design. The
  firewall's CSRF gate only ever runs on the restored-session and remember-me
  paths, because CSRF protects an *ambient* credential — a cookie the browser
  attaches by itself. An anonymous visitor has no such credential to ride, so
  an anonymous POST to a public path is not gated by the firewall, and the
  endpoint protects itself: a Symfony form validates its own `_token` (a form
  error, so the visitor can correct and resubmit), and a hand-written form
  checks the token in the controller. This is the same split Symfony makes —
  its firewall only validates CSRF for the login and logout actions it owns,
  never for state-changing requests at large.

- **Let a form layer answer the CSRF question: `csrf_validator`.** The
  firewall's CSRF check accepted exactly one shape: a top-level `_csrf_token`
  field (or an `X-CSRF-Token` header) validated against the firewall's single
  token id. A form library that namespaces its field (`contact[_token]`) or
  keys tokens per form was rejected before the controller ever ran — a valid
  form POST from a signed-in user got a 403, and the only escape was disabling
  CSRF for the whole firewall. The new per-firewall `csrf_validator` option is
  a `callable(ServerRequestInterface, CsrfTokenManagerInterface): ?bool`:
  return `true` to accept, `false` to reject, or `null` to fall through to the
  built-in check. It applies everywhere the firewall enforces CSRF, including
  the first request authenticated by a remember-me cookie. Unset, nothing
  changes.

### Changed

- **CSRF failures now honour the `Accept` header.** A browser posting a form
  used to get a raw JSON blob on a missing or invalid CSRF token. A client
  that prefers `text/html` now gets whatever your exception handler renders
  for `AccessDeniedException` (an error page, typically); fetch/XHR clients
  (detected via `X-Requested-With`) and API clients keep the exact
  `{"error": "invalid_csrf_token", …}` body as before, so nothing
  machine-readable changes. Preference is decided by real content negotiation
  — the same `willdurand/negotiation` the exception handler already uses, so
  the two agree on what a request wants — which means quality values decide,
  not header order: `Accept: text/html;q=0.1, application/json;q=0.9` is a
  JSON client despite listing HTML first. A request expressing no preference
  (`*/*`, or no header) gets JSON.

- **`validateToken()` answers instead of throwing.** `CsrfToken` rejects an
  empty value with an `InvalidArgumentException`, so
  `validateToken($id, $submitted)` exploded on an empty `_token` field.
  Callers pass whatever the request contained, and a missing token is an
  ordinary invalid submission — it now returns `false`. The parameter is
  widened to `?string` on both `CsrfTokenManager` and
  `CsrfTokenManagerInterface`: existing call sites keep working (a `string`
  still satisfies `?string`), but if you implement the interface yourself you
  must update your signature to
  `validateToken(string $tokenId, ?string $tokenValue): bool`.

- **`Form` validates attributes like the rest of the app.** The base `Form`
  class built a bare validator while the kernel's `validator()` enables
  attribute mapping — two validators with different capabilities in one app,
  so `#[Assert\…]` on a nested object was honoured in a controller and
  silently ignored inside a form. The form's default validator now enables
  attribute mapping too.

### Fixed

- **`Template::url()` no longer renders `":/…"` for requests without a
  scheme or host.** A request built without an absolute URI (console, tests,
  some SAPIs) made `calculateBaseUrl()` return `'://'`, so a layout emitted
  `href=":/assets/css/app.css"`. Such requests now yield root-relative URLs
  (`/assets/css/app.css`), and `url('/')` is `/`. Requests with a real
  scheme and host are unchanged.

- **`env()` no longer requires the `BASE_DIR` constant.** Calling `env()` from
  a script that doesn't define it (a one-off CLI script, a worker bootstrap, a
  test harness) was a fatal undefined-constant error instead of a lookup. It
  now falls back to `$_ENV` / `$_SERVER` and the default; when `BASE_DIR` is
  defined, the `.env` file is read exactly as before.

## [0.5.0] - 2026-08-02

### Changed

- **JSON:API routes no longer require a numeric id.** The `\d+` requirement
  on the show, update, delete, and relationship routes generated by
  `JsonApiRouteLoader` is gone, so identifiers like uuids now reach the
  controller instead of 404ing at the router. Pair this with
  `modufolio/json-api`'s uuid resolution to serve
  `GET /api/contact/{uuid}` alongside numeric ids. Numeric lookups behave
  exactly as before; if your controller relied on the router guaranteeing
  a numeric id, validate the parameter yourself (a JSON:API controller
  handling both is the expected setup).

## [0.4.1] - 2026-07-25

### Fixed

- **Logging out now also works for JavaScript apps (Inertia, Vue, fetch).**
  Before this release, logout only accepted the CSRF token as a hidden
  `_csrf_token` field in a submitted form. JavaScript apps don't submit
  forms — they send the CSRF token in the `X-CSRF-Token` header, which the
  framework already accepts on every other request. Logout was the one
  exception, so those apps always got a `401 Unauthorized` when signing out.

  Logout now accepts both: the classic form field (unchanged) and the
  `X-CSRF-Token` / `X-XSRF-Token` header. No changes needed in your app —
  if your logout form works today, it keeps working; if your logout was
  failing from JavaScript, it now just works.

## [0.4.0] - 2026-07-19

### Added

- **New command: `debug:controllers`.** Checks every controller used by
  your routes and reports whether it can actually be built by the
  container, so a broken constructor gets caught by a CLI check instead of
  by a user hitting the page. Sits alongside `debug:router`. Wire it up
  like any other command: `new ControllersDebugCommand($app, $app->router())`.

- **Commands and tests can now prime the app without a real HTTP request.**
  `initializeConsoleState()` sets up everything a controller needs (the
  same request-scoped state a normal request would create) using a
  harmless fake request — useful for CLI commands like the one above, and
  for test suites that need the container ready before making requests.

- **`getController()` is now part of `AppInterface`.** It already worked,
  it just wasn't declared on the interface, so code that only had an
  `AppInterface` (rather than the concrete app class) couldn't call it
  without an extra type check first.

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
