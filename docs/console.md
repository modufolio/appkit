# Console

AppKit's console is powered by Symfony Console. Run it with:

```bash
php bin/console
```

This prints a list of all available commands.

## How the console is bootstrapped

The console is a **separate bootstrap from the HTTP app**. It does not go through the application factory and does not use the AppKit kernel or its DI container. There is no `config/services.php` or `config/controllers.php` involved.

Three framework commands are the exception, because what they inspect only exists once the app is configured: `debug:controllers`, `debug:firewall` and `security:validate` take the booted `AppInterface` in their constructor (`debug:controllers` also takes the router). Wiring them means booting the app inside the runner, so those three share the app's failure domain; the rest do not.

This is deliberate: **the console must not share a failure domain with the app it repairs.** A broken `services.php` closure, a circular dependency, a fatal in an `App` accessor, a security config that kills `boot()` — none of it can take the console down, because the console never touches any of it. The moment you most need `migrations:migrate` or `dbal:run-sql` is exactly the moment an app-coupled console would be dead too. (Frameworks whose CLI builds the full container have this failure mode: one bad service definition and the command that would fix it cannot start.)

Hand-constructing command dependencies (see [Registering a custom command](#registering-a-custom-command)) costs a few lines per command — that is the price of the isolation, and it extends inside the console too: each command wires itself, so one command's broken construction does not poison the rest. Keep the discipline even when it feels redundant: a command that reaches into the HTTP app's container for convenience re-couples the fates and gives the isolation away.

`bin/console` loads `config/console.php`, which creates a `ConsoleRunner` and registers commands manually:

```php
// config/console.php
use App\Console\ConsoleRunner;

require dirname(__DIR__) . '/bootstrap.php';

// Composer's autoloader returns the ClassLoader; the maker commands need it
$classLoader = require dirname(__DIR__) . '/vendor/autoload.php';

$console = new ConsoleRunner(
    classLoader: $classLoader,
    projectDir:  dirname(__DIR__),
);

$console->addDefaultCommands();
$console->addOrmCommands();
$console->addMigrationsCommands();

$console->run();
```

`ConsoleRunner` builds its own `EntityManager` directly from `config/doctrine.php`. All command dependencies are instantiated explicitly — there is no container to call `get()` on.

> **Application code.** `ConsoleRunner` is not part of the framework. The skeleton (`modufolio/appkit-skeleton`) ships a starting version in `src/Console/ConsoleRunner.php`; it is yours to change.

The framework's console surface is the commands themselves and `ConsoleStyle`; how they are wired belongs to the application.

## Built-in commands

### App commands

| Command | Description |
|---------|-------------|
| `app:info` | Show environment, database and schema status; `--tables-initialised` reports only whether the tables exist, as the exit code (0 = yes) |
| `app:add-user [email] [password]` | Create a new user — skeleton code, see [below](#the-appadd-user-command) |

### Module commands

| Command | Description |
|---------|-------------|
| `modules:list` | List the modules registered in `config/modules.php` |

### Router commands

| Command | Description |
|---------|-------------|
| `debug:router [name]` | List all registered routes, optionally filtered by name |
| `debug:controllers` | List controllers with their resolved dependencies |

### Security commands

| Command | Description |
|---------|-------------|
| `debug:firewall [name]` | Show firewalls, their restrictions, access-control rules and the role hierarchy; pass a name to detail one firewall |
| `security:validate` | Validate firewall and access-control configuration; reports the first problem in each of the two sections and exits non-zero if either had one (run it in CI/at deploy — see [Security](security.md#validating-configuration)) |

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

> **Application code.** `app:add-user` is not part of the framework. The skeleton (`modufolio/appkit-skeleton`) ships a starting version in `bundles/UserBundle/Command/AddUserCommand.php`; it is yours to change.

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

When it prompts, the command validates the email format, refuses an address that already exists, and requires a password of at least 8 characters; arguments passed on the command line skip those prompt-level checks. In both modes the new entity is run through the validator before it is persisted via Doctrine, and the password is hashed with the configured `UserPasswordHasherInterface` (Argon2id by default).

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

`Modufolio\Appkit\Console\ConsoleStyle` extends Symfony's `SymfonyStyle`, so every `SymfonyStyle` method is available, with AppKit-specific styling on top. It is available when you inject it or instantiate it from `$input` and `$output`.

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

> **Application code.** `ConsoleRunner` is not part of the framework. The skeleton (`modufolio/appkit-skeleton`) ships a starting version in `src/Console/ConsoleRunner.php`; it is yours to change.

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

> **Application code.** The `--env` and `--test` options are not part of the framework. The skeleton (`modufolio/appkit-skeleton`) defines them in `src/Console/ConsoleRunner.php`; they are yours to change.

The console's Doctrine config is selected by the `--env` option (or the `--test` shortcut), *not* by the `APP_ENV` environment variable — setting `APP_ENV=test` in your shell does not change which config the console loads.

```bash
php bin/console migrations:migrate --env=test
php bin/console migrations:migrate --test          # shortcut for --env=test
```

With `--env=<name>` the runner loads `config/<name>/doctrine.php` — `config/test/doctrine.php` for `--env=test` — instead of `config/doctrine.php`, and exits with an error if that file is missing. Useful for running migrations against a test database without touching production data.

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
