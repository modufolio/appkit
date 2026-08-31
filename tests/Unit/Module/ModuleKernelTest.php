<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Module;

use Modufolio\Appkit\Tests\App\Module\Demo\DemoCounter;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoService;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * The fixture app registers DemoModule and BareModule through
 * tests/fixtures/config/modules.php (see AppFactory::create()).
 */
class ModuleKernelTest extends AppTestCase
{
    public function testModuleServicesResolveThroughTheContainer(): void
    {
        $service = $this->app()->get(DemoService::class);

        $this->assertInstanceOf(DemoService::class, $service);
        // Manifest config merged over the module defaults.
        $this->assertSame(['per_page' => 25, 'flavor' => 'plain'], $service->config);

        $counter = $this->app()->get(DemoCounter::class);
        $this->assertInstanceOf(DemoCounter::class, $counter);
        $this->assertSame(25, $counter->perPage);
    }

    public function testMergedModuleConfigIsPublishedAsAParameter(): void
    {
        $this->assertSame(['per_page' => 25, 'flavor' => 'plain'], $this->app()->getParameter('module.demo'));
        $this->assertSame([], $this->app()->getParameter('module.bare'));
    }

    public function testResetModulesFansOutToEveryModule(): void
    {
        $before = DemoModule::$resets;
        $this->app()->resetModules();

        $this->assertSame($before + 1, DemoModule::$resets);
    }
}
