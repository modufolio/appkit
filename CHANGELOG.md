# Changelog

All notable changes to this project are documented here. Unreleased work and
the most recent release are in this file in full; every earlier release has its
own file under [`releases/`](releases/), listed at the bottom. Releases marked
**breaking** carry an *Upgrading* section.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **A long-lived worker kept the asset version it booted with.** The Inertia
  version from `version_file` was hashed once per process, so after an asset
  build a RoadRunner pool answered with the old and the new version at once
  and a browser met the 409 handshake on every other request. The file is now
  re-hashed whenever its mtime changes, one stat per request.

- **`listTableNames()` is deprecated in DBAL 4.** `app:info`, `AppTestCase`'s
  table drop and `DatabaseTestingCapabilities`' snapshot call
  `introspectTableNames()` and render its name objects with
  `toSQL($platform)` where they become SQL: `toString()` is ANSI-quoted,
  which MySQL reads as a string literal rather than a table. The drop loops
  ask the schema manager with `dropTable()`, as ORM's own suite does.

### Added

- **Definition sets.** `ServiceConfigurator::load()` takes the array a
  PSR-11 container consumes — id => factory closure receiving the container,
  or id => the id it aliases — or an object implementing the new
  `DependencyInjection\DefinitionsInterface` that returns one. A package
  writes its wiring once in that shape and it loads into the kernel, into
  PHP-DI, and into any container that takes closures. A definitions class may
  also be listed in `config/modules.php` in place of a module: the registry
  wraps it in `Module\DefinitionsModule`, so it takes the module's place in
  the override order and the manifest's checks without a contract it has no
  use for.
- **Autowiring, opt-in.** `configureAutowiring()` lets the container build an
  undeclared, instantiable class from its constructor types, after every
  declared source has had its say: class-typed parameters the container
  answers are resolved through it, others take their default or null, and a
  required parameter nothing can fill makes the class not found, with the
  parameter named. Plans are reflected once per process and kept. Off by
  default; a declared id always wins. For packages with typed constructors,
  which then need no definitions on this container or on Symfony's.
- **Events: the notification seam.** The kernel dispatches PSR-14 events
  saying what just happened, through whatever the application declares as
  `Psr\EventDispatcher\EventDispatcherInterface` in `config/services.php`
  (`symfony/event-dispatcher` is now a dependency) or installs with
  `Kernel::setEventDispatcher()`; a `NullEventDispatcher` until then. These
  are notifications, not hooks: each is dispatched after the state it
  reports is committed, events are `final readonly` classes of identifiers
  and names (never a user, token or secret), and `Kernel::notify()` logs a
  listener that throws and carries on, so the login that just succeeded is
  never turned into a 500 by a mail server, and refuses a kernel event
  dispatched from inside another's listener, so a listener that logs the
  user out cannot start a login/logout cycle. Every dispatch call sits
  inline in the kernel's own flow.

  Security: `UserLoggedInEvent` (with the authenticator's name, roles and
  whether a remember-me cookie did it), `LoginFailedEvent` (with the attempted
  identifier where the authenticator can say — the new
  `AttemptedIdentifierInterface`, implemented by the form and Basic
  authenticators — and the exception class), `UserLoggedOutEvent`,
  `RememberMeCookieTheftDetectedEvent`, `ImpersonationStartedEvent`,
  `ImpersonationEndedEvent`, `AccessDeniedEvent` (from path rules and `#[IsGranted]`
  alike), `TwoFactorEnabledEvent` and `TwoFactorDisabledEvent` (from `TotpService`,
  when built with a dispatcher), and `PasswordChangedEvent`, which the
  application dispatches itself since the framework never changes a
  password. Elsewhere: `Http\RequestHandledEvent` after the profiler, for every
  response; `ExceptionCaughtEvent` before the handler renders; `Http\UploadStoredEvent`
  from `Upload::saveTo()` when built with `Upload::from($file, $events)` or
  `->notifying($events)`. See [docs/events.md](docs/events.md).

- `Kernel::eventDispatcher()` and `setEventDispatcher()`; `EventDispatcherInterface`
  in the core service map, so controllers receive it by type. `ExceptionHandler`
  and `PrepareResponse` take an optional dispatcher; `TotpService` an optional
  trailing `events` argument. `CookieTheftException` carries the identifier
  of the account the replayed series belonged to.

- **Rate limiting, through symfony/rate-limiter** (now a dependency, with
  symfony/lock). `SecurityConfigurator::rateLimiter('login', [...])`
  declares a limiter with the component's own options; a firewall's new
  `rate_limit` option names one, and every credential presented to that
  firewall then counts against it per client address, before it is read —
  a remember-me cookie and an anonymous visitor presenting nothing are not
  counted. `#[RateLimit('api', by: RateLimit::BY_USER)]` on a controller or
  action throttles a route per user or per address, after access control;
  every attribute on a route applies. A spent window is a
  `RateLimitExceededException`, which the exception handler answers with
  429, `Retry-After` and `RateLimit-Limit`, after `RateLimitExceededEvent`.
  `Kernel::rateLimiter($name)` hands application code the same factory.
  Windows are counted in a file cache under `var/cache/<env>/rate_limiter`
  behind a flock; declare symfony/rate-limiter's `StorageInterface` and
  symfony/lock's `LockFactory` in `config/services.php` for a shared store.
  See [docs/security/rate-limiting.md](docs/security/rate-limiting.md).
- The exception handler applies a `headers` map a handler returns beside
  `status`, `title` and `detail`.
- **Versioned assets and Subresource Integrity.** `Template` takes an
  `Asset\AssetVersioningInterface` — `FileHashVersioning` (a content hash
  in the name or the query string, cached per process by modification
  time), `ManifestVersioning` (a Vite `manifest.json` or a flat map), or
  the default `NoVersioning` — and an `Asset\AssetIntegrity` map, and
  renders `renderCss()`/`renderJs()` with versioned URLs and `integrity` +
  `crossorigin` attributes; the new `asset()` helper versions any path.
  `assets:sri` writes the map to `config/sri.php`. The kernel gained
  `publicDir()`, `assetVersioning()` and `assetIntegrity()`, both
  declarable in `config/services.php`, and `TemplateResolver` hands them to
  `#[Template]` parameters. `Template::url()` now returns an absolute URL
  untouched, so a CDN asset can be queued. Uses a cwd-relative public path,
  per-render hashing and a fixed `render()` copy; see [docs/templates.md](docs/templates.md#versioned-assets-and-subresource-integrity)
  and the nginx rewrite under [docs/deployment.md](docs/deployment.md#versioned-assets).
- **`Debug\QueryFingerprinter`, the N+1 finder.** Reduces a request's
  queries to their shapes and reports the repeated ones with count and
  time; reads the `DebugStack`'s own records.

### Changed

- **`Template::__construct()` is `final`** and `render()` builds layouts with
  `new static`, so a subclass keeps its behaviour for layouts without
  copying `render()`. A subclass that changed the constructor signature
  must add its state through a setter or a factory instead.

### Fixed

- **A snippet called from inside a layout resolves again.** `render()` built
  the layout with the layout paths alone, and `snippet()` derives its search
  directories from the template paths, so `$this->snippet('global/meta')` in a
  layout looked under `site/layouts` and threw. The layout now keeps the
  template paths behind its own.

### Changed

- **The README and docs no longer say "no event dispatcher".** The stated
  choice is now precise: no event-driven control flow, no plugin system;
  notifications after the fact, yes.

## [0.21.0] - 2026-09-15

### Added

- **Session storage and cookie configuration.** Declare a
  `\SessionHandlerInterface` in `config/services.php` to store sessions in
  Redis, a database or anywhere else Symfony ships a handler for; the
  kernel builds it once per worker and every request's state uses it. The
  default stays PHP's file handler under `var/sessions`. Declare a
  `SessionConfiguration` to set the cookie name, `Secure`, `HttpOnly`,
  `SameSite`, path, domain and lifetime, and the server-side
  `gc_maxlifetime`. Without one the previous defaults apply, with `Secure`
  still read from `COOKIE_SECURE` — now once per process at configuration
  time rather than on every request.

- **Idle session timeout.** A firewall can declare `idle_timeout` (seconds);
  a session unused for longer is invalidated, its remember-me cookie cleared,
  and the visitor redirected to the login page with
  `SessionIdleStatus::TIMEOUT_MESSAGE` flashed as `info`. Symfony ships no
  equivalent — it offers `MetadataBag::getLastUsed()` and `gc_maxlifetime` and
  leaves enforcement to the app.

  `idle_ignore_paths` lists paths that are served **without** counting as
  activity. This is what makes a "how long have I got left?" endpoint safe:
  without it, a page polling its own session status renews the deadline it is
  reporting and the session never expires. Inject `SessionIdleStatus` to read
  the remaining seconds. Sessions that predate the setting are stamped rather
  than expired, so enabling it does not sign out everyone currently online.

### Changed

- **`Core\NativeApplicationState` is now `Core\ApplicationState`.** With the
  session handler injected, nothing native-specific remained but PHP's
  session functions. The constructor gained two optional trailing arguments,
  the session configuration and handler; code that built the state by hand
  keeps working with the previous defaults.

- **`Http\UploadedFileErrorHandler` is now `Http\Upload`.** The class
  validates and stores an upload; the old name described neither. No alias:
  rename the import and the static `from()` call.

- **`declare(strict_types=1)` in every source file**, and in the entities and
  repositories `make:entity` generates. Twenty files were still coercive.
  Two latent type slips this surfaced in `Toolkit\Collection` are fixed:
  list-style keys are cast before reaching the magic setter, and the equals
  filter accepts a separator string for `split`, as `getAttribute()` always
  did.

### Fixed

- **The documented switch-user form now works.** A `POST` carrying
  `_switch_user` and the dedicated `switch_user` CSRF token in `_csrf_token`
  was refused with a 403 before impersonation ran: the firewall-wide CSRF
  check validated that field against the session token id (`csrf`) first,
  and only `X-CSRF-Token` clients ever got through. The firewall-wide check
  now steps aside for a switch-user request, which enforces its own token
  under the `switch_user` id, exactly as logout already does. A token minted
  for another action is still refused.

- **Reordered roles no longer sign the user out.** The per-request user
  refresh compared role arrays positionally, so a provider returning the same
  roles in a different order read as a security-relevant change and dropped
  the session. Roles are now compared as a set; a revoked or added role still
  ends the session.

- **A required `#[CurrentUser]` on an anonymous request answers 401**, or
  the login redirect, instead of a `TypeError` 500. A nullable parameter
  still receives null.

- **`ValidationResult` may be declared before its payload.** The pairing
  used to work only when the result parameter followed the mapped payload;
  declared first, it was silently left null.

- **Route values are no longer truncated into an `int`.** `1.5` and `1e3`
  were cast with `(int)`, so `/posts/1.5` served post 1. Only a value that
  reads as an integer is cast; anything else is handed through for the call
  to reject. Floats and bools follow the same rule.

- **An object passed under a parameter's name is handed through.** The
  resolver wrapped an instance of the declared class in a second `new`,
  which failed for any class without a single-argument constructor.

- **`#[MapFilter]` verifies the class implements `MapFilterInterface`** with
  a real check; the previous `assert()` is compiled out in production.
  `#[MapQueryString(name: …)]` treats a scalar where the nested array is
  expected as an empty payload instead of failing in the denormalizer, and
  a non-array parsed body is treated the same way.

- **An oversized JSON body answers 413, not 500.** The PSR-7 package's
  JSON parser throws its own `PayloadTooLargeException`; the handler mapped
  only appkit's class of the same name, so the package's fell through as a
  server error. Appkit's exception now extends the package's and the handler
  registers the package class, covering both.

- **`Upload::saveTo()` checks every extension segment.**
  `shell.php.jpg` passed the denylist because only the last segment was
  read, while Apache's `mod_mime` maps each one and serves it as PHP. The
  denylist also gained `html`, `htm`, `xhtml` and `xht`, for the same
  stored-XSS reason `svg` was already on it.

- **`FlatFileRouteLoader` invalidates its cache for the configured extension.**
  The directory resource was hardcoded to `.txt`, so a loader built for
  another content extension never noticed a changed page.

- **`denyUnmatchedRequests()` no longer locks out `publicPath()` routes.**
  Deny-by-default treated a path only a PUBLIC_ACCESS rule matched as
  unmatched, so the login page and assets the docs say to declare public
  answered 401. A matching public rule now counts as explicitly allowed; a
  later non-public rule still decides as before.

- **Access-control rules honour their `firewall` option during enforcement**,
  not only when waiving the login redirect. A rule scoped to the admin
  firewall no longer restricts the site firewall.

- **An `ips` rule fails closed without `REMOTE_ADDR`.** A missing client
  address was read as `127.0.0.1`, so a loopback-only rule passed for any
  runtime that leaves the server parameter unset.

- **Logout revokes a persistent remember-me series server-side.** Only the
  clear-cookie header was sent, so a copy of the cookie taken from the
  device that logged out stayed valid for its full lifetime. The series is
  now deleted from the token provider as well.

- **Remember-me cookies: an array-valued cookie no longer causes a 500**, and
  a user identifier containing `:` now round-trips in signature mode (the
  cookie is split from the right). A rotation queued by an earlier request
  can no longer leak onto a later response from a reused authenticator.

- **`OAuthToken` survives session serialization.** It inherited the base
  serializer and lost its scopes and firewall name on restore, so any scope
  check on a session-backed OAuth firewall was a 500.

- **`ApiKeyToken` no longer persists the API key** into the session record.

- **Google sign-in: PKCE, key rotation, and two-factor.** The start action
  can pass a code verifier (`GoogleOAuthClient::generateCodeVerifier()`) and
  the callback sends it with the exchange. Google's signing keys are cached
  for an hour and re-fetched once when a token arrives under an unknown key,
  instead of failing every login on that worker until restart. With a
  `TwoFactorServiceInterface` injected, a user with TOTP enabled is sent to
  the second factor after Google vouches for the address.

- **A replayed OAuth refresh token revokes the user's whole grant set**, per
  OAuth 2.1: presenting an already-rotated token means two parties hold it.

- **`enableTwoFactor()` refuses an already-enabled secret** instead of
  silently regenerating the user's saved backup codes.

- **Brute-force protection hardening.** The file store prunes counter files
  that can hold nothing live (probabilistically on failures, and via
  `prune()` for a cron) instead of growing without bound; an unreadable
  counter file fails closed like the write path. The Redis DSN forms in the
  docblock now work: `redis://password@host`, `redis://user:pass@host`
  (ACL), URL-encoded passwords, and `redis:///path/to.sock`.

- **Password checks without an injected hasher cap the password length**
  at the same 4096 bytes `UserPasswordHasher` enforces. An account with no
  stored password now costs a dummy verify, so its existence is not readable
  from the response time.

- **The lock reason stays out of `LockedAccountException`.** It is
  administrator-authored and already logged; a handler rendering the
  message would have shown it to the visitor.

- **CSRF token eviction is least-recently-used.** A page minting dozens of
  per-row ids could evict the firewall-wide token every other form relies
  on. Rendering an id now refreshes it. A token id of `"0"` is accepted.

- **`idle_timeout => 0` passes schema validation**, as the docs say it does.

- **Redeclaring a module's service takes the new lifetime and status.** An
  application answering a module's `shared()` id with `set()` kept the
  module's shared flag, so the "fresh per resolve" definition was cached
  anyway, and a deprecation the module attached to the id kept firing.
  `configureServices()` now clears the previous shared flag, deprecation and
  cached instance for every id the new configurator declares.

- **`has()` agrees with `get()` for authenticator names.** `get('form_login')`
  resolved a registered authenticator while `has('form_login')` said no.

- **Resolving an authenticator or factory id no longer builds the entity
  manager.** The container consulted the repository list, which loads every
  entity's metadata, before its in-memory authenticator and factory tables.
  Those are checked first now, so an application without Doctrine configured
  can still resolve them, and every other application skips the metadata
  load for ids that were never repositories.

- **`#` comments in a `.env` file no longer break the whole file.** INI only
  honours `;`, and the scanner tolerated a plain `#` line only until one
  contained a character it reserves — so a comment as ordinary as
  `# Generate with: php -r '…random_bytes(32)…'` failed *every* variable in the
  file, with an error message blaming quoting. Whole-line `#` comments are now
  stripped before parsing; a `#` inside a value is still part of the value.
  Shipping a `.env.example` full of `#` comments and telling people to copy it
  now works.

- **A remember-me cookie now signs a visitor in on the login page too.**
  `GET {entry_point}` short-circuited the authenticators, so a returning
  visitor whose session had expired was shown the login form on `/login`
  while every other URL in the firewall would have signed them back in. The
  login page now runs the remember-me authenticator first (Symfony's order)
  and the login controller sees the user through `#[CurrentUser]`; a cookie
  that no longer validates still falls through to the form, expired on the
  response. Only the login page changes — a cookie never bypasses the 2FA page.

## [0.20.0] - 2026-09-13

### Changed

- ⚠️ **PHP 8.4 is the minimum.** The kernel now uses property hooks and
  asymmetric visibility, neither of which exists before 8.4. CI runs 8.4, 8.5
  and 8.6 (allowed to fail until it is released). The runtime guards for
  native lazy objects (`OrmConfigurator`) and AVIF support are gone with the
  floor that made them conditional. **Symfony stays on `^7.4`, the LTS line;**
  the components move only when the next LTS ships, never to an interim 8.x
  release — see the README's Requirements section for the policy.

- ⚠️ **`AbstractController`'s inherited services are built on first use.**
  `$entityManager`, `$flashBag`, `$tokenStorage`, `$urlGenerator`,
  `$userProvider`, `$validator` and `$inertia` are get-only property hooks
  backed by a `ServiceLocator`, resolved when a method first reads them
  instead of when the kernel wires the controller. Two of them cost: the
  flash bag and the Inertia renderer start the session, and a session cookie
  makes the response uncacheable by every shared cache — an action that
  touched neither was paying for both. **A subclass can no longer assign these
  properties** (`$this->entityManager = …` now raises "Property is
  read-only"); a test that did so should stub the `AppInterface` it hands to
  `setSubscribedServices()` instead. Reading them behaves as before, including
  the unwired-Inertia stand-in. (`src/Core/AbstractController.php`)

- **The router cache is written in every environment.** Previously only
  `prod` cached the compiled matcher and generator, so `dev` and `test`
  reloaded every route from source — tokenising and reflecting every
  controller — on each request. Now every environment writes
  `var/cache/<env>/router`; the kernel's `debug` flag (`!isProd()`) decides
  whether the cache is checked for staleness. Outside production a changed
  `#[Route]`, or an added or removed controller, is picked up on the next
  request with nothing to clear; production still never re-checks, so
  clearing the directory remains a deployment step. Documented under
  [Deployment › The router cache](docs/deployment.md#the-router-cache).
  (`src/Core/Kernel.php`)

- **`Kernel::$baseDir`, `$routeLoader`, `$roleHierarchy` and `$debugStack`
  are `public protected(set)`.** Readable from outside as before, assignable
  only by the kernel and its subclasses. Nothing in the framework or the
  reference applications wrote to them; a host that did must move the
  assignment into its `App` class.

### Added

- **`RouterInterface::cachedRouteData(string $key, \Closure $project)`.**
  A request-independent projection of the route collection — a menu, a list
  of names, a permission map — dumped beside the compiled matcher as
  `route_data_<key>.php` and invalidated by the same resources, so it
  rebuilds exactly when the routes do. `$project` runs only on a cache miss
  and must return an array `var_export()` can round-trip; `$key` is a bare
  filename, `[a-z0-9_-]`. Prefer it over walking `getRouteCollection()` at
  request time, which reloads every route from source. See
  [Routing › Deriving data from the routes](docs/routing.md#deriving-data-from-the-routes).
  Implementations of `RouterInterface` must add the method.
  (`src/Routing/Router.php`, `src/Routing/RouterInterface.php`)

- **`DependencyInjection\ServiceLocator`.** A PSR-11 container over a fixed
  map of id → factory, each built once on first `get()`. What
  `AbstractController` uses for its inherited services; usable by any
  collaborator that should reach a declared subset of the kernel's services
  and nothing else. Unknown ids raise the framework's `NotFoundException`
  naming what was declared.

## Earlier releases

- [0.19.0](releases/0.19.0.md) - 2026-09-13
- [0.18.0](releases/0.18.0.md) - 2026-09-08
- [0.17.0](releases/0.17.0.md) - 2026-09-07
- [0.16.0](releases/0.16.0.md) - 2026-09-06
- [0.15.0](releases/0.15.0.md) - 2026-09-01
- [0.14.0](releases/0.14.0.md) - 2026-08-29
- [0.13.0](releases/0.13.0.md) - 2026-08-28
- [0.12.0](releases/0.12.0.md) - 2026-08-27
- [0.11.0](releases/0.11.0.md) - 2026-08-27
- [0.10.1](releases/0.10.1.md) - 2026-08-21
- [0.10.0](releases/0.10.0.md) - 2026-08-20
- [0.9.0](releases/0.9.0.md) - 2026-08-19
- [0.8.0](releases/0.8.0.md) - 2026-08-15
- [0.7.0](releases/0.7.0.md) - 2026-08-07
- [0.6.1](releases/0.6.1.md) - 2026-08-03
- [0.6.0](releases/0.6.0.md) - 2026-08-02
- [0.5.0](releases/0.5.0.md) - 2026-08-02
- [0.4.1](releases/0.4.1.md) - 2026-07-25
- [0.4.0](releases/0.4.0.md) - 2026-07-19
- [0.3.1](releases/0.3.1.md) - 2026-07-13
- [0.3.0](releases/0.3.0.md) - 2026-06-15
- [0.2.0](releases/0.2.0.md) - 2026-06-13
