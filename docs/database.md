# Database

AppKit integrates Doctrine ORM for entity mapping and persistence, a fluent `QueryBuilder` for raw DBAL queries, and a `DoctrineOrmPagination` helper for paginated results.

## Configuring Doctrine

`config/doctrine.php` returns a closure that receives an `OrmConfigurator` instance.

```php
// config/doctrine.php
use Modufolio\Appkit\Doctrine\OrmConfigurator;

return function (OrmConfigurator $orm) use ($projectDir): void {
    $orm->connection([
        'driver' => 'pdo_sqlite',
        'path'   => $projectDir . '/database/data.db',
    ])->entities(
        $projectDir . '/src/Entity'
    );
};
```

`entities()` is variadic — pass multiple paths to scan entity classes from more than one directory. Paths are additive; calling `entities()` again appends rather than replaces.

```php
$orm->connection([...])->entities(
    $projectDir . '/src/Entity',
    $projectDir . '/packages/billing/src/Entity',
    $projectDir . '/packages/cms/src/Entity',
);
```

### Switching to MySQL or PostgreSQL

```php
$orm->connection([
    'driver'   => 'pdo_mysql',
    'host'     => getenv('DB_HOST'),
    'dbname'   => getenv('DB_NAME'),
    'user'     => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'charset'  => 'utf8mb4',
])->entities($projectDir . '/src/Entity');
```

```php
$orm->connection([
    'driver'   => 'pdo_pgsql',
    'host'     => getenv('DB_HOST'),
    'dbname'   => getenv('DB_NAME'),
    'user'     => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
])->entities($projectDir . '/src/Entity');
```

### `OrmConfigurator` methods

| Method | Description |
|--------|-------------|
| `connection(array $params)` | Set DBAL connection parameters |
| `entities(string ...$paths)` | Scan one or more directories for entity classes (variadic — pass as many paths as you need) |
| `addFilter(string $name, string $class)` | Register a Doctrine filter (e.g. soft delete) |
| `middlewares(array $middlewares)` | Add DBAL middleware stack entries |
| `addSubscriber(EventSubscriber $subscriber)` | Register a Doctrine event subscriber |
| `cache(?CacheItemPoolInterface $metadata, ?CacheItemPoolInterface $query, ?CacheItemPoolInterface $result)` | Configure caches for metadata, queries, and results |

## Defining an entity

Entity classes live in `src/Entity/`. Use Doctrine PHP attributes for mapping.

```php
// src/Entity/Post.php
namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    private string $body = '';

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }
    // ...
}
```

## Defining a repository

Repositories extend `Doctrine\ORM\EntityRepository` and are registered in `config/repositories.php`.

```php
// src/Repository/PostRepository.php
namespace App\Repository;

use App\Entity\Post;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Post>
 */
class PostRepository extends EntityRepository
{
    public function findPublished(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.published = true')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
```

Register it:

```php
// config/repositories.php
return [
    PostRepository::class => Post::class,
];
```

## Accessing the entity manager

Inject `EntityManagerInterface` via `config/controllers.php`:

```php
// config/controllers.php
use Doctrine\ORM\EntityManagerInterface;

return [
    PostController::class => [
        EntityManagerInterface::class,
    ],
];
```

In the controller:

```php
class PostController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function create(#[MapRequestPayload] CreatePostDto $dto): ResponseInterface
    {
        $post = new Post();
        $post->setTitle($dto->title);
        $post->setBody($dto->body);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return Response::redirect($this->urlGenerator->generate('post.show', ['id' => $post->getId()]));
    }
}
```

## Schema management

Two approaches — choose based on your situation.

**New projects (development):**

```bash
php bin/console orm:schema-tool:create
```

Creates tables directly from entity definitions. Fast for greenfield setup but does not create migration files.

**Existing databases (production and teams):**

```bash
# Generate a migration from the diff between entities and current schema
php bin/console migrations:diff

# Review the generated file in database/migrations/
# Then apply it
php bin/console migrations:migrate
```

> Never run `orm:schema-tool:create` against an existing database. Use `migrations:migrate` for any database that already has data.

## Migrations

```bash
php bin/console migrations:diff       # Generate migration from entity changes
php bin/console migrations:migrate    # Run all pending migrations
php bin/console migrations:status     # Show current version and pending count
php bin/console migrations:list       # List all migrations and their status
php bin/console migrations:execute    # Run a specific migration
```

Migrations are stored in `database/migrations/`. Each class extends `AbstractMigration` and implements `up()` and `down()`.

```php
// database/migrations/Version20260507194151.php
public function up(Schema $schema): void
{
    $this->addSql('CREATE TABLE posts (id INTEGER NOT NULL, title VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
}

public function down(Schema $schema): void
{
    $this->addSql('DROP TABLE posts');
}
```

## The `QueryBuilder`

`Modufolio\Appkit\Doctrine\QueryBuilder` is a fluent wrapper around DBAL's query builder for raw SQL queries without ORM overhead. The API is inspired by the [Laravel Query Builder](https://laravel.com/docs/queries).

```php
use Modufolio\Appkit\Doctrine\QueryBuilder;

$qb = new QueryBuilder($this->entityManager->getConnection());

$posts = $qb->from('posts')
    ->select('id', 'title', 'created_at')
    ->where('published', '=', true)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

### Method reference

| Method | Description |
|--------|-------------|
| `from(string $table, ?string $alias)` | Set the table |
| `select(string ...$columns)` | Select columns |
| `selectRaw(string $expr, array $bindings)` | Select a raw expression |
| `where(string $col, string $op, mixed $val)` | Add a WHERE condition |
| `orWhere(string $col, string $op, mixed $val)` | Add an OR WHERE condition |
| `whereIn(string $col, array $values)` | WHERE IN clause |
| `whereNotIn(string $col, array $values)` | WHERE NOT IN clause |
| `whereNull(string $col)` | WHERE IS NULL |
| `whereNotNull(string $col)` | WHERE IS NOT NULL |
| `whereRaw(string $expr, array $bindings)` | Raw WHERE expression |
| `join(...)` | INNER JOIN |
| `leftJoin(...)` | LEFT JOIN |
| `rightJoin(...)` | RIGHT JOIN |
| `orderBy(string $col, string $dir)` | ORDER BY |
| `groupBy(string ...$cols)` | GROUP BY |
| `limit(int $n)` | LIMIT |
| `offset(int $n)` | OFFSET |
| `insert(array $values)` | INSERT — returns affected rows |
| `update(array $values)` | UPDATE — returns affected rows |
| `delete()` | DELETE — returns affected rows |
| `get()` | Fetch all rows as array |
| `first()` | Fetch first row or null |
| `count()` | Fetch count |
| `fetchColumn(string $col)` | Fetch a single column as array |
| `toSql()` | Return the SQL string |

## Pagination

`DoctrineOrmPagination` paginates a Doctrine `Query` object.

```php
use Modufolio\Appkit\Doctrine\DoctrineOrmPagination;

$query = $this->entityManager
    ->createQuery('SELECT p FROM App\Entity\Post p ORDER BY p.createdAt DESC');

$pagination = (new DoctrineOrmPagination())->paginate($query, page: $page, limit: 20);

$items   = $pagination->getResults();
$total   = $pagination->total();
$pages   = $pagination->pages();
$hasPrev = $pagination->hasPrevPage();
$hasNext = $pagination->hasNextPage();
$range   = $pagination->range(5); // array of page numbers around current page
```

### `DoctrineOrmPagination` methods

| Method | Returns | Description |
|--------|---------|-------------|
| `paginate(Query, int $page = 1, int $limit = 10)` | `self` | Instance method; populates and returns `$this` |
| `getResults()` | `array` | Current page items |
| `total()` | `int` | Total item count |
| `limit()` | `int` | Items per page |
| `page()` | `int` | Current page number |
| `pages()` | `int` | Total page count |
| `offset()` | `int` | Current page offset |
| `firstPage()` | `int` | Always `1` |
| `lastPage()` | `int` | Same as `pages()` |
| `isFirstPage()` | `bool` | |
| `isLastPage()` | `bool` | |
| `hasPrevPage()` | `bool` | |
| `prevPage()` | `?int` | |
| `hasNextPage()` | `bool` | |
| `nextPage()` | `?int` | |
| `range(int $range = 5)` | `array` | Page numbers centred on current page |
| `hasPages()` | `bool` | `true` if more than one page |
| `start()` | `int` | Index of first item on current page |
| `end()` | `int` | Index of last item on current page |

## Soft delete

Register `SoftDeleteFilter` to exclude soft-deleted records from all queries automatically.

```php
// config/doctrine.php
$orm->addFilter('soft_delete', \Modufolio\Appkit\Doctrine\Filter\SoftDeleteFilter::class);
```

Your entity needs a `deletedAt` column. Add a `#[ORM\Column(nullable: true)]` property of type `DateTimeImmutable`. When `deletedAt` is not null, the record is hidden from queries while the filter is enabled.

Enable or disable the filter at runtime:

```php
$this->entityManager->getFilters()->enable('soft_delete');
$this->entityManager->getFilters()->disable('soft_delete');
```

## DQL queries

```php
// Direct DQL
$users = $this->entityManager
    ->createQuery('SELECT u FROM App\Entity\User u WHERE u.enabled = true')
    ->getResult();

// With parameters
$user = $this->entityManager
    ->createQuery('SELECT u FROM App\Entity\User u WHERE u.email = :email')
    ->setParameter('email', $email)
    ->getOneOrNullResult();
```

For quick SQL inspection during development:

```bash
php bin/console dbal:run-sql "SELECT COUNT(*) FROM users"
```
