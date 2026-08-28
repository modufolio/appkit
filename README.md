# Appkit

[![CI](https://img.shields.io/github/actions/workflow/status/modufolio/appkit/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/modufolio/appkit/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![License: MIT](https://img.shields.io/badge/License-MIT-brightgreen.svg?style=flat-square)](https://opensource.org/licenses/MIT)
[![codecov](https://img.shields.io/codecov/c/github/modufolio/appkit?token=RMUZV84J10&style=flat-square)](https://codecov.io/gh/modufolio/appkit)

A small, hand-wired PHP application kernel built on Symfony components,
Doctrine ORM, Firebase JWT, and a strict-typed PSR-7 fork. Designed for
security-conscious SaaS applications that want Symfony-grade components
without Symfony's full kernel, bundle system, and compile step.

**In AppKit, your App class is the container.** Symfony compiles a container
class you never read; Laravel hides its container behind facades. Here the
container is a class you write: services are typed methods on your `App`,
lazily constructed and cached in properties you can see. There is nothing to
compile, because you already wrote what a compiler would generate — and
`grep` is the container debugger.

## Why it exists

- **Slim is too thin.** No Doctrine, no validation, no security primitives —
  the consumer wires everything.
- **Symfony is too heavy.** A compiled DI container, an event dispatcher,
  bundles, Flex recipes, and a bootstrap that has to be generated. Excellent
  for large apps; more than most SaaS workloads need.
- **Laravel is opinionated and non-Symfony.** Facades, ActiveRecord, and a
  separate ecosystem.
- **Appkit sits in between.** Symfony components plus Doctrine plus a thin
  abstract kernel, with a hand-compiled container so the file you read is
  the resolution path that runs — and the parts of Symfony's tooling that
  earn their keep, such as a `make:entity` generator ported from
  [MakerBundle](https://symfony.com/bundles/SymfonyMakerBundle/current/index.html).

## What AppKit deliberately doesn't include

Each of these is a stated choice with a documented alternative, not a gap:

- **No application-level event bus.** Extension happens through named seams:
  explicit interfaces (authenticators, user checkers, CSRF validators,
  package contracts answered in `config/services.php`), Doctrine's lifecycle
  events at the persistence layer, and plain method override — subclass your
  `App` and replace an accessor. Internal control flow stays a readable call
  stack.
- **No queue abstraction.** Background jobs run on RoadRunner's first-party
  jobs plugin — you are already running RoadRunner, and durability is a
  config swap, not a PHP layer. See
  [Background jobs](docs/deployment.md#background-jobs).
- **No mailer, no i18n.** Bring the PSR-compatible library your app needs and
  register it as an `App` method; the framework does not wrap what it cannot
  improve.
- **No container-coupled console.** `bin/console` boots without the app
  container, so a wiring bug can never take down the tool that fixes it —
  see [Console](docs/console.md#how-the-console-is-bootstrapped).
- **Security headers live at the edge** (nginx/Caddy/CDN), where they also
  cover static assets — see
  [What the framework does not handle](docs/security.md#what-the-framework-does-not-handle).

## What it solves

- **Fast boot.** No DI compile step, no cache invalidation. Config files are
  loaded with `require`; OPcache handles the rest.
- **Transparent control flow.** No event dispatcher by design. Reading
  `handleAuthentication()` top-to-bottom shows exactly what runs.
- **RoadRunner-aware.** Every stateful service implements
  [`ResetInterface`](src/Core/ResetInterface.php); the kernel rebuilds
  `ApplicationState` per request. The worker loop stays in your application
  rather than behind a runtime — see
  [modufolio/appkit-roadrunner](https://github.com/modufolio/appkit-roadrunner).
- **Security hardening already wired.** Symfony-style firewalls with
  method/host/IP restrictions; path- and attribute-based access control with a
  role hierarchy and trust-level attributes (`IS_AUTHENTICATED_FULLY`,
  `IS_IMPERSONATOR`, …); CSRF rotation on login; session-fixation defence;
  remember-me with optional persistent tokens (theft detection and rotation);
  HTTPS channel upgrades; brute-force protection; a token unserialize allowlist;
  password timing-parity; credential-length DoS caps; and boot-time
  firewall-config validation.
- **Strict typing.** PHP 8.2+, `declare(strict_types=1)` throughout. The
  bundled PSR-7 implementation is a strict-typed fork of `nyholm/psr7`.

## Quick start

```bash
composer create-project modufolio/appkit-skeleton my-app
cd my-app
composer start
```

The skeleton lives in its own repository:
[modufolio/appkit-skeleton](https://github.com/modufolio/appkit-skeleton).

## A minimal controller

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Modufolio\Appkit\Core\AbstractController;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HelloController extends AbstractController
{
    #[Route('/hello/{name}', methods: ['GET'])]
    public function show(string $name): ResponseInterface
    {
        return Response::json(['message' => "Hello, {$name}"]);
    }
}
```

## Documentation

Full guides under [docs/](docs/index.md):

- [Getting started](docs/getting-started.md) — install, configure, and run your first app
- [Kernel](docs/kernel.md) — request lifecycle, service container, boot
- [Routing](docs/routing.md) — routes, parameters, access control
- [Controllers](docs/controllers.md) — controllers and parameter attributes
- [Dependency injection](docs/dependency-injection.md) — wiring services with config files
- [Templates](docs/templates.md) — layouts, snippets, sections, asset helpers
- [Security](docs/security.md) — firewalls, access control, CSRF, roles, trust levels
- [Authenticators](docs/authenticators.md) — form login, JWT, OAuth 2.1, 2FA, remember-me, brute-force
- [Database](docs/database.md) — Doctrine ORM, QueryBuilder, pagination, soft delete
- [Forms](docs/forms.md) — validation, `ValidationResult`, payload mapping
- [Exception handling](docs/exception-handling.md) — turning exceptions into HTTP responses
- [File uploads](docs/file-uploads.md) — validating and storing uploaded files
- [Image processing](docs/image-processing.md) — Darkroom, Dimensions, DiskManager
- [Console](docs/console.md) — built-in commands (`debug:firewall`, `security:validate`, `make:entity`), writing your own
- [Toolkit](docs/toolkit.md) — array, file, string, and directory utilities
- [Testing](docs/testing.md) — PHPUnit, EntityFactory, static analysis
- [Deployment](docs/deployment.md) — Nginx/Caddy, permissions, RoadRunner, databases
- [Configuration](docs/configuration.md) — environment variables and config reference

Start with the [introduction](docs/index.md) for the architecture overview and
the design philosophy the rest of the documentation assumes.

## Requirements

- PHP 8.2 or later
- Composer
- Extensions: `curl`, `dom`, `exif`, `fileinfo`, `gd`, `intl`, `libxml`,
  `pdo`, `simplexml`, `sqlite3`, `zip`

See [`composer.json`](composer.json) for the canonical dependency list.

## License

MIT. See [LICENSE](LICENSE).
