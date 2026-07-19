# AppKit

AppKit is a lean PHP framework for building modern web applications. It works well with [Inertia.js](https://inertiajs.com/) and Vue.js — controllers return JSON props to Inertia while the PHP template engine handles server-rendered views. Routes are declared with attributes, dependencies are wired in config files. No magic, no auto-wiring surprises.

AppKit makes deliberate choices to stay small and fast. If you need a full event system, use Symfony.

## Design philosophy

**Explicit over implicit.** Every dependency is declared in a config file you can open and read top to bottom. There is no classpath scanning, no annotation magic, no container that builds itself at runtime. You can grep any interface name and find exactly where it is wired.

**Readable auth flow.** AppKit does not use a PSR-15 middleware pipeline for authentication. Middleware pipelines are order-dependent — move one layer and authentication silently breaks. A fixed pipeline solves the ordering problem but hides the flow across multiple classes. AppKit's authentication lives in one place and reads as a straight sequence: restore session → run authenticators → enforce access control → resolve controller. You can follow it in the source without jumping between files.

**Minimal, scoped state.** The framework was designed for RoadRunner from the start — not adapted for them after the fact. Request-scoped state is isolated in `ApplicationState`, created fresh for every request and cleared after the response is sent. Services with no per-request state live for the lifetime of the worker. What gets cleared is explicit — `reset()` is yours to write, so nothing is torn down behind your back, and nothing you forget is torn down for you either.

**Pipelines over procedures.** The parameter resolver, the brute-force protection, the query builder — data flows through explicit transformations rather than accumulating in shared state. This is a functional programming instinct applied pragmatically to PHP.

**Standing on good shoulders.** AppKit takes what works from the frameworks that came before it: Symfony's routing, security model, validator, and serializer; the factory pattern from Slim; the query builder API from Laravel; the disk abstraction from Laravel and Flysystem; the resolver pipeline from php-di/invoker and Symfony's argument resolver; the state model from Axum; and the maker command from the Symfony MakerBundle. The Kirby CMS philosophy of explicitness and filesystem clarity runs underneath all of it.

## What you get

- Attribute-based routing via Symfony Routing
- Explicit, hand-wired dependency injection through config files — no auto-wiring
- Inertia.js-compatible JSON responses out of the box
- PHP template engine for server-rendered views
- Form-login, JWT, API key, OAuth 2.1, and two-factor authentication
- Doctrine ORM with migrations, a fluent QueryBuilder, and pagination
- Form validation with request payload mapping
- File upload validation and storage
- Image processing (GD and ImageMagick)
- Brute-force protection (file-based or Redis)
- Console commands with Doctrine and maker support
- Array, string, file, and directory utilities
- RoadRunner compatible — see [modufolio/appkit-roadrunner](https://github.com/modufolio/appkit-roadrunner) for a working worker setup

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js 18+ (only for compiling frontend assets)

## Documentation

| Guide | What it covers |
|-------|----------------|
| [Getting started](getting-started.md) | Install, configure, and run your first AppKit app |
| [Kernel](kernel.md) | The Kernel: request lifecycle, service container, boot |
| [Routing](routing.md) | Declaring routes, route parameters, access control |
| [Controllers](controllers.md) | Writing controllers and using parameter attributes |
| [Dependency injection](dependency-injection.md) | Wiring services with config files |
| [Templates](templates.md) | Layouts, snippets, sections, and asset helpers |
| [Security](security.md) | Firewalls, access control rules, CSRF, roles |
| [Authenticators](authenticators.md) | Form login, JWT, OAuth 2.1, 2FA, brute-force protection |
| [Database](database.md) | Doctrine ORM, QueryBuilder, pagination, soft delete |
| [Forms](forms.md) | Validation, `ValidationResult`, request payload mapping |
| [Exception handling](exception-handling.md) | Turning exceptions into HTTP responses, custom handlers and formatters |
| [File uploads](file-uploads.md) | Validating and storing uploaded files |
| [Image processing](image-processing.md) | Darkroom (GD/ImageMagick), Dimensions, DiskManager |
| [Console](console.md) | Built-in commands, `make:entity`, writing your own |
| [Toolkit](toolkit.md) | Array, file, string, and directory utilities |
| [Testing](testing.md) | PHPUnit, EntityFactory, static analysis |
| [Deployment](deployment.md) | Nginx/Caddy, permissions, RoadRunner, switching databases |
| [Configuration](configuration.md) | Environment variables and config file reference |
