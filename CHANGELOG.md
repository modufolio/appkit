# Changelog

All notable changes to this project are documented here. Unreleased work and
the most recent release are in this file in full; every earlier release has its
own file under [`releases/`](releases/), listed at the bottom. Releases marked
**breaking** carry an *Upgrading* section.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.22.0] - 2026-09-17

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

- **The README and docs no longer say "no event dispatcher".** The stated
  choice is now precise: no event-driven control flow, no plugin system;
  notifications after the fact, yes.

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

- **A snippet called from inside a layout resolves again.** `render()` built
  the layout with the layout paths alone, and `snippet()` derives its search
  directories from the template paths, so `$this->snippet('global/meta')` in a
  layout looked under `site/layouts` and threw. The layout now keeps the
  template paths behind its own.

## Earlier releases

- [0.21.0](releases/0.21.0.md) - 2026-09-15
- [0.20.0](releases/0.20.0.md) - 2026-09-13
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
