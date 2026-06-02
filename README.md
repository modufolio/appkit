# Appkit

A small, hand-wired PHP application kernel built on Symfony components,
Doctrine ORM, Firebase JWT, and a strict-typed PSR-7 fork. Designed for
security-conscious SaaS applications that want Symfony-grade components
without Symfony's full kernel, bundle system, and compile step.

## Why it exists

- **Slim is too thin.** No Doctrine, no validation, no security primitives —
  the consumer wires everything.
- **Symfony is too heavy.** A compiled DI container, an event dispatcher,
  bundles, MakerBundle, recipes. Excellent for large apps; more than most
  SaaS workloads need.
- **Laravel is opinionated and non-Symfony.** Facades, ActiveRecord, and a
  separate ecosystem.
- **Appkit sits in between.** Symfony components plus Doctrine plus a thin
  abstract kernel, with a hand-compiled container so the file you read is
  the resolution path that runs.

## What it solves

- **Fast boot.** No DI compile step, no cache invalidation. Config files are
  loaded with `require`; OPcache handles the rest.
- **Transparent control flow.** No event dispatcher by design. Reading
  `handleAuthentication()` top-to-bottom shows exactly what runs.
- **RoadRunner-aware.** Every stateful service implements
  [`ResetInterface`](src/Core/ResetInterface.php); the kernel rebuilds
  `ApplicationState` per request.
- **Security hardening already wired.** CSRF rotation on login,
  session-fixation defence, token unserialize allowlist, password
  timing-parity dummy, brute-force protection, generic 401 responses.
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
- [Security](docs/security.md) — firewalls, access control, CSRF, roles
- [Authenticators](docs/authenticators.md) — form login, JWT, OAuth 2.1, 2FA, brute-force
- [Database](docs/database.md) — Doctrine ORM, QueryBuilder, pagination, soft delete
- [Forms](docs/forms.md) — validation, `ValidationResult`, payload mapping
- [Exception handling](docs/exception-handling.md) — turning exceptions into HTTP responses
- [File uploads](docs/file-uploads.md) — validating and storing uploaded files
- [Image processing](docs/image-processing.md) — Darkroom, Dimensions, DiskManager
- [Console](docs/console.md) — built-in commands, `make:entity`, writing your own
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
