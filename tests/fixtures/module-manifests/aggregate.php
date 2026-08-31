<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;

// Three mistakes at once: an unknown class, and a duplicate name.
return [
    'Modufolio\Appkit\Tests\App\Module\DoesNotExist\NopeModule',
    DemoModule::class,
    DemoModule::class => ['per_page' => 1],
];
