<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Demo;

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Module\AbstractModule;

/**
 * Fixture module exercising every convention: default config, a
 * config/services.php file, the loadServices() hook, path discovery and the
 * reset() lifecycle.
 */
final class DemoModule extends AbstractModule
{
    public static int $resets = 0;

    protected function defaultConfig(): array
    {
        return ['per_page' => 10, 'flavor' => 'plain'];
    }

    protected function loadServices(ServiceConfigurator $services, array $config): void
    {
        $services->set(DemoCounter::class, fn () => new DemoCounter($config['per_page']));
    }

    public function reset(): void
    {
        ++self::$resets;
    }
}
