<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Command;

use Doctrine\DBAL\DriverManager;
use Modufolio\Appkit\Command\InfoCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class InfoCommandTest extends TestCase
{
    private function connection(): \Doctrine\DBAL\Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testPrintsEnvironmentAndDatabaseRows(): void
    {
        $tester = new CommandTester(new InfoCommand($this->connection()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('PHP', $display);
        $this->assertStringContainsString('SQLitePlatform', $display);
        $this->assertStringContainsString('Tables initialised', $display);
        $this->assertStringContainsString('no — run your migrations', $display);
    }

    public function testTablesInitialisedFlagAnswersThroughTheExitCode(): void
    {
        $connection = $this->connection();

        $tester = new CommandTester(new InfoCommand($connection));
        $this->assertSame(Command::FAILURE, $tester->execute(['--tables-initialised' => true]));

        $connection->executeStatement('CREATE TABLE probe (id INTEGER PRIMARY KEY)');
        $this->assertSame(Command::SUCCESS, $tester->execute(['--tables-initialised' => true]));
    }

    public function testWorksWithoutAConnection(): void
    {
        $tester = new CommandTester(new InfoCommand());
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('no connection configured', $tester->getDisplay());
    }
}
