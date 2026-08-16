<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Command;

use Modufolio\Appkit\Command\SecurityValidateCommand;
use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SecurityValidateCommandTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Known-good config, independent of shared-app state from other classes.
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => ['pattern' => '/', 'authenticators' => ['form_login'], 'entry_point' => '/login'],
            ],
            'access_control' => [
                ['path' => '/admin', 'roles' => ['ROLE_ADMIN']],
            ],
        ]);
    }

    public function testValidConfigurationPasses(): void
    {
        $tester = new CommandTester(new SecurityValidateCommand($this->app()));

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('is valid', $tester->getDisplay());
    }

    public function testAcceptsFirewallRestrictionKeys(): void
    {
        // methods/host/ips are honoured firewall restrictions, not errors.
        $app = $this->createMock(AppInterface::class);
        $app->method('getFirewalls')->willReturn([
            'main' => ['pattern' => '/api', 'methods' => ['GET'], 'host' => 'api.example.com'],
        ]);
        $app->method('getAccessControlRules')->willReturn([]);

        $tester = new CommandTester(new SecurityValidateCommand($app));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testRejectsFirewallWithWrongType(): void
    {
        $app = $this->createMock(AppInterface::class);
        $app->method('getFirewalls')->willReturn([
            'main' => ['pattern' => '/', 'stateless' => 'yes'],
        ]);
        $app->method('getAccessControlRules')->willReturn([]);

        $tester = new CommandTester(new SecurityValidateCommand($app));
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testRejectsMalformedAccessControlRule(): void
    {
        $app = $this->createMock(AppInterface::class);
        $app->method('getFirewalls')->willReturn([]);
        // roles must be a list of strings — a bare string fails open at runtime.
        $app->method('getAccessControlRules')->willReturn([
            ['path' => '/admin', 'roles' => 'ROLE_ADMIN'],
        ]);

        $tester = new CommandTester(new SecurityValidateCommand($app));
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Access-control rules', $tester->getDisplay());
    }

    public function testCommandMetadata(): void
    {
        $command = new SecurityValidateCommand($this->app());

        $this->assertSame('security:validate', $command->getName());
    }
}
