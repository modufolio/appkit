# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.13.0] - 2026-08-28

### Upgrading

- **The cache directory is now namespaced by environment.** `cacheDir()`
  resolves to `var/cache/{env}` (`var/cache/prod`, `var/cache/dev`, …) instead
  of `var/cache`, so switching `APP_ENV` on one machine can never serve a cache
  another environment built. The compiled router cache moves with it; delete
  the stale `var/cache/router` directory on deploy. (`src/Core/Kernel.php`)

- **A controller that is not wired in `config/controllers.php` now logs a
  warning** before falling back to reflection-based resolution. Reflection is a
  safety net, not a wiring strategy — a constructor parameter with a default
  value silently receives that default instead of the service you meant. The
  behaviour is unchanged; the miss is simply no longer invisible. Expect these
  warnings in the log until every controller is declared.
  (`src/Core/Kernel.php`)

- **PHP no longer prints errors into the response stream.** `boot()` now calls
  `Debug::enable()` in dev (warnings and notices are thrown as
  `\ErrorException` and routed through `ExceptionHandler`) and
  `Debug::harden()` in prod (`display_errors` forced off for web SAPIs). A
  warning previously became the first output byte, committing a 200 and losing
  the real response's status and headers to "headers already sent". Dev code
  that relied on a notice being ignored will now surface as a 500 — which is
  the point. Test is left untouched so PHPUnit keeps its own error handling.
  (`src/Core/Debug.php`)

### Added

- **`config/services.php` and the `ServiceConfigurator`** — one fluent place to
  declare application services, replacing the split between `interfaces.php`
  (kernel-bound closures) and `factories.php` (container-parameter closures):

  ```php
  return function (ServiceConfigurator $services): void {
      $services
          ->set(Mailer::class, fn (App $app) => new Mailer($app->entityManager()))
          ->shared(JsonApiRegistry::class, fn (App $app) => new JsonApiRegistry(...))
          ->alias(SharedPropsInterface::class, DefaultProps::class);
  };
  ```

  Every factory receives the application as its only argument, so definitions
  no longer depend on `$this` binding at `require` time. `set()` runs the
  factory on every `get()`; `shared()` caches the first resolution in the
  kernel's per-request instance table (cleared by `reset()`); `alias()` points
  one id at another. Wire it with `configureServices()` before `boot()`,
  alongside `configureSecurity()`. Definitions take precedence over everything
  else in the container, so an application can override a kernel core service
  by re-declaring its id. (`src/DependencyInjection/ServiceConfigurator.php`,
  `docs/dependency-injection.md`)

- **The kernel wires its own core services.** `coreServices()` supplies the
  container defaults every application repeated by hand — router, session,
  entity manager, CSRF token manager, serializer, validator, user provider,
  user checker, password hasher, request, response factory, flash bag,
  environment, debug stack. `config/services.php` therefore only declares what
  the application adds. A mapped legacy `config/interfaces.php` still replaces
  this map entirely, so existing applications keep working unchanged.
  (`src/Core/Kernel.php`)

- **"Sign in with Google".** `GoogleOAuthClient` builds the authorization
  redirect, exchanges the code, and verifies the returned ID token — RS256
  signature against Google's published keys, plus issuer, audience, expiry and
  `email_verified`. `GoogleAuthenticator` runs the callback leg: it checks the
  one-time OAuth `state`, requires a verified email, and maps it onto an
  **existing** user. It never provisions an account — an address Google vouches
  for that nobody here owns is a failed login, not a new user — so who may sign
  in stays governed by who you added. `allowed_hosted_domain` gates on the
  Workspace `hd` claim as a second check. Every OAuth failure collapses to one
  message. (`src/Security/OAuth/Google/`,
  `src/Security/Authenticator/GoogleAuthenticator.php`, `docs/authenticators.md`)

- **`F::safeFilename()` — confine an untrusted string to a single filename.**
  Strips any directory component (Windows separators included) and control
  characters, rejects `.` and `..`, and returns `''` when nothing usable
  survives. Unlike `safeName()` it preserves case and the remaining
  characters, so a token already written to disk still matches and a display
  name is not mangled. (`src/Toolkit/F.php`, `docs/toolkit.md`)

- **`Kernel::cacheDir()`** — the environment-namespaced cache directory, for
  application code that needs to place a cache next to the framework's.

### Fixed

- **TOTP enrolment generated a 103-character secret.** `TotpService` used
  otphp's 64-byte default, dense enough to hurt QR scanning, painful to type by
  hand, and no stronger — HMAC-SHA1 folds anything over its block size back to
  160 bits. It now generates 20 bytes (RFC 4226 §4), via an injectable
  `secretBytes` floored at 16. Existing enrolled secrets are read as-is and are
  unaffected. (`src/Security/TwoFactor/TotpService.php`)

### Changed

- **`psr/http-client` is now a required dependency**, used by the Google OAuth
  client for the token exchange and key fetch.

## [0.12.0] - 2026-08-27

### Upgrading

- **Generated JSON:API write endpoints now require authentication by
  default.** An entity that exposes `create`/`update`/`delete` routes but
  declares no write roles gets `IS_AUTHENTICATED` stamped on the write methods
  — an unconfigured resource is no longer a silently ungated write endpoint
  (the shape behind this year's Backpack/Winter advisories). Deliberately
  public writes must now say so: `'roles' => ['read' => [], 'write' => []]`.

- **Anonymous state-changing requests on public paths need a CSRF token.** On
  session-backed firewalls, a POST to a `publicPath()` route is CSRF-checked
  like any authenticated request — an anonymous session (guest cart, wizard)
  is just as forgeable cross-site. Public forms must render the firewall
  token; webhook-style receivers opt out per firewall via `csrf => false`, a
  `csrf_validator`, or a stateless firewall.

- **`{two_factor_path}/cancel` is POST-only and CSRF-checked.** Cancelling a
  pending 2FA login wipes session state, so a cross-site GET (an `<img>` tag
  sufficed) must not reach it. Cancel links must become forms carrying the
  firewall CSRF token; a GET now just bounces to the entry point with the
  pending token intact.

### Added

- **`csrf_form_tokens` — accept Symfony-form-shaped CSRF tokens
  declaratively.** A form named `contact` posts its token as
  `contact[_token]`, keyed by the form type's `csrf_token_id` — invisible to
  the kernel's flat `_csrf_token` extraction, and until now expressible only
  as a `csrf_validator` closure. A firewall now declares the pairs instead:
  `'csrf_form_tokens' => ['contact' => 'contact_form']`. Validated as an
  additional accepted proof; the header and `_csrf_token` checks still run
  when no declared shape matches. (`docs/security.md`)

- **`csrf_delegated_paths` — hand the CSRF check to a form layer, per path.**
  A controller that validates its own token (a Symfony form with form-level
  CSRF, a component with a per-form token id) can now be declared instead of
  worked around: paths listed in the firewall's `csrf_delegated_paths` skip
  the kernel check so the form layer answers with its own failure shape (a
  re-rendered form with a field error, a 422) rather than the kernel's hard
  403 — the right response for a public form whose visitor's session expired
  mid-compose. Same pattern syntax as firewall `pattern`; everything else on
  the firewall keeps the kernel check. Previously this took a `csrf_validator`
  closure per request shape. (`docs/security.md`)

- **Per-operation JSON:API roles.** `roles` accepts a split shape next to the
  flat list: `['read' => ['ROLE_USER'], 'write' => ['ROLE_ADMIN']]` becomes
  method-scoped `_is_granted_roles` groups (`GET|HEAD` vs
  `POST|PUT|PATCH|DELETE`), so "readable by users, writable by admins" no
  longer forces over-granting writes or over-protecting reads. Declared via
  the fluent `roles()` (json-api ≥ 0.7) or `setResourceConfig()`; unknown
  keys throw in debug.
  (`src/Routing/Loader/JsonApiRouteLoader.php`, `docs/routing.md`)

### Fixed

- **SVG dimension probing parsed uploads with an unhardened XML parser.**
  `Dimensions::forSvg()` slurped the whole file and called
  `simplexml_load_string()` with no flags, and left
  `libxml_use_internal_errors(true)` leaked into the process. It now mirrors
  `Mime::fromSvg()` — 1 MB read cap, `LIBXML_NONET` (and never
  `LIBXML_NOENT`), error state restored in `finally`.
  (`src/Image/Dimensions.php`)

- **The focal-point crop was the one ImageMagick argument built without
  `escapeshellarg()`.** `Focus::coords()` only returns ints, so it was not
  injectable — but `crop` originates from a URL-parsed filename, and this was
  the single branch relying on that invariant from a distance. The geometry
  is now escaped like every other argument. (`src/Image/Darkroom/ImageMagick.php`)

### Changed

- **`modufolio/json-api` dev dependency raised to `^0.6.0 || ^0.7.0`.** 0.7.0
  adds the fluent read/write roles split and row-level `scope()`; the test
  suite runs on either.

## [0.11.0] - 2026-08-27

### Upgrading

- **Every existing image URL changes once on deploy**, since the variant
  directory is now derived from the uploads-relative path and the master's
  mtime. Variants rebuild on demand, but anything holding the old URLs outside
  the app — feeds, sent email, CDN config — stops resolving.

- **JSON:API endpoints now enforce the roles they declare.** The `roles` key
  was read but never applied, so those entities were open to any authenticated
  user. Clients relying on that gap start getting 403s — check each entity's
  `roles` says what you meant while it went unenforced.

### Fixed

- **Generated JSON:API routes ignored the entity's declared roles.**
  `JsonApiRouteLoader` read `roles` from the entity configuration for
  validation but never wrote it to the routes it built, so every generated
  endpoint — including `create`, `update` and `delete` — was reachable by any
  authenticated user regardless of what the configuration declared. The roles
  are now written to each of the entity's routes as `_is_granted_roles`, the
  same default `#[IsGranted]` sets, so `AccessDecisionEngine::enforceRoleGroups()`
  applies the role hierarchy before the controller runs. They are normalised
  into a single group, which the engine ORs — any one of the listed roles
  grants access, matching how the key reads.
  (`src/Routing/Loader/JsonApiRouteLoader.php`)

- **Media URLs changed whenever the project moved, and never changed when the
  image did.** `Storage` derived the variant directory from
  `md5($file->root())` — the absolute path — so a deploy path differing from
  the developer's, or a plain directory move, silently rewrote every media URL
  on the site. Because the hash covered only the path, the opposite also held:
  masters are rewritten in place on upload (downscaled, auto-oriented) without
  the URL changing, so a client or CDN holding the previous response kept
  serving the superseded image. The segment is now derived from the
  uploads-relative path plus the master's modification time — stable across
  installations, and new bytes produce a new URL, so variants can be cached
  indefinitely. Existing URLs change once on deploy.
  (`src/Image/Storage.php`)

- **`#[MapEntity]` added the route's `id` to a mapped lookup.** The resolver
  appended `id` to the criteria unconditionally, so on a nested route such as
  `/parent/{uuid}/child/{id}` the parent argument was constrained by the
  child's id and resolved to nothing — a 404 with no indication why. A mapping
  states which route parameters identify the entity, so the route's `id` is
  only used when the attribute declares no mapping. `criteria` is unaffected:
  it holds fixed constraints such as `['status' => 'published']` rather than
  identifying a record, and still combines with the route's id.
  (`src/Resolver/MapEntityResolver.php`)

### Changed

- **`phpstan/phpstan-phpunit` analyses the test suite.** PHPUnit's assertions
  now narrow types for static analysis, so a guarded value no longer needs a
  hand-written `is_int()` check to satisfy level 8, and an assertion that can
  never pass is reported rather than sitting green forever. That last rule
  found three: `Handler::decode()` was documented `@return array<string, mixed>`
  while a decoded scalar legitimately yields a list, and two `testParse()`
  docblocks in the query tests claimed a string-keyed array against providers
  supplying lists. The extension's style rules (`rules.neon`) are deliberately
  not included, and its redundant-`assertInstanceOf` rule is ignored under
  `tests/` — those assertions still fail at runtime when an implementation
  violates its declared type, which mocks and proxies do.
  (`phpstan.php`, `composer.json`, `src/Data/Handler.php`)

## [0.10.1] - 2026-08-21

### Added

- **`config/controllers.php` entries may be keyed by constructor parameter
  name**, in which case the dependencies are passed as named arguments and
  their order in the file no longer matters. Prefer this for controllers with
  several dependencies: a positional list that drifts out of sync with the
  constructor transposes the arguments silently whenever the mismatched
  parameters share a type, while an unrecognised key fails loudly with
  `Unknown named parameter`. Plain positional lists keep working unchanged.
  (`docs/dependency-injection.md`)

### Fixed

- **Controller arguments are matched by name, not by position.** The resolver
  pipeline keys its results by parameter name and fills them in resolver
  order, so `AssociativeArrayResolver` supplied the route parameters before
  `TypeHintResolver` supplied the request. Spreading that array with
  `array_values()` then handed argument #1 whatever resolved first — a
  controller like `handle(ServerRequestInterface $request, string $slug)`
  behind a `/{slug}` route failed with a `TypeError` before its body ran.
  Arguments are now spread by name, which is order-independent, and an
  unknown key raises `Unknown named parameter` instead of silently shifting
  every argument along. `ReflectionControllerArgumentResolver` likewise keys
  constructor dependencies by parameter name; hand-written
  `config/controllers.php` lists remain positional.
  (`src/Core/Kernel.php`,
  `src/DependencyInjection/ReflectionControllerArgumentResolver.php`)

- **`isViewable()` was never actually asserted.** `ImageTest::testIsViewable()`
  called `isResizable()` twice, so the viewable-type check had no coverage at
  all — hidden behind a skip for a missing HEIC fixture, which is now
  committed. (`tests/Unit/Image/ImageTest.php`)

- **Composer no longer warns about four test classes.** `mocks.php` declared
  `CustomHandler` under a name PSR-4 could not map, `CameraTest` sat in
  `tests/Unit/Image` while declaring `Tests\Unit\Unit\Image`, and the
  `F::load()` fixtures are deliberately non-PSR-4. The first two are corrected
  and every fixture directory is now excluded from the classmap.
  (`composer.json`, `tests/Unit/Data/CustomHandler.php`,
  `tests/Unit/Image/CameraTest.php`)

### Changed

- **CI runs the darkroom and brute-force suites instead of skipping them.**
  24 tests were skipping for want of a runtime dependency, so ImageMagick
  processing, Redis-backed lockout and the HEIC paths went unverified. The
  workflow now installs ImageMagick and runs a `redis:7-alpine` service with
  the phpredis extension.

  Note for consumers packaging their own images: `Darkroom\ImageMagick`
  defaults to `bin => 'magick'`, which exists in ImageMagick 7 but **not** in
  the ImageMagick 6 that Debian and Ubuntu package — there the binary is
  `convert`. Set the `bin` option accordingly. `ImageMagickTest` now resolves
  whichever is present, so the suite covers both.
  (`.github/workflows/ci.yml`,
  `tests/Unit/Image/Darkroom/ImageMagickTest.php`)

### Docs

- CI status and PHPStan level 8 badges in the README.

## [0.10.0] - 2026-08-20

### Upgrading

- `DarkroomInterface` gained `preprocess(string $file, array $options = []): array`.
  Drivers extending `Darkroom` inherit it and need no change. Only classes
  implementing the interface directly must add the method. This lets a consumer
  regenerating a variant from a stored job call `preprocess()` through the
  interface instead of asserting a concrete driver.
  (`src/Image/DarkroomInterface.php`)

- `ApplicationStateInterface` gained `getVarDir(): string`. Classes extending
  `AbstractApplicationState` inherit it and need no change. Only classes
  implementing the interface directly must add the method — return the
  directory your app writes sessions and caches to (`$baseDir.'/var'` matches
  the previous hard-coded behaviour). (`src/Core/ApplicationStateInterface.php`,
  `src/Core/AbstractApplicationState.php`)

### Added

- **A configurable writable runtime directory.** `baseDir` previously did
  double duty as both the application root *and* the root of everything the
  framework writes — sessions, Doctrine proxies, the router and metadata
  caches were all hard-coded to `$baseDir/var`. `Kernel::varDir()` now
  resolves that path and `Kernel::setVarDir()` overrides it, so an app
  deployed with a read-only project root, or one running several processes
  against a shared checkout, can point runtime state elsewhere. The default is
  unchanged (`$baseDir/var`), so existing apps behave exactly as before.
  (`src/Core/Kernel.php`, `src/Core/AbstractApplicationState.php`,
  `src/Core/NativeApplicationState.php`, `src/Doctrine/EntityManagerFactory.php`)

- **Parallel test runs via ParaTest.** `composer test:par` runs the suite
  across worker processes using `phpunit.parallel.xml.dist`; `composer test`
  stays serial and keeps `stopOnFailure` for debugging. Each worker scopes its
  own `var/test/<TEST_TOKEN>` directory through the new `varDir`, so sessions
  and proxies can't collide between workers. (`phpunit.parallel.xml.dist`,
  `bootstrap.php`, `composer.json`)

### Changed

- **PHPStan now runs at level 8** (was level 5), with the type annotations and
  null-handling that entails applied across `src/` and `tests/`. Most changes
  are documentation-only (`@param`/`@return` array shapes, `@var` on
  properties), but several genuine null-safety gaps in the console and
  Doctrine maker code were tightened along the way. (`phpstan.php`)

- **`AppInterface` extends `ParameterAccessorInterface`**, splitting parameter
  access (`getParameter()`/`hasParameter()`/`setParameter()`) into its own
  contract that services can depend on without pulling in the whole
  application interface. (`src/Core/AppInterface.php`,
  `src/DependencyInjection/ParameterAccessorInterface.php`)

### Fixed

- **A corrupted or truncated security token no longer emits a PHP warning.**
  The serialized token reaching `TokenUnserializer::create()` comes from a
  session record or a remember-me cookie — both attacker-controllable — and
  `unserialize()` signals malformed input with an `E_WARNING` and a `false`
  return rather than an exception, so the surrounding `catch` never saw it and
  every garbage payload wrote to the error log. The warning is now converted
  at the call site and drives the existing `null` path. Following Symfony's
  `ContextListener::safelyUnserialize()`, only diagnostics originating in this
  file are captured and the previous error handler is chained, so a warning
  raised inside a token's own `__wakeup()`/`__unserialize()` still surfaces.
  (`src/Security/TokenUnserializer.php`)

- **Test suite fixtures and configuration.** `displayDetailsOnTestsThatTriggerWarnings`
  and `displayDetailsOnSkippedTests` are enabled so warnings and skips name
  themselves in the run output, and the missing
  `onigiri-adobe-rgb-gps.jpg` ImageMagick fixture has been restored.
  (`phpunit.xml.dist`, `tests/Unit/Image/fixtures/image/`)

## [0.9.0] - 2026-08-19

### Security

- **Firewall selection now honours a firewall's `methods`, `host` and `ips`
  restrictions, not just its `pattern`.** Previously
  `getFirewallName()`/request resolution matched on pattern alone, so a
  firewall scoped to e.g. `methods: [GET, HEAD]` was still selected for a
  `POST` — silently applying the wrong firewall's (looser) security to a
  mutating request. A new request-aware
  `getFirewallNameForRequest(ServerRequestInterface $request)` checks pattern
  *and* every declared restriction, falling through to the next matching
  firewall (typically the authenticated one) when a restriction doesn't
  match, the way Symfony does. `getFirewallName(string $path)` remains as a
  pattern-only convenience for callers that only have a path (URL generation,
  tooling). (`src/Core/AbstractApplicationState.php`,
  `src/Core/ApplicationStateInterface.php`)

### Added

- **Persistent (database-backed) remember-me tokens with cookie-theft
  detection.** Opting a `RememberMeAuthenticator` into a
  `RememberMeTokenProviderInterface` switches it from a stateless signed
  cookie to a series + rotating-value token stored server-side: the value is
  rotated on every use, and a known series presented with a stale value is
  treated as unambiguous theft — every token for that user is revoked and a
  new `CookieTheftException` is thrown. Ships with `FileTokenProvider` and
  `InMemoryTokenProvider` implementations. (`src/Security/RememberMe/`,
  `src/Security/Authenticator/RememberMeAuthenticator.php`,
  `src/Security/Exception/CookieTheftException.php`)

- **Trust-level authorization attributes** (`IS_AUTHENTICATED_FULLY`,
  `IS_IMPERSONATOR`, …), decided by a new `RoleAttributeEvaluator` shared by
  both path-based access-control rules and `#[IsGranted]` route groups, so
  the two can't drift apart. A group that needs a stronger authentication
  than the current token provides (e.g. `IS_AUTHENTICATED_FULLY` while on a
  remember-me cookie) now asks the user to step up instead of hard-denying.
  (`src/Security/AccessControl/RoleAttributeEvaluator.php`,
  `src/Security/AuthenticationTrustResolver.php`)

- **`#[IsGranted]` accepts a `methods` option**, scoping the check to
  specific HTTP methods on a route that serves more than one — e.g. leaving a
  `GET` open while requiring a role on the same route's `POST`. Listing `GET`
  implicitly covers `HEAD`. (`src/Attributes/IsGranted.php`,
  `src/Routing/Loader/AttributeClassLoader.php`,
  `src/Security/AccessControl/AccessDecisionEngine.php`)

- **`AccessDecisionEngine`** consolidates access-control matching
  (path/method/host/IP/channel/role constraints) that was previously
  duplicated between `AbstractApplicationState` and `AppSecurity` into one
  rule/constraint model. (`src/Security/AccessControl/`)

- **`debug:firewall` and `security:validate` console commands** — the former
  lists configured firewalls, their scoped access-control rules, and the role
  hierarchy (or details a single named firewall); the latter checks the
  security configuration for common mistakes.
  (`src/Command/FirewallDebugCommand.php`,
  `src/Command/SecurityValidateCommand.php`)

- **`AppAwareInterface`**, implemented by `AbstractController`, for
  controllers (or any kernel-instantiated object) that want the application
  handed to them right after construction without extending
  `AbstractController`'s full set of injected services.
  (`src/Core/AppAwareInterface.php`, `src/Core/AbstractController.php`)

- Generated entities now get an internal auto-increment `id` plus an
  external-facing `uuid` (v7, set in the constructor), so the auto-increment
  value doesn't need to be exposed outside the app. The Doctrine maker's
  column-type-to-PHP-type inference was also synced with upstream, falling
  back to reflecting a custom DBAL type's `convertToPHPValue()` return type
  when it isn't one of the built-in types. (`src/Console/Doctrine/`,
  `src/Console/Resources/skeleton/doctrine/Entity.tpl.php`)

### Fixed

- Excluded `tests/Unit/Util/fixtures/` from the Composer classmap so fixture
  files (invalid/incomplete PHP by design) no longer break classmap
  generation. (`composer.json`)

### Docs

- Documented persistent remember-me tokens, the new security console
  commands, and security configuration options. (`README.md`,
  `docs/authenticators.md`, `docs/console.md`, `docs/security.md`)

- Documented the `#[IsGranted]` `methods` option. (`docs/index.md`,
  `docs/routing.md`)

## [0.8.0] - 2026-08-15

### Upgrading

- `AppInterface` gained `csrfTokenManager(): CsrfTokenManagerInterface`. Apps
  extending `Kernel` inherit a working default (a per-call
  `new CsrfTokenManager($this->session())` — stateless, so nothing to reset)
  and most apps already declared an identical memoized override, which now
  simply takes precedence. Only classes implementing `AppInterface` *without*
  extending `Kernel` must add the method.

### Fixed

- **A stale remember-me cookie no longer flashes "Invalid credentials." on
  every request.** The cookie is an ambient credential — the browser presents
  it on its own — so when it stops validating (expired, password changed,
  `secret` rotated) the firewall treated every page load as a failed login and
  greeted anonymous visitors with an error until the cookie expired. A dead
  cookie is now expired on the response (`Max-Age=0`) and the request continues
  anonymously: no flash, remaining authenticators still run, public paths stay
  reachable. (`src/Core/AppSecurity.php`)

- **A freshly issued remember-me cookie can no longer be clobbered by the
  expiry of a stale one.** When a stale cookie and a successful opt-in login
  meet in the same request, both a clearing header and a new cookie are sent —
  and with duplicate `Set-Cookie` headers for one name the browser honors the
  last. The clearing header is now always attached before the fresh cookie.
  (`src/Core/AppSecurity.php`)

### Security

- **Authentication failures now flash the exception's `getMessageKey()` instead
  of a hardcoded string — with account-status detail deliberately hidden.**
  `getMessageKey()` is the user-safe half of the exception contract:
  `getMessage()` may carry internal detail for logs. CSRF and brute-force
  failures now surface their own accurate messages, while
  locked/disabled/expired accounts (`AccountStatusException`) read as
  "Invalid credentials.", so a login response never confirms that an account
  exists. The original exception is preserved as `getPrevious()` for logging.
  (`src/Core/AppSecurity.php`, `src/Security/Authenticator/FormLoginAuthenticator.php`)

- **A failed interactive login also expires any remember-me cookie riding along
  on the request.** (`src/Core/AppSecurity.php`)

### Added

- **`TooManyLoginAttemptsException`** — thrown by the brute-force throttle in
  `FormLoginAuthenticator`, with a display-safe message key ("Too many failed
  login attempts. Please try again later."). Previously the throttle threw a
  generic `AuthenticationException`. (`src/Security/Exception/TooManyLoginAttemptsException.php`)

- **`InvalidCsrfTokenException::getMessageKey()`** — "Invalid CSRF token.",
  so CSRF failures no longer masquerade as bad credentials.
  (`src/Security/Exception/InvalidCsrfTokenException.php`)

- **`AppInterface::csrfTokenManager()` with a `Kernel` default** — the firewall
  (logout CSRF check, state-changing CSRF enforcement, login token rotation)
  now calls it directly instead of `get()` + `assert()` against the container.
  (`src/Core/AppInterface.php`, `src/Core/Kernel.php`, `src/Core/AppSecurity.php`)

- Docs: new [Authentication failure behaviour](docs/security.md) section
  (interactive vs ambient failures, the `getMessageKey()` contract, enumeration
  guards) and a remember-me subsection on what happens when the cookie stops
  validating. (`docs/security.md`, `docs/authenticators.md`)

### Changed

- **`FormLoginAuthenticator` throws specific exception subclasses.** Credential
  failures (wrong password, unknown user, empty fields) throw
  `BadCredentialsException`; unknown users stay deliberately indistinguishable
  from wrong passwords. `unauthorizedResponse()` flashes `getMessageKey()`,
  replacing the private `publicErrorMessage()` map — the CSRF flash text
  changes from "Invalid security token. Please try again." to
  "Invalid CSRF token.". (`src/Security/Authenticator/FormLoginAuthenticator.php`)

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
