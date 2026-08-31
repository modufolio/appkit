<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Needy;

use Modufolio\Appkit\Module\AbstractModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;

/**
 * Fixture module declaring a dependency, for requires() verification tests.
 */
final class NeedyModule extends AbstractModule
{
    public function requires(): array
    {
        return [DemoModule::class];
    }
}
