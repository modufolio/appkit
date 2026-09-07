<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Module;

use Modufolio\Appkit\Core\Environment;
use Modufolio\Appkit\Module\ModuleRegistry;
use Modufolio\Appkit\Tests\App\Module\Bare\BareModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;
use PHPUnit\Framework\TestCase;

class ModuleRegistryTest extends TestCase
{
    private const MANIFEST = __DIR__.'/../../fixtures/config/modules.php';
    private const ENVS_MANIFEST = __DIR__.'/../../fixtures/config/modules_envs.php';

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

    public function testAnEntryIsLoadedOnlyInTheEnvironmentsItNames(): void
    {
        $modules = ModuleRegistry::load('base-a', self::ENVS_MANIFEST, Environment::TEST);

        $this->assertCount(1, $modules);
        $this->assertInstanceOf(DemoModule::class, $modules[0]);
        // The reserved key is the manifest's: the module never sees it.
        $this->assertSame(['per_page' => 25], ModuleRegistry::configFor('base-a', $modules[0]));
        $this->assertSame(['dev', 'test'], ModuleRegistry::environmentsFor('base-a', $modules[0]));
        $this->assertSame([['class' => BareModule::class, 'envs' => ['prod']]], ModuleRegistry::inactive('base-a'));

        ModuleRegistry::reset();
        $modules = ModuleRegistry::load('base-a', self::ENVS_MANIFEST, Environment::PROD);

        $this->assertCount(1, $modules);
        $this->assertInstanceOf(BareModule::class, $modules[0]);
        $this->assertSame([['class' => DemoModule::class, 'envs' => ['dev', 'test']]], ModuleRegistry::inactive('base-a'));
    }

    public function testAnUngatedEntryIsForEveryEnvironment(): void
    {
        $modules = ModuleRegistry::load('base-a', self::MANIFEST, Environment::PROD);

        $this->assertCount(2, $modules);
        $this->assertNull(ModuleRegistry::environmentsFor('base-a', $modules[0]));
        $this->assertSame([], ModuleRegistry::inactive('base-a'));
    }

    public function testTheEnvironmentDefaultsToAppEnv(): void
    {
        // PHPUnit runs the test app with APP_ENV=test.
        $modules = ModuleRegistry::load('base-a', self::ENVS_MANIFEST);

        $this->assertCount(1, $modules);
        $this->assertInstanceOf(DemoModule::class, $modules[0]);
    }

    public function testAnInvalidEnvsValueFailsLoudly(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'appkit-manifest');
        file_put_contents($file, sprintf(
            '<?php return [%s::class => ["envs" => "dev"], %s::class => ["envs" => ["staging"]]];',
            DemoModule::class,
            BareModule::class,
        ));

        try {
            ModuleRegistry::load('base-a', $file, Environment::DEV);
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('"envs" must be a non-empty list of environments (dev, test, prod)', $e->getMessage());
            $this->assertStringContainsString('unknown environment "staging" in "envs"', $e->getMessage());
        } finally {
            @unlink($file);
        }
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
