# Testing

AppKit projects use PHPUnit for tests, PHPStan for static analysis, and PHP-CS-Fixer for code style. All three are available as Composer scripts.

## Running tests

```bash
composer test
# or directly:
vendor/bin/phpunit
```

Run a single test suite:

```bash
vendor/bin/phpunit --testsuite Classes
```

Run a single test file:

```bash
vendor/bin/phpunit tests/Unit/Entity/UserTest.php
```

## Test suites

`phpunit.xml.dist` defines two suites: `Classes` covers `./tests` minus the
database tests, and `Database` holds the platform-sensitive Doctrine tests.
The default run executes both; `composer test:db` runs the Database suite
alone — against SQLite in memory by default, or any real engine via
`DB_DRIVER` (see [Testing against real databases](#testing-against-real-databases)).

## The shipped test harness

The framework ships its test harness under `Modufolio\Appkit\Testing\` — the
same classes AppKit's own suite runs on. PHPUnit stays in your `require-dev`;
the classes simply aren't loadable without it, which only test code minds.

Your application's base test case fills exactly one seam — how your app is
built — and inherits the rest: in-process request dispatch with SAPI-faithful
server params (`get`/`post`/`form`/`json`/`request`), session and CSRF
continuity across requests, engine-agnostic `refreshDatabase()`, and
`actingAs()`/`logout()` against the framework's form-login conventions.

```php
// tests/Case/AppTestCase.php
namespace App\Tests\Case;

use App\App;
use App\AppFactory;
use Modufolio\Appkit\Testing\AppTestCase as BaseAppTestCase;

abstract class AppTestCase extends BaseAppTestCase
{
    private static ?App $app = null;

    // The one required seam. Declaring your concrete App as the return
    // type gives every test typed access to your accessors.
    protected function app(): App
    {
        if (self::$app === null) {
            self::$app = AppFactory::create(dirname(__DIR__, 2), 'test');
            self::$app->initializeConsoleState();
        }

        return self::$app;
    }
}
```

Optional hooks, all no-ops by default:

| Hook | When it runs | Override it to |
|------|--------------|----------------|
| `loadFixtures()` | on demand from your tests | seed Doctrine fixtures |
| `resetAppConfiguration()` | in `tearDown()`, after `reset()` | undo per-test config changes (e.g. restore firewalls) |
| `afterSchemaCreate()` | at the end of `refreshDatabase()` | apply DDL SchemaTool doesn't know — triggers, views |
| `actingAs()` / `logout()` | when your tests call them | match your login route and field names |

Responses come back wrapped in `Modufolio\Appkit\Testing\TestResponse`
(status, header, JSON and Inertia assertions — see below). Database-level
tests `use Modufolio\Appkit\Testing\DatabaseTestingCapabilities;` for query
tracking, fixtures, snapshots and schema management against a DBAL connection.

## Testing against real databases

Both the harness trait and a `config/test/doctrine.php` built on the same
convention read the connection from the environment — SQLite in memory when
nothing is set, so a fresh checkout tests with zero setup:

```bash
docker compose up -d mysql postgres
DB_DRIVER=pdo_mysql DB_PORT=3308 DB_USER=root DB_PASSWORD=secret composer test:db
DB_DRIVER=pdo_pgsql DB_PORT=5434 DB_USER=postgres DB_PASSWORD=secret composer test:db
```

`DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` are
recognised; SQL Server additionally gets `TrustServerCertificate` set for the
self-signed certificate a containerised server presents. The harness keeps
teardown portable — referential checks are suspended per platform, and
schema changes between test classes are detected and rebuilt. For tests that
must skip or assert something engine-specific, the trait exposes
`self::driver()` and `self::isDriverOneOf('pdo_sqlsrv', ...)`.

CI runs the Database suite against MySQL 8.4, PostgreSQL 16 and SQL Server
2022 on every push.

## Writing a unit test

```php
// tests/Unit/Entity/UserTest.php
namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testRolesAlwaysContainRoleUser(): void
    {
        $user = new User();
        $user->setRoles([]);

        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testEnabledByDefault(): void
    {
        $user = new User();
        $this->assertTrue($user->isEnabled());
    }
}
```

## The test environment

Set `APP_ENV=test` to activate the test environment. The Kernel will use `ArrayAdapter` for Doctrine's metadata and query caches instead of `FilesystemAdapter`, keeping tests fast.

If you create `config/test/doctrine.php`, the console will use it when you pass `--env=test`. This is useful for running migrations against an in-memory SQLite database.

```php
// config/test/doctrine.php
use Modufolio\Appkit\Doctrine\OrmConfigurator;

return function (OrmConfigurator $orm) use ($projectDir): void {
    $orm->connection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ])->entities($projectDir . '/src/Entity');
};
```

```bash
php bin/console orm:schema-tool:create --env=test
vendor/bin/phpunit
```

## `EntityFactory`

`Modufolio\Appkit\Doctrine\EntityFactory` creates and persists test fixtures.

```php
use Modufolio\Appkit\Doctrine\EntityFactory;
use App\Entity\User;

$factory = new EntityFactory(
    entityManager: $em,
    serializer:    $serializer,
    validator:     $validator,
);

// Create and persist one entity
$factory->create(User::class, [
    'email'    => 'test@example.com',
    'password' => 'hashed-password',
    'roles'    => ['ROLE_USER'],
])->store();

// Create many entities
$factory->createMany(User::class, 10, function (int $i): array {
    return [
        'email' => "user{$i}@example.com",
    ];
})->store();
```

Pass a config file to predefine factory defaults:

```php
$factory->loadConfig([
    User::class => [
        'roles'   => ['ROLE_USER'],
        'enabled' => true,
    ],
]);
```

Override specific fields per instance:

```php
$factory->create(User::class, ['email' => 'admin@example.com'])
    ->withResolverArgs(['roles' => ['ROLE_ADMIN']])
    ->store();
```

## `TestResponse`

`Modufolio\Appkit\Tests\Response\TestResponse` wraps a PSR-7 `ResponseInterface` and provides a fluent assertion API inspired by Laravel's `TestResponse`. Use it in feature tests to assert HTTP responses without parsing raw headers or body strings.

The recommended shape for feature tests is: boot the *real* app once via your `AppFactory`, build PSR-7 requests, pass them to `$app->handle()`, and assert on the wrapped response — no mocking of framework internals. AppKit's own suite does exactly this; its [`tests/Case/AppTestCase.php`](https://github.com/modufolio/appkit/blob/main/tests/Case/AppTestCase.php) (with `get()`/`post()` helpers, automatic session cookies and CSRF headers, and an `actingAs()` login helper) is the reference implementation to copy into your project.

```php
use Modufolio\Appkit\Tests\Response\TestResponse;

$response = new TestResponse($this->app->handle($request));

$response->assertStatus(200);
$response->assertHeader('Content-Type', 'application/json');
```

### Status and redirect assertions

```php
$response->assertStatus(200);
$response->assertStatus(422);
$response->assertRedirect('/login');
```

`assertRedirect()` checks that the status is a 3xx code and that the `Location` header matches the given URL.

### Header assertions

```php
$response->assertHeader('X-Custom-Header', 'value');
```

### Inertia assertions

When the response is an Inertia JSON response, chain into Inertia-specific assertions:

```php
$response
    ->assertStatus(200)
    ->assertInertia()
    ->component('Dashboard')
    ->hasProp('user')
    ->whereProp('user.email', 'test@example.com')
    ->whereProp('stats.count', 42);
```

| Method | Description |
|--------|-------------|
| `assertInertia()` | Assert the response is an Inertia response; returns `$this` for chaining |
| `component(string $name)` | Assert the rendered component name |
| `hasProp(string $key)` | Assert a prop key exists (dot notation supported) |
| `whereProp(string $key, mixed $value)` | Assert a prop value (dot notation supported) |

### Debugging

```php
$response->dump(); // print response body and continue
$response->dd();   // print and exit
```

---

## `DatabaseTestingCapabilities`

`Modufolio\Appkit\Tests\Traits\DatabaseTestingCapabilities` is a PHPUnit trait that adds query tracking, database assertions, fixture seeding, and performance monitoring to any test class. It registers its hooks with `#[Before]` and `#[After]` so no `setUp()`/`tearDown()` wiring is needed.

```php
use Modufolio\Appkit\Tests\Traits\DatabaseTestingCapabilities;
use PHPUnit\Framework\TestCase;

final class UserFeatureTest extends TestCase
{
    use DatabaseTestingCapabilities;

    // ...
}
```

### Seeding fixtures

Assign rows to `$this->fixtures` before the test runs, or call `seed()` inside the test body:

```php
// Declarative — set before the test
$this->fixtures = [
    'users' => [
        ['email' => 'alice@example.com', 'roles' => '["ROLE_USER"]'],
        ['email' => 'bob@example.com',   'roles' => '["ROLE_ADMIN"]'],
    ],
];

// Imperative — call inside the test
$this->seed('users', [
    ['email' => 'charlie@example.com'],
]);
```

### Database assertions

```php
$this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
$this->assertDatabaseMissing('users', ['email' => 'deleted@example.com']);
$this->assertDatabaseCount('users', 2);
```

### Query count assertions

```php
$this->assertQueryCount(3);               // total queries executed
$this->assertQueryCount(1, 'SELECT');     // only SELECT queries
```

### Query pattern assertions

Use a regex pattern to assert that a specific query ran — or did not run:

```php
$this->assertQueryExecuted('/SELECT.*FROM users/', 2);
$this->assertQueryNotExecuted('/DELETE/');
```

### Table-level assertions

```php
$this->assertTableQueried('users', 'SELECT');   // table was SELECTed
$this->assertTableNotQueried('sessions');        // table was never touched
```

### Performance assertions

```php
$this->assertNoSlowQueries();                              // no query exceeded the threshold
$this->assertQueryPerformance('/SELECT.*users/', 0.05);    // pattern must complete in < 50 ms
```

Set the slow query threshold (default 1.0 s):

```php
$this->setSlowQueryThreshold(0.5); // queries over 500 ms are "slow"
```

### Performance report

```php
$report = $this->getPerformanceReport();
// ['total_queries' => 4, 'slow_queries' => 0, 'total_time' => 0.012, ...]
```

### Snapshots

`withAutoSnapshot()` saves the database state before the test and restores it after, giving you full isolation without rebuilding the schema:

```php
$this->withAutoSnapshot();
```

### Inspecting the query log

```php
$this->dumpQueryLog();                       // print all recorded queries
$log = $this->getQueryLog('SELECT', 'users'); // filter by type and table
```

---

## Static analysis with PHPStan

```bash
composer stan
```

PHPStan runs at level 8 — the framework and the skeleton both hold that level. The config file is `phpstan.php` in the project root:

```php
return [
    'parameters' => [
        'level' => 8,
        'paths' => ['src'],
    ],
];
```

If level 8 is too strict for a legacy codebase you are migrating, lower `level` and raise it back incrementally.

Fix errors before committing. PHPStan catches type mismatches, undefined variables, and unreachable code that tests might miss.

## Code style with PHP-CS-Fixer

```bash
composer fix
```

Reformats all PHP files in `src/` to match the configured coding standard. Run this before every commit to keep the diff clean.

Check what would change without modifying files:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## CI pipeline

A reliable CI pipeline runs these steps in order. Note that CI installs **with** dev dependencies — PHPUnit and PHPStan live in `require-dev`; `--no-dev` belongs to the *deploy* build, not the test run:

```bash
# 1. Install dependencies (including require-dev, for phpunit + phpstan)
composer install --optimize-autoloader

# 2. Install Node.js dependencies and build assets
npm ci
npm run build

# 3. Create the database schema (or run migrations)
php bin/console migrations:migrate --no-interaction

# 4. Run the test suite
vendor/bin/phpunit

# 5. Run static analysis
vendor/bin/phpstan analyse
```

For the artifact you actually deploy, build separately with `composer install --no-dev --optimize-autoloader`.

## Test database isolation

Each test that touches the database should use a transaction rollback or rebuild the schema from scratch between test runs. A simple approach with in-memory SQLite:

```php
protected function setUp(): void
{
    // Rebuild schema before each test
    $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
    $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
}

protected function tearDown(): void
{
    $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
    $schemaTool->dropDatabase();
}
```
