# Deployment

## Pre-deployment checklist

Before going live, confirm each of these.

- [ ] `APP_ENV=prod` in your server environment
- [ ] `APP_URL` set to your production domain (e.g. `https://example.com`)
- [ ] `COOKIE_SECURE=true`
- [ ] `composer install --no-dev --optimize-autoloader` completed
- [ ] `npm run build` completed and compiled assets uploaded to `public/assets/`
- [ ] `php bin/console migrations:migrate` completed
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
        fastcgi_param APP_URL https://example.com;
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
        env APP_URL https://example.com
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

## Compiled assets

`public/assets/css/app.css`, `public/assets/js/app.js`, and `public/assets/js/app.js.map` are gitignored. Rebuild them as part of every deployment.

```bash
npm ci
npm run build
```

If you use a CDN or object storage for assets, copy the compiled files there and update `APP_URL` or your asset base URL accordingly.

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

`FileLogger` writes to two files:

| File | Contains |
|------|---------|
| `storage/logs/app.log` | All log levels |
| `storage/logs/error.log` | Emergency, alert, critical, error |

Context fields named `password`, `plainPassword`, `token`, `authorization`, and `cookie` are automatically redacted before writing.

To replace `FileLogger` with Monolog or another PSR-3 implementation, swap it in `AppFactory::create()`:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('app');
$logger->pushHandler(new StreamHandler($baseDir . '/storage/logs/app.log'));

return (new App(
    // ...
    logger: $logger,
))->configureSecurity($security)->boot();
```

## Caching

Doctrine uses different cache adapters per environment:

| Environment | Adapter |
|-------------|---------|
| `prod` | `FilesystemAdapter` — persisted in `var/cache/` |
| `dev` / `test` | `ArrayAdapter` — in-memory, cleared on each request |

Clear the Doctrine cache after a deployment that changes entity metadata:

```bash
php bin/console orm:clear-cache:metadata
php bin/console orm:clear-cache:query
php bin/console orm:clear-cache:result
```

Clear the router cache:

```bash
rm -rf var/cache/router/
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

Three details that are easy to miss:

- `waitRequest()` returning `null` means shutdown — break, do not `continue`
- `$app->reset()` belongs in `finally`, so it runs even when the handler throws
- periodic `gc_collect_cycles()` keeps cyclic garbage from accumulating across
  thousands of requests in one process

### The reset contract

**`Kernel::reset()` is abstract.** The framework mandates nothing — each
application supplies its own and is responsible for tearing down whatever the
framework does not.

`AbstractApplicationState::reset()` clears exactly five things:

| Cleared | Not cleared |
|---|---|
| `session` (saved, then nulled) | Router / route collection |
| `sessionStorage` | Entity manager |
| `tokenStorage` (token set to `null` first) | Emitter |
| `requestInstances` | Environment |
| `firewallNameCache` | Cached service instances |

Everything in the right column is yours. A typical `App::reset()`:

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
