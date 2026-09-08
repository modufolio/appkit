# Deployment

## Pre-deployment checklist

Before going live, confirm each of these.

- [ ] `APP_ENV=prod` in your server environment
- [ ] Any variable your own code reads through `env()` is set. The skeleton's `.env.example` also lists `APP_URL`, but nothing in the framework or in the skeleton's shipped code reads it — base URLs come from the request
- [ ] `COOKIE_SECURE=true`
- [ ] `composer install --no-dev --classmap-authoritative` completed — see [Autoloader](#autoloader)
- [ ] `npm run build` completed and compiled assets uploaded to `public/assets/`
- [ ] `php bin/console migrations:migrate` completed
- [ ] `php bin/console security:validate` passes — config validation is skipped at runtime in `prod`, so this is the last gate that catches a bad firewall or access-control rule (see [Security](security.md#validating-configuration)). The framework ships the command as `SecurityValidateCommand`; the skeleton's console does not register it, so add it to your runner first
- [ ] `storage/logs/` is writable by the web server user
- [ ] `var/` is writable by both the web server user and the CLI user
- [ ] Any secrets (JWT keys, OAuth secrets, DB passwords) are in the server environment, not in `.env` files

## Web server configuration

Only `public/` should be accessible from the web. The project root must not be served directly.

### nginx

```nginx
server {
    listen 443 ssl;
    server_name example.com;

    root /var/www/my-app/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param APP_ENV prod;
        fastcgi_param COOKIE_SECURE true;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

Pass environment variables via `fastcgi_param` so they are available through `getenv()`.

### Caddy

```caddy
example.com {
    root * /var/www/my-app/public
    php_fastcgi unix//run/php-fpm/php-fpm.sock {
        env APP_ENV prod
        env COOKIE_SECURE true
    }
    file_server
    @notFound not file
    rewrite @notFound /index.php
}
```

## File permissions

| Path | Needs write access | Who |
|------|--------------------|-----|
| `storage/logs/` | Yes | Web server user |
| `var/` | Yes | Web server user and CLI user |
| `database/data.db` | Yes (SQLite only) | Web server user |
| `database/` (directory) | Yes (SQLite only) | Web server user — SQLite WAL mode writes a `-wal` and `-shm` file next to the DB |

Everything else should be read-only for the web server user.

> **Application code.** `storage/logs/` and `bin/console` are not framework paths. The skeleton (`modufolio/appkit-skeleton`) creates `storage/logs/` for its `FileLogger` and boots the console from `config/console.php`; both are yours to change. The framework's own writes — sessions, caches, proxies — go under `var/` (`Kernel::setVarDir()` moves them).

## Compiled assets

`public/assets/css/app.css`, `public/assets/js/app.js`, and `public/assets/js/app.js.map` are gitignored. Rebuild them as part of every deployment.

```bash
npm ci
npm run build
```

If you use a CDN or object storage for assets, copy the compiled files there and reference them with plain `<link>`/`<script>` tags in your layout. `$this->css()` and `$this->js()` cannot take a full URL: `Template::url()` prefixes every queued asset with the request's base URL, so `css('https://cdn.example.com/app.css')` renders `href="https://example.com/https://cdn.example.com/app.css"`. Setting `APP_URL` changes nothing: neither the framework nor the skeleton's shipped code reads it, so it only matters if your own code does through `env('APP_URL')`.

## Switching from SQLite

Change `config/doctrine.php` to use a different DBAL driver. Add the corresponding environment variables.

```php
$orm->connection([
    'driver'   => 'pdo_mysql',
    'host'     => getenv('DB_HOST'),
    'port'     => getenv('DB_PORT') ?: 3306,
    'dbname'   => getenv('DB_NAME'),
    'user'     => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'charset'  => 'utf8mb4',
])->entities($projectDir . '/src/Entity');
```

For PostgreSQL:

```php
$orm->connection([
    'driver'   => 'pdo_pgsql',
    'host'     => getenv('DB_HOST'),
    'dbname'   => getenv('DB_NAME'),
    'user'     => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
])->entities($projectDir . '/src/Entity');
```

After switching drivers, generate a fresh migration from your entities and run it against the new database.

## Logging

> **Application code.** `FileLogger` is not part of the framework. The skeleton (`modufolio/appkit-skeleton`) ships a starting version in `src/Logger/FileLogger.php`; it is yours to change. The framework only asks for a PSR-3 `LoggerInterface` and logs through whatever you give it.

The skeleton's `FileLogger` writes to two files:

| File | Contains |
|------|---------|
| `storage/logs/app.log` | All log levels |
| `storage/logs/error.log` | Emergency, alert, critical, error |

Context fields named `password`, `plainPassword`, `token`, `authorization`, and `cookie` are redacted before writing (`scrubContext()`).

The skeleton wires it in `config/services.php`:

```php
$services->set(LoggerInterface::class, FileLogger::class)
    ->args(['%app.base_dir%/storage/logs']);
```

To replace it with Monolog or another PSR-3 implementation, change that one definition — nothing else in the skeleton refers to `FileLogger`:

```php
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

$services->set(StreamHandler::class)
    ->args(['%app.base_dir%/storage/logs/app.log']);

$services->set(LoggerInterface::class, Logger::class)
    ->args(['app'])
    ->call('pushHandler', [service(StreamHandler::class)]);
```

An application built directly on the framework `Kernel`, like the RoadRunner reference (`modufolio/appkit-roadrunner`), passes the logger to its `App` constructor instead; there the `logger:` argument in `AppFactory::create()` is the place to swap.

## Autoloader

Always deploy with an authoritative classmap:

```bash
composer install --no-dev --classmap-authoritative
```

Without it, Composer resolves every class through PSR-4 prefix lookups and
`file_exists()` probes — several hundred per request — which can account for more
than half of a page's response time. `--classmap-authoritative` (which implies
`--optimize-autoloader`) turns each lookup into a single array hit and skips the
filesystem entirely for unknown classes. `--no-dev` matters too: dev packages can
register Composer `files` autoloads (PHPUnit does) that would otherwise be
included on every production request.

Don't use `--classmap-authoritative` in development — classes added after the
dump are not found until you re-run it.

## Caching

Persistent caches are namespaced by environment — `var/cache/prod`, `var/cache/dev`, … (`Kernel::cacheDir()`) — so switching `APP_ENV` on one machine can never serve a cache built by another environment. A stale prod metadata cache after an entity change is exactly the failure mode this prevents.

Doctrine uses different cache adapters per environment:

| Environment | Adapter |
|-------------|---------|
| `prod` | `FilesystemAdapter` — persisted in `var/cache/prod/` |
| `dev` / `test` | `ArrayAdapter` — in-memory, cleared on each request |

Clear the Doctrine cache after a deployment that changes entity metadata:

```bash
php bin/console orm:clear-cache:metadata
php bin/console orm:clear-cache:query
php bin/console orm:clear-cache:result
```

Clear the router cache:

```bash
rm -rf var/cache/prod/router/
```

If the application uses [the Symfony container behind the kernel](dependency-injection.md#the-symfony-container-behind-the-kernel), the compiled container lives next to it. A changed module set (`config/modules.php`) rebuilds it on its own — a hash of the resolved manifest sits beside the class — but a changed `config/container.php` or a module's `container.php` does not, so clear it on deploy:

```bash
rm -rf var/cache/prod/container/
```

## RoadRunner

> **Reference implementation:** [`modufolio/appkit-roadrunner`](https://github.com/modufolio/appkit-roadrunner)
> is the canonical setup — `worker.php`, `.rr.yaml`, and a `RoadRunnerApp` that
> overrides `handle()`. Start from that repo rather than from this page.

AppKit runs under RoadRunner's persistent worker model. The relevant behaviours:

- A fresh application state is created per request
- Controller instances are cached per request, not across requests
- Static state in core classes is *managed*, not absent — `Router` keeps a static
  compiled-route cache and clears it in `Router::reset()`

There is no framework-supplied runtime. The worker loop is written explicitly, so
what happens per request is visible in your own code rather than hidden behind a
runtime abstraction.

```bash
composer require spiral/roadrunner spiral/roadrunner-cli spiral/roadrunner-http
```

```php
// worker.php
use App\AppFactory;
use App\RoadRunnerApp;
use Modufolio\Psr7\Http\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

require_once __DIR__ . '/bootstrap.php';

$psr17  = new Psr17Factory();
$worker = new PSR7Worker(Worker::create(), $psr17, $psr17, $psr17);

$app = AppFactory::create(__DIR__, RoadRunnerApp::class);

$requestCount = 0;
$gcInterval   = 100;

while (true) {
    try {
        $request = $worker->waitRequest();
    } catch (\Throwable $e) {
        $worker->getWorker()->error((string) $e);
        continue;
    }

    if ($request === null) {
        break;   // graceful shutdown
    }

    try {
        $response = $app->handle($request);
        $worker->respond($response);
    } catch (\Throwable $e) {
        $worker->getWorker()->error((string) $e);
    } finally {
        $app->reset();

        if (++$requestCount % $gcInterval === 0) {
            gc_collect_cycles();
        }
    }
}
```

> **Application code.** `AppFactory` and `RoadRunnerApp` are not part of the framework. They come from `modufolio/appkit-roadrunner` — `src/AppFactory.php`, whose signature is `create(string $baseDir, string $appClass = App::class)`, and `src/RoadRunnerApp.php` — and are yours to change. The skeleton (`modufolio/appkit-skeleton`) has neither: its `public/index.php` constructs `new Kernel(baseDir: …, environment: …)`, a class of its own that does not extend the framework `Kernel`.

Three details that are easy to miss:

- `waitRequest()` returning `null` means shutdown — break, do not `continue`
- `$app->reset()` belongs in `finally`, so it runs even when the handler throws
- periodic `gc_collect_cycles()` keeps cyclic garbage from accumulating across
  thousands of requests in one process

### The reset contract

**`Kernel::reset()` is abstract.** The framework mandates nothing — each
application supplies its own and is responsible for tearing down whatever the
framework does not.

`AbstractApplicationState::reset()` clears exactly six things:

| Cleared | Not cleared |
|---|---|
| `session` (saved, then nulled) | Router / route collection |
| `sessionStorage` | Entity manager |
| `tokenStorage` (token set to `null` first) | Emitter |
| `requestInstances` | Environment |
| `firewallNameCache` | Cached service instances |
| `firewallRequestCache` | |

Everything in the right column is yours. A typical `App::reset()`, abridged from the reference implementation:

```php
public function reset(): void
{
    $this->state?->reset();
    $this->state = null;

    $this->debugStack->resetQueries();
    $this->entityManagerFactory?->reset();

    $this->emitter = null;
    $this->environment = null;
    $this->instances = [];
}
```

> **Whether you must also reset the router depends on your route loaders.**
>
> Routes loaded from PHP attributes or static config genuinely do not change
> between requests, so leaving the router in place is correct and saves rebuilding
> the collection every request. The reference implementation does exactly this.
>
> Routes *generated from the filesystem* are different. If a loader scans a
> directory — a flat-file page loader, for example — then adding a file adds a
> route, and a worker holding its boot-time collection will keep serving the old
> one. Existing pages work, newly created ones 404 until workers restart. In that
> case call `Router::reset()`, which clears the matcher, generator, collection and
> the static route cache.

### Configuration

`.rr.yaml` at the project root:

```yaml
version: '3'

server:
    command: "php worker.php"
    relay: pipes
    env:
        XDEBUG_MODE: "off"

http:
    address: 0.0.0.0:8080
    middleware: ["static", "gzip"]
    static:
        dir: "public"
        forbid: [".php", ".htaccess"]
    pool:
        num_workers: 8
        supervisor:
            max_worker_memory: 128
```

`max_worker_memory` (MB) restarts a worker that exceeds the limit — a safety net
for slow leaks, not a substitute for resetting state properly. Disabling xdebug in
the worker environment matters: it roughly halves throughput when left on.

### Background jobs

**For background jobs, use RoadRunner's first-party jobs plugin** via
[`spiral/roadrunner-jobs`](https://github.com/roadrunner-php/jobs). AppKit
deliberately ships no queue abstraction of its own and does not integrate
Symfony Messenger — you are already running RoadRunner, and its jobs plugin
gives you queues, workers, and driver-swappable pipelines with no extra
infrastructure layer in PHP.

The pattern, shown working in
[`modufolio/appkit-roadrunner`](https://github.com/modufolio/appkit-roadrunner):

- **One worker script serves both modes.** `worker.php` branches on `RR_MODE` —
  HTTP requests go through `$app->handle()`, job payloads through a
  `Spiral\RoadRunner\Jobs\Consumer` loop dispatching to your handler classes
  (`src/Jobs/`, one class per task type).
- **Push from PHP over the RPC socket**; handlers run in dedicated job workers
  with their own pool and `max_worker_memory` supervisor.
- **Durability is a config decision, not a code change.** Pipelines start on
  `driver: memory` (in-process, zero infrastructure) and swap to
  `redis`/`amqp`/`sqs` per pipeline in `.rr.yaml` when a queue needs to survive
  restarts — the PHP side does not change.

**Job handlers get the application, not console-style isolation.** The console
is repair tooling and must survive a broken app; jobs are the *application's
own deferred work* — if the app cannot boot, its jobs should stop, not run
against broken wiring. So the jobs branch of `worker.php` boots the `App` the
same way the HTTP branch does, and handlers take their dependencies from its
accessors (`$app->entityManager()`, your own service methods) — one
construction site, no third wiring system. Two rules follow:

- **Call `$app->reset()` in the task loop's `finally`**, exactly like the HTTP
  loop. Job workers are long-lived PHP processes, and the same
  [reset contract](#the-reset-contract) applies — above all
  `EntityManager::clear()`, or entities from one task bleed into the next.
- **Only application-level services.** Request-scoped accessors — `session()`,
  `tokenStorage()`, `request()` — have no meaning in jobs mode; no request ever
  creates their state. A handler that needs to know "who" acts on an id in the
  payload, not on a session.

## Security headers

Set these in your reverse proxy, not in AppKit:

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Frame-Options SAMEORIGIN always;
add_header X-Content-Type-Options nosniff always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
add_header Content-Security-Policy "default-src 'self'" always;
```

AppKit handles CSRF protection and session hardening. Edge-level headers belong in your infrastructure layer.
