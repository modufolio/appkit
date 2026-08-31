<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Module\Bare\BareModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;

return [
    DemoModule::class => ['per_page' => 25],
    BareModule::class,
];
