<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Command;

use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sqlite:wal', description: 'SQLite: Write-Ahead Logging (WAL) mode')]
class WalCommand extends Command
{
    protected function getDatabasePath(): string
    {
        return BASE_DIR . '/database/data.db';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $databasePath = $this->getDatabasePath();

        if (file_exists($databasePath)) {
            // Enable WAL mode for SQLite
            $pdo = new PDO('sqlite:' . $databasePath);
            $pdo->exec('PRAGMA journal_mode=WAL;');
            $pdo = null; // Close the connection

            $output->writeln('Write-Ahead Logging (WAL) mode enabled for SQLite database.');
        } else {
            $output->writeln('Database file not found.');
        }

        return Command::SUCCESS;
    }
}
