<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Command;

use Modufolio\Appkit\Command\ModulesListCommand;
use Modufolio\Appkit\Core\Environment;
use Modufolio\Appkit\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ModulesListCommandTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleRegistry::reset();
    }

    protected function tearDown(): void
    {
        ModuleRegistry::reset();
    }

    public function testListsTheManifestModules(): void
    {
        // The fixture manifest lives at tests/fixtures/config/modules.php
        // relative to this baseDir — the registry's conventional location
        // is preloaded here the way AppFactory does it.
        $baseDir = \dirname(__DIR__, 2).'/..';
        ModuleRegistry::load($baseDir, \dirname(__DIR__, 2).'/fixtures/config/modules.php');

        $tester = new CommandTester(new ModulesListCommand($baseDir));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('demo', $display);
        $this->assertStringContainsString('DemoModule', $display);
        $this->assertStringContainsString('per_page', $display);
        $this->assertStringContainsString('1 controllers', $display);
        $this->assertStringContainsString('3 module(s)', $display);
    }

    public function testAnEmptyManifestSaysSoInsteadOfPrintingAnEmptyTable(): void
    {
        $tester = new CommandTester(new ModulesListCommand('/nowhere'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No modules registered', $tester->getDisplay());
    }

    public function testShowsTheEnvironmentGateAndTheEntriesLeftOut(): void
    {
        $baseDir = \dirname(__DIR__, 2).'/..';
        ModuleRegistry::load($baseDir, \dirname(__DIR__, 2).'/fixtures/config/modules_envs.php', Environment::TEST);

        $tester = new CommandTester(new ModulesListCommand($baseDir));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('dev, test', $display, 'The active module shows its gate.');
        $this->assertStringContainsString('BareModule', $display, 'The gated-out entry is still listed…');
        $this->assertStringContainsString('not in this environment', $display, '…marked as left out.');
        $this->assertStringContainsString('1 module(s)', $display);
    }
}
