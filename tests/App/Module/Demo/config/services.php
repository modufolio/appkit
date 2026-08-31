<?php

declare(strict_types=1);

use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoService;

return function (ServiceConfigurator $services, array $config): void {
    $services->set(DemoService::class, fn () => new DemoService($config));
};
