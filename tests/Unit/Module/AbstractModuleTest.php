<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Module;

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Tests\App\Module\Bare\BareModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoCounter;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoService;
use PHPUnit\Framework\TestCase;

class AbstractModuleTest extends TestCase
{
    public function testNameStripsTheModuleSuffixAndLowercases(): void
    {
        $this->assertSame('demo', (new DemoModule())->name());
        $this->assertSame('bare', (new BareModule())->name());
    }

    public function testPathIsTheDirectoryTheClassLivesIn(): void
    {
        $file = (new \ReflectionClass(DemoModule::class))->getFileName();
        $this->assertNotFalse($file);
        $this->assertSame(\dirname($file), (new DemoModule())->path());
    }

    public function testConfigBeforeServicesReturnsTheDefaults(): void
    {
        $this->assertSame(['per_page' => 10, 'flavor' => 'plain'], (new DemoModule())->config());
    }

    public function testServicesMergesDeclaredConfigOverDefaults(): void
    {
        $module = new DemoModule();
        $module->services(new ServiceConfigurator(), ['per_page' => 25]);

        $this->assertSame(['per_page' => 25, 'flavor' => 'plain'], $module->config());
    }

    public function testServicesLoadsTheModuleServicesFileWithTheMergedConfig(): void
    {
        $configurator = new ServiceConfigurator();
        (new DemoModule())->services($configurator, ['per_page' => 25]);

        // Declared in the module's config/services.php.
        $this->assertArrayHasKey(DemoService::class, $configurator->definitions);
        // Declared programmatically in loadServices().
        $this->assertArrayHasKey(DemoCounter::class, $configurator->definitions);
    }

    public function testUnknownDeclaredConfigKeysFailLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Unknown config key\(s\) \[per_pge\].*known keys: \[per_page, flavor\]/');

        (new DemoModule())->services(new ServiceConfigurator(), ['per_pge' => 25]);
    }

    public function testAModuleWithoutDefaultsAcceptsAnyConfig(): void
    {
        $module = new BareModule();
        $module->services(new ServiceConfigurator(), ['anything' => true]);

        $this->assertSame(['anything' => true], $module->config());
    }

    public function testControllersLoadsTheModuleControllerMap(): void
    {
        $this->assertSame(
            [\Modufolio\Appkit\Tests\App\Module\Demo\Controller\DemoModuleController::class => [DemoService::class]],
            (new DemoModule())->controllers()
        );
        $this->assertSame([], (new BareModule())->controllers());
    }

    public function testPathConventionsResolveOnlyExistingDirectories(): void
    {
        $demo = new DemoModule();
        $this->assertSame([$demo->path().'/Entity'], $demo->entityPaths());
        $this->assertSame([$demo->path().'/Controller'], $demo->controllerPaths());
        $this->assertSame([$demo->path().'/Migrations'], $demo->migrationPaths());
        $this->assertSame([], $demo->templatePaths());
        $this->assertSame([], $demo->translationPaths());

        $bare = new BareModule();
        $this->assertSame([], $bare->entityPaths());
        $this->assertSame([], $bare->controllerPaths());
        $this->assertSame([], $bare->migrationPaths());
    }
}
