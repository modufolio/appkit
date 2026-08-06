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

Maps controller classes to their constructor dependencies. The array order must match the constructor parameter order.

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

## `config/interfaces.php`

Maps interface and class names to closures that produce the service. The closures bind to the kernel instance at load time — `$this` refers to the `App` kernel.

All core framework services are pre-wired. Add your own at the bottom of the file.

```php
return [
    CsrfTokenManagerInterface::class     => fn () => $this->csrfTokenManager(),
    EntityManagerInterface::class        => fn () => $this->entityManager(),
    Environment::class                   => fn () => $this->environment(),
    FlashBagAwareSessionInterface::class => fn () => $this->session(),
    FlashBagInterface::class             => fn () => $this->session()->getFlashBag(),
    ParameterResolverInterface::class    => fn () => $this->parameterResolver(),
    ResponseFactoryInterface::class      => fn () => new Psr17Factory(),
    ResponseInterface::class             => fn () => new Response(),
    RouterInterface::class               => fn () => $this->router(),
    SerializerInterface::class           => fn () => $this->serializer(),
    ServerRequestInterface::class        => fn () => $this->request(),
    SessionInterface::class              => fn () => $this->session(),
    TokenStorageInterface::class         => fn () => $this->tokenStorage(),
    UrlGeneratorInterface::class         => fn () => $this->router()->getUrlGenerator(),
    UserCheckerInterface::class          => fn () => new UserChecker(),
    UserPasswordHasherInterface::class   => fn () => new UserPasswordHasher(),
    UserProviderInterface::class         => fn () => $this->userProvider(),
    ValidatorInterface::class            => fn () => $this->validator(),

    // Add your own:
    MailerInterface::class               => fn () => $this->get(Mailer::class),
];
```

## `config/factories.php`

Registers service factory closures. The closure receives the kernel (`$container`) as its argument.

```php
use App\Service\Mailer;
use Doctrine\ORM\EntityManagerInterface;

return [
    Mailer::class => function ($container) {
        return new Mailer(
            $container->get(EntityManagerInterface::class),
            getenv('MAIL_DSN'),
        );
    },
];
```

## `config/repositories.php`

Maps repository classes to their Doctrine entity class.

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
| `pattern` | `string` | Path prefix or `segment:pos` pattern |
| `authenticators` | `string[]` | Names from `config/authenticators.php` |
| `entry_point` | `string` | Redirect destination for unauthenticated requests |
| `stateless` | `bool` | `true` disables session for this firewall |
| `security` | `bool` | `false` disables all security for this firewall |
| `logout.path` | `string` | POST URL to log out |
| `logout.target` | `string` | Redirect destination after logout |
| `two_factor_path` | `string` | Path for TOTP code entry (default `/2fa`) |
| `switch_user` | `array` | Impersonation config (`parameter`, `role`) |

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

Bootstrap file for `bin/console`. It creates a `ConsoleRunner` with the Doctrine config and registers commands.

```php
use App\Console\ConsoleRunner;

$runner = new ConsoleRunner($baseDir);
$runner->run();
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

```php
require dirname(__DIR__) . '/bootstrap.php';

$app = AppFactory::create(BASE_DIR);
$response = $app->handle(ServerRequest::fromGlobals());
(new Emitter())->emit($response);
```
