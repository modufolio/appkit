<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Command;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The doctor: versions, database platform, and whether the schema is
 * initialised — the questions every "it doesn't work" report starts with.
 *
 * `--tables-initialised` answers only that, through the exit code, so an
 * install script can branch on it without parsing output:
 *
 *   bin/console app:info --tables-initialised && echo "ready"
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[AsCommand(name: 'app:info', description: 'Shows environment, database and schema status')]
final class InfoCommand extends Command
{
    public function __construct(private readonly ?Connection $connection = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'tables-initialised',
            null,
            InputOption::VALUE_NONE,
            'Report only whether database tables exist, as the exit code (0 = yes)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('tables-initialised')) {
            return $this->tablesInitialised() ? Command::SUCCESS : Command::FAILURE;
        }

        $rows = [
            ['AppKit', $this->packageVersion('modufolio/appkit')],
            ['PHP', \PHP_VERSION],
            ['OS', \PHP_OS_FAMILY.' ('.php_uname('r').')'],
            ['Memory limit', (string) \ini_get('memory_limit')],
            ['SAPI', \PHP_SAPI],
        ];

        foreach (['gd', 'imagick', 'redis', 'pdo_sqlite', 'pdo_mysql', 'pdo_pgsql'] as $extension) {
            if (\extension_loaded($extension)) {
                $rows[] = ['ext-'.$extension, phpversion($extension) ?: 'loaded'];
            }
        }

        if (null !== $this->connection) {
            try {
                $platform = $this->connection->getDatabasePlatform();
                $rows[] = ['Database platform', (new \ReflectionClass($platform))->getShortName()];
                $rows[] = ['Database version', $this->connection->getServerVersion()];
            } catch (\Throwable $e) {
                $rows[] = ['Database', '<error>unreachable: '.$e->getMessage().'</error>'];
            }

            $rows[] = ['Tables initialised', $this->tablesInitialised() ? 'yes' : 'no — run your migrations'];
        } else {
            $rows[] = ['Database', 'no connection configured for this command'];
        }

        $io->table(['Component', 'Value'], $rows);

        return Command::SUCCESS;
    }

    private function tablesInitialised(): bool
    {
        if (null === $this->connection) {
            return false;
        }

        try {
            return [] !== $this->connection->createSchemaManager()->listTableNames();
        } catch (\Throwable) {
            return false;
        }
    }

    private function packageVersion(string $package): string
    {
        try {
            return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
