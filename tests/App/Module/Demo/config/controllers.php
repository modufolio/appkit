<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Module\Demo\Controller\DemoModuleController;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoService;

return [
    DemoModuleController::class => [
        DemoService::class,
    ],
];
