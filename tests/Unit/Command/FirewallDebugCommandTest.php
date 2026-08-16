<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Command;

use Modufolio\Appkit\Command\FirewallDebugCommand;
use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class FirewallDebugCommandTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Configure a known security setup so the test does not depend on
        // shared-app state left by other test classes in the same process.
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => ['pattern' => '/', 'authenticators' => ['form_login'], 'entry_point' => '/login'],
            ],
            'access_control' => [
                ['path' => '/admin', 'roles' => ['ROLE_ADMIN']],
            ],
            'role_hierarchy' => [
                'ROLE_ADMIN' => ['ROLE_USER'],
            ],
        ]);
    }

    public function testListsConfiguredFirewalls(): void
    {
        $tester = new CommandTester(new FirewallDebugCommand($this->app()));

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Firewalls', $output);
        // The test fixture configures a 'main' firewall and a role hierarchy.
        $this->assertStringContainsString('main', $output);
        $this->assertStringContainsString('Role hierarchy', $output);
    }

    public function testDescribesASingleFirewall(): void
    {
        $tester = new CommandTester(new FirewallDebugCommand($this->app()));

        $tester->execute(['name' => 'main']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Firewall "main"', $tester->getDisplay());
    }

    public function testUnknownFirewallFails(): void
    {
        $tester = new CommandTester(new FirewallDebugCommand($this->app()));

        $tester->execute(['name' => 'does_not_exist']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testWarnsWhenNoFirewallsConfigured(): void
    {
        $app = $this->createMock(AppInterface::class);
        $app->method('getFirewalls')->willReturn([]);

        $tester = new CommandTester(new FirewallDebugCommand($app));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No firewalls', $tester->getDisplay());
    }

    public function testCommandMetadata(): void
    {
        $command = new FirewallDebugCommand($this->app());

        $this->assertSame('debug:firewall', $command->getName());
    }
}
