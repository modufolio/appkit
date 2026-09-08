# Configuration

AppKit is configured through PHP files in `config/` and environment variables. There is no YAML or XML configuration.

## Environment variables

Use the global `env()` helper to read environment variables in any config file:

```php
env('APP_ENV', 'prod')       // falls back to 'prod' if not set
env('COOKIE_SECURE', false)  // "true"/"false" strings are cast to bool
```

`env()` checks `$_ENV`, `$_SERVER`, and then any `.env` file loaded by the bootstrap — in that order, so a real environment variable always wins over a file. See [`bootstrap.php`](#bootstrapphp) for how the file is loaded and frozen.

### Typed access

Every value the environment hands you is a string, so `COOKIE_SECURE=false` arrives as `"false"` — which is truthy. Casting it yourself with `(bool)` silently turns the flag back on. Call `env()` with no arguments to get the `Env` reader and ask for the type you want instead:

```php
env()->getBool('COOKIE_SECURE', true)   // filter_var rules: "false", "0", "off", "no" are all false
env()->getInt('DB_PORT', 3306)
env()->getFloat('SAMPLE_RATE', 0.1)
env()->getString('APP_NAME', 'AppKit')
env()->has('SENTRY_DSN')
```

These are modelled on Symfony's env var processors (`%env(bool:FOO)%`, `%env(int:FOO)%`).

A value that cannot be read as the requested type raises a `RuntimeException` rather than being coerced to `0` or `false`, so a typo in `.env` fails at boot instead of quietly disabling a setting.

For secrets, `getRequired()` replaces the hand-written "is it set?" check:

```php
// throws RuntimeException naming the variable when it is missing or empty
'secret' => env()->getRequired('REMEMBER_ME_SECRET'),
```

Omitting the default on any typed getter makes the variable required in the same way.

### Limitations of the built-in helper

The `env()` helper covers simple setups. It has no support for:

- Automatic per-environment file resolution (you can chain `fromFile()` calls yourself, but nothing picks `.env.prod` for you based on `APP_ENV`)
- Variable interpolation (`DATABASE_URL="${DB_HOST}/mydb"`)
- Multiline values (a quoted newline is a parse error, reported with its line number)
- Command substitution (`$(...)`) — deliberate: a `.env` file should not be executable
- Secret resolution from a vault or secrets manager

### Using Symfony Dotenv for complex setups

Install [Symfony Dotenv](https://symfony.com/doc/current/components/dotenv.html) when you need per-environment overrides or a team workflow with committed base values and ignored local overrides:

```bash
composer require symfony/dotenv
```

Load it early in `bootstrap.php`, before any config files are required:

```php
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->loadEnv(dirname(__DIR__) . '/.env');
```

Symfony Dotenv loads files in this order, with later files taking priority:

| File | Committed | Loaded in `test` |
|------|-----------|------------------|
| `.env` | Yes — base defaults for all environments | Yes |
| `.env.local` | No — personal machine overrides | No |
| `.env.{APP_ENV}` (e.g. `.env.test`) | Yes — environment-specific defaults | Yes |
| `.env.{APP_ENV}.local` | No — environment-specific local overrides | Yes |

The rule: committed files set shared defaults, local files override them per machine without affecting other developers. Real environment variables (already set in the shell or web server) always win — Symfony Dotenv never overwrites them.

A typical team setup:

```
.env            # APP_ENV=dev, DB_URL=sqlite:///database/app.db  ← committed
.env.local      # DB_URL=mysql://root:secret@localhost/mydb       ← gitignored
.env.test       # APP_ENV=test, DB_URL=sqlite:///:memory:         ← committed
```

Once Symfony Dotenv is loaded, `env()` still works — it reads from `$_ENV` first, which is where Symfony Dotenv puts its values.

### Core variables

Every AppKit app recognises these three; everything else is defined by your own config files.

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `prod` | Application environment. One of `dev`, `test`, or `prod`. |
| `APP_URL` | — | Base URL of the application, for your own use (e.g. absolute links in CLI or email contexts). No trailing slash. Note: `$this->url()` and URL generation derive their base from the incoming request, not this value. |
| `COOKIE_SECURE` | `false` | Set to `true` in production behind HTTPS. Adds the `Secure` flag to session cookies. |

> `APP_ENV` defaults to `prod` when the variable is missing. This means an unconfigured production deploy is safe — it will not accidentally run in debug mode.

## `config/routes.php`

Configures the route loader. By default it scans `src/Controller/` for `#[Route]` attributes using `AttributeDirectoryLoader`.

```php
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    $routes->import('../src/Controller/', 'attribute');
};
```

Add manual routes below the import:

```php
$routes->add('home.alias', '/welcome')->methods(['GET']);
```

See [Routing](routing.md).

## `config/controllers.php`

Maps controller classes to their constructor dependencies. In a plain list the array order must match the constructor parameter order; keying entries by parameter name passes them as named arguments, which cannot be transposed — prefer that for anything with more than two dependencies (see [Dependency injection](dependency-injection.md#naming-the-arguments)).

```php
return [
    HomeController::class => [
        CsrfTokenManagerInterface::class,
    ],
    PostController::class => [
        CsrfTokenManagerInterface::class,
        MailerInterface::class,
    ],
];
```

See [Dependency injection](dependency-injection.md).

## `config/services.php`

Declares the application's services through a `ServiceConfigurator`. The kernel pre-wires its own core services (router, session, entity manager, CSRF, serializer, …), so this file only adds what the application needs — an entry with a core id overrides the kernel default. Factory closures receive the application as their only argument.

```php
use App\App;
use App\Contract\MailerInterface;
use App\Service\Mailer;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;

return function (ServiceConfigurator $services): void {
    $services
        ->set(Mailer::class, fn (App $app) => new Mailer(
            $app->entityManager(),
            env('MAIL_DSN'),
        ))
        ->alias(MailerInterface::class, Mailer::class);
};
```

`set()` builds a fresh instance per `get()`; `shared()` resolves once per request (cleared by `reset()`); `alias()` points one id at another. See [Dependency injection](dependency-injection.md) for the full API and the legacy `config/interfaces.php` / `config/factories.php` layout it replaces.

## `config/repositories.php`

Optional. Maps repository classes to their Doctrine entity class. Without it the kernel derives the same map from Doctrine's metadata; an application that hands the file's array to the kernel replaces that derivation — and an empty array disables it, so a file that exists must be complete. See [Wiring repositories](dependency-injection.md#wiring-repositories).

```php
return [
    UserRepository::class => User::class,
    PostRepository::class => Post::class,
];
```

## `config/authenticators.php`

Defines named authenticator factory closures. The key is the name referenced in `config/security.php` under `authenticators`.

```php
return [
    'form_login' => function ($container) {
        return new FormLoginAuthenticator(
            userProvider:     $container->get(UserProviderInterface::class),
            csrfTokenManager: $container->get(CsrfTokenManagerInterface::class),
            session:          $container->get(FlashBagAwareSessionInterface::class),
        );
    },
];
```

## `config/security.php`

Configures firewalls, access control, and role hierarchy. See [Security](security.md) for the full reference.

Firewall option reference:

| Option | Type | Description |
|--------|------|-------------|
| `pattern` | `string` | Path prefix (whole segments: `/admin` matches `/admin/users`, not `/administrator`) or `segment:pos` pattern |
| `authenticators` | `string[]` | Names from `config/authenticators.php` |
| `entry_point` | `string` | Redirect destination for unauthenticated requests |
| `stateless` | `bool` | `true` disables session for this firewall |
| `security` | `bool` | `false` disables all security for this firewall |
| `methods` | `string[]` | Only handle these HTTP methods; other methods fall through to the next firewall |
| `host` | `string` | Only handle this host (case-insensitive, plain match) |
| `ips` | `string[]` | Only handle requests from these client IPs / CIDR ranges |
| `logout.path` | `string` | POST URL to log out |
| `logout.target` | `string` | Redirect destination after logout |
| `two_factor_path` | `string` | Path for TOTP code entry (default `/2fa`) |
| `csrf` | `bool` | `false` turns off the kernel CSRF check for this firewall (default `true`) |
| `csrf_token_id` | `string` | Id of the session token sent in `X-CSRF-Token` (default `csrf`) |
| `csrf_delegated_paths` | `string[]` | Paths (firewall pattern syntax) whose controller validates its own CSRF token |
| `csrf_form_tokens` | `array<string,string>` | Symfony-form token shapes to accept: form name → token id |
| `csrf_validator` | `callable` | Custom check `function ($request, $tokenManager): ?bool`; must be callable |
| `switch_user.enabled` | `bool` | `true` turns on impersonation (default `false`) |
| `switch_user.role` | `string` | Role the impersonator must hold (default `ROLE_ALLOWED_TO_SWITCH`) |
| `switch_user.parameter` | `string` | POST field carrying the target identifier (default `_switch_user`) |
| `switch_user.target` | `string` | Redirect destination after switching (default: the current URI with the parameter stripped) |

Keys the schema does not know (an app-specific `context`, say) are kept, not rejected. The CSRF and restriction options are explained in [Security](security.md#defining-a-firewall).

## `config/doctrine.php`

Configures Doctrine ORM via `OrmConfigurator`. See [Database](database.md) for a full reference.

```php
return function (OrmConfigurator $orm) use ($projectDir): void {
    $orm->connection([
        'driver' => 'pdo_sqlite',
        'path'   => $projectDir . '/database/data.db',
    ])->entities($projectDir . '/src/Entity');
};
```

## `config/migrations.php`

Configures Doctrine Migrations.

```php
return [
    'table_storage' => [
        'table_name'                 => 'migrations',
        'version_column_name'        => 'version',
        'version_column_length'      => 191,
        'executed_at_column_name'    => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],
    'migrations_paths' => [
        'Database\Migrations' => 'database/migrations',
    ],
    'all_or_nothing' => false,
    'transactional'  => true,
];
```

`all_or_nothing: false` — a failure in one migration does not roll back previously run ones. `transactional: true` — each migration runs in its own database transaction.

## `config/console.php`

Bootstrap file for `bin/console`. It creates a `ConsoleRunner` and registers command groups — see [Console](console.md) for the full picture.

> **Application code.** `ConsoleRunner` is not part of the framework. The skeleton (`modufolio/appkit-skeleton`) ships a starting version in `src/Console/ConsoleRunner.php`; it is yours to change.

```php
use App\Console\ConsoleRunner;

require dirname(__DIR__) . '/bootstrap.php';

// Composer's autoloader returns the ClassLoader; the maker commands need it
$classLoader = require dirname(__DIR__) . '/vendor/autoload.php';

$console = new ConsoleRunner(
    classLoader: $classLoader,
    userClass:   App\Entity\User::class,
    projectDir:  dirname(__DIR__),
);

$console->addDefaultCommands();
$console->addOrmCommands();
$console->addMigrationsCommands();

$console->run();
```

## `bootstrap.php`

Defines the `BASE_DIR` constant, loads the Composer autoloader, and publishes the environment. Both `public/index.php` and `config/console.php` require it first.

```php
define('BASE_DIR', dirname(__DIR__));
require BASE_DIR . '/vendor/autoload.php';

(new Env())->fromFile(BASE_DIR . '/.env')->freeze();
```

`freeze()` seals the reader and publishes it process-wide, so `env()` and `Env::instance()` return it from anywhere — no constant sniffing, no lazy re-parsing mid-request. Load every file you need first; `fromFile()` throws once frozen, and later files win over earlier ones:

```php
(new Env())
    ->fromFile(BASE_DIR . '/.env')
    ->fromFile(BASE_DIR . '/.env.local')
    ->freeze();
```

A missing file is ignored rather than fatal, so the same bootstrap works in production where you set real environment variables and ship no `.env`. Real environment variables and `$_SERVER` always outrank file values.

A file that exists but is malformed *is* fatal, and the exception names the offending line. The underlying `parse_ini_file()` rejects the whole file on a single bad line, so failing quietly would drop every variable at once and surface much later as a confusing "required variable is not set" for a secret sitting right there in the file.

`export FOO=bar` is accepted — the prefix is stripped, so the variable is `FOO`.

A process that never runs the bootstrap — a one-off CLI script, a worker — still gets a working `env()`; it simply sees `$_ENV` and `$_SERVER` without any file.

## `public/index.php`

The HTTP entry point. Creates the application, handles the request, and emits the response.

> **Application code.** `AppFactory` is not part of the framework. The skeleton (`modufolio/appkit-skeleton`) ships a starting version in `src/AppFactory.php` — `create(string $baseDir): AppInterface`, which builds the route loader, loads the config files and boots `App` — and the framework's test application keeps its own in `tests/App/AppFactory.php`; it is yours to change.

```php
require dirname(__DIR__) . '/bootstrap.php';

$app = AppFactory::create(BASE_DIR);
$response = $app->handle(ServerRequest::fromGlobals());
(new Emitter())->emit($response);
```
