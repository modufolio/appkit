# Contributing

Thanks for considering a contribution to AppKit.

## Before you start

- **Security vulnerabilities:** never via issue or PR — see [SECURITY.md](SECURITY.md).
- **New features:** open an issue first to discuss fit. AppKit is deliberately small; features that grow the framework's surface (an event bus, a queue abstraction, autowiring) are out of scope by design — see the [design philosophy](docs/index.md#design-philosophy).
- **Bug fixes and doc improvements:** PRs welcome directly.

## Development setup

```bash
git clone https://github.com/modufolio/appkit
cd appkit
composer install
```

## Running checks

```bash
# Tests — run the suite affected by your change, not everything, while iterating:
vendor/bin/phpunit tests/Unit/Security/
composer test          # full suite
composer test:par      # full suite, parallel (paratest)

# Static analysis (level 8, must be clean):
composer stan

# Code style (applied automatically):
composer fix
```

## Writing tests

Prefer **functional tests over mocks**: boot the real fixture app and drive real requests through it. Extend `Modufolio\Appkit\Tests\Case\AppTestCase` and use its `get()`/`post()`/`actingAs()` helpers — see any test in `tests/Unit/Security/` for the pattern. Do not mock framework internals; if a test needs a seam, that's usually a sign the framework needs one.

The fixture application lives in `tests/App/` with its config in `tests/fixtures/config/`. If your change adds a service or route the tests need, wire it there the same way a real app would.

## Pull requests

- One logical change per PR.
- Every behavior change comes with a test that fails without it.
- `composer stan` and `composer fix` clean; CI runs both.
- Backwards-compatibility breaks need prior discussion in an issue.
- Update the relevant page in `docs/` when behavior or configuration changes — the docs state trade-offs explicitly, and yours should too.
