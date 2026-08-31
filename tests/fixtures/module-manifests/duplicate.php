<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;

// Two entries resolving to the same module name ("demo").
return [
    DemoModule::class,
    DemoModule::class,
];
