<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Module;

use Modufolio\Appkit\Module\ModuleRegistry;
use Modufolio\Appkit\Tests\App\Module\Bare\BareModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;
use PHPUnit\Framework\TestCase;

class ModuleRegistryTest extends TestCase
{
    private const MANIFEST = __DIR__.'/../../fixtures/config/modules.php';

    protected function setUp(): void
    {
        ModuleRegistry::reset();
    }

    protected function tearDown(): void
    {
        ModuleRegistry::reset();
    }

    public function testLoadNormalizesBareClassAndClassConfigEntries(): void
    {
        $modules = ModuleRegistry::load('base-a', self::MANIFEST);

        $this->assertCount(2, $modules);
        $this->assertInstanceOf(DemoModule::class, $modules[0]);
        $this->assertInstanceOf(BareModule::class, $modules[1]);

        $this->assertSame(['per_page' => 25], ModuleRegistry::configFor('base-a', $modules[0]));
        $this->assertSame([], ModuleRegistry::configFor('base-a', $modules[1]));
    }

    public function testModulesReturnsTheSameInstancesAsLoad(): void
    {
        $loaded = ModuleRegistry::load('base-a', self::MANIFEST);

        $this->assertSame($loaded, ModuleRegistry::modules('base-a'));
    }

    public function testAMissingManifestYieldsNoModules(): void
    {
        $this->assertSame([], ModuleRegistry::modules('/nowhere'));
    }

    public function testDuplicateModuleNamesFailLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate module name "demo"/');

        ModuleRegistry::load('base-dup', __DIR__.'/../../fixtures/module-manifests/duplicate.php');
    }

    public function testAnUnknownModuleClassFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        ModuleRegistry::load('base-bad', __DIR__.'/../../fixtures/module-manifests/missing-class.php');
    }

    public function testAllManifestMistakesAreReportedInOneAggregateError(): void
    {
        try {
            ModuleRegistry::load('base-agg', __DIR__.'/../../fixtures/module-manifests/aggregate.php');
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Invalid module manifest', $e->getMessage());
            $this->assertStringContainsString('NopeModule" does not exist', $e->getMessage());
            $this->assertStringContainsString('Duplicate module name "demo"', $e->getMessage());
        }
    }

    public function testRequiresIsSatisfiedByAnEarlierManifestEntry(): void
    {
        $modules = ModuleRegistry::load('base-req', __DIR__.'/../../fixtures/module-manifests/requires-ok.php');

        $this->assertCount(2, $modules);
    }

    public function testRequiresFailsWhenTheDependencyIsNotListed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/requires .*DemoModule", which is not listed/');

        ModuleRegistry::load('base-req-missing', __DIR__.'/../../fixtures/module-manifests/requires-missing.php');
    }

    public function testRequiresFailsWhenTheDependencyIsListedAfterTheRequirer(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must be listed before it/');

        ModuleRegistry::load('base-req-order', __DIR__.'/../../fixtures/module-manifests/requires-order.php');
    }

    public function testPathCollectorsAggregateAcrossModules(): void
    {
        ModuleRegistry::load('base-a', self::MANIFEST);
        $demoPath = (new DemoModule())->path();

        $this->assertSame([$demoPath.'/Entity'], ModuleRegistry::entityPaths('base-a'));
        $this->assertSame([$demoPath.'/Controller'], ModuleRegistry::controllerPaths('base-a'));
        $this->assertSame([$demoPath.'/Migrations'], ModuleRegistry::migrationPaths('base-a'));
        $this->assertSame([], ModuleRegistry::templatePaths('base-a'));
    }

    public function testMigrationNamespacesMapNamespaceToDirectory(): void
    {
        ModuleRegistry::load('base-a', self::MANIFEST);

        $this->assertSame(
            ['Modufolio\Appkit\Tests\App\Module\Demo\Migrations' => (new DemoModule())->path().'/Migrations'],
            ModuleRegistry::migrationNamespaces('base-a')
        );
    }
}
