# Console

AppKit's console is powered by Symfony Console. Run it with:

```bash
php bin/console
```

This prints a list of all available commands.

## How the console is bootstrapped

The console is a **separate bootstrap from the HTTP app**. It does not call `AppFactory::create()` and does not use the AppKit kernel or its DI container. There is no `config/interfaces.php`, `config/factories.php`, or `config/controllers.php` involved.

`bin/console` loads `config/console.php`, which creates a `ConsoleRunner` and registers commands manually:

```php
// config/console.php
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

`ConsoleRunner` builds its own `EntityManager` directly from `config/doctrine.php`. All command dependencies are instantiated explicitly — there is no container to call `get()` on.

## Built-in commands

### App commands

| Command | Description |
|---------|-------------|
| `app:add-user [email] [password]` | Create a new user |

### Router commands

| Command | Description |
|---------|-------------|
| `debug:router [name]` | List all registered routes, optionally filtered by name |
| `debug:controllers` | List controllers with their resolved dependencies |

### ORM / schema commands

| Command | Description |
|---------|-------------|
| `orm:schema-tool:create` | Create schema from entities |
| `orm:schema-tool:update --force` | Update existing schema to match entities |
| `orm:schema-tool:drop --force` | Drop all tables |
| `orm:validate-schema` | Check consistency between entities and database schema |
| `orm:info` | List all mapped entity classes |
| `dbal:run-sql "SQL"` | Execute a raw SQL query |

### Migration commands

| Command | Description |
|---------|-------------|
| `migrations:diff` | Generate a migration from entity changes |
| `migrations:migrate` | Run all pending migrations |
| `migrations:status` | Show current version and pending migrations |
| `migrations:list` | List all migrations and their status |
| `migrations:execute` | Run a specific migration version |
| `migrations:generate` | Create a blank migration class |

### Maker commands

| Command | Description |
|---------|-------------|
| `make:entity` | Scaffold an entity and repository class interactively |

## The `app:add-user` command

Creates a new user. Works interactively (prompts for missing values) or non-interactively.

```bash
# Interactive
php bin/console app:add-user

# Non-interactive
php bin/console app:add-user user@example.com secretpassword

# Create an admin
php bin/console app:add-user admin@example.com secretpassword --admin

# Custom roles
php bin/console app:add-user editor@example.com secretpassword --roles ROLE_EDITOR
```

The command validates the email format, checks for uniqueness, requires a minimum 8-character password, hashes it with Argon2id, and persists the user via Doctrine.

## `make:entity`

Scaffolds a Doctrine entity and its repository. Run it and follow the prompts to define fields. The maker command is partly ported from the [Symfony MakerBundle](https://symfony.com/bundles/SymfonyMakerBundle/current/index.html).

```bash
php bin/console make:entity
```

It will ask for:
- Entity class name
- Field names, types, and constraints

The generated files go to `src/Entity/` and `src/Repository/`. Review them before running a migration — the generator makes sensible defaults but you may want to adjust nullable fields, cascade options, or indexes.

## Writing a custom command

Create a class in `src/Command/` and use the `#[AsCommand]` attribute.

```php
// src/Command/SendNewsletterCommand.php
namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name:        'app:send-newsletter',
    description: 'Sends the newsletter to all subscribed users',
)]
class SendNewsletterCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('subject', InputArgument::REQUIRED, 'Email subject line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subject = $input->getArgument('subject');

        // ... do the work

        $output->writeln("Newsletter sent: $subject");

        return Command::SUCCESS;
    }
}
```

Return `Command::SUCCESS` (0) when the command finishes cleanly, `Command::FAILURE` (1) on error.

### Interactive prompts

Use `interact()` to prompt for missing required arguments before `execute()` runs:

```php
protected function interact(InputInterface $input, OutputInterface $output): void
{
    if ($input->getArgument('subject') === null) {
        $input->setArgument(
            'subject',
            $this->getHelper('question')->ask($input, $output, new Question('Subject: '))
        );
    }
}
```

### `ConsoleStyle`

`Modufolio\Appkit\Console\ConsoleStyle` wraps Symfony's `SymfonyStyle` with AppKit-specific styling. It is available when you inject it or instantiate it from `$input` and `$output`.

```php
use Modufolio\Appkit\Console\ConsoleStyle;

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io = new ConsoleStyle($input, $output);

    $io->title('Sending newsletter');
    $io->info("Subject: {$input->getArgument('subject')}");

    // ... work ...

    $io->success('Newsletter sent.');

    return Command::SUCCESS;
}
```

## Registering a custom command

Commands are wired in `src/Console/ConsoleRunner.php`. Open `addDefaultCommands()` and instantiate your command with its dependencies directly — there is no container here, everything is constructed by hand.

```php
// src/Console/ConsoleRunner.php
public function addDefaultCommands(): self
{
    return $this->addCommands([
        new AddUserCommand(
            $this->entityManager(),
            new UserPasswordHasher(),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            $this->entityManager()->getRepository(User::class),
        ),
        new SendNewsletterCommand(
            $this->entityManager(),
            new Mailer(getenv('MAIL_DSN')), // instantiate directly, not from a container
        ),
        $this->createMakerCommand(),
        new RouterDebugCommand($this->router),
    ]);
}
```

`$this->entityManager()` is lazy — it reads `config/doctrine.php` and builds a fresh `EntityManager` on first call. For anything beyond the entity manager, construct services inline or add a private factory method to `ConsoleRunner`.

If you need to add command groups (like the ORM or migration commands), call `addOrmCommands()` and `addMigrationsCommands()` from `config/console.php` — or add a new public method to `ConsoleRunner` following the same pattern.

## Running in a specific environment

The console environment is selected by the `--env` option (or the `--test` shortcut), *not* by the `APP_ENV` environment variable — setting `APP_ENV=test` in your shell does not change which config the console loads.

```bash
php bin/console migrations:migrate --env=test
php bin/console migrations:migrate --test          # shortcut for --env=test
```

When run with `--env=test`, the console loads a separate Doctrine config from `config/test/doctrine.php` if it exists — useful for running migrations against a test database without touching production data.

## Doctrine migrations config

Migrations are configured in `config/migrations.php`:

```php
return [
    'table_storage' => [
        'table_name' => 'migrations',
    ],
    'migrations_paths' => [
        'Database\Migrations' => 'database/migrations',
    ],
    'all_or_nothing'  => false,
    'transactional'   => true,
];
```

`all_or_nothing: false` means each migration is its own transaction (`transactional: true`). A failure in one migration does not roll back previously successful ones.
