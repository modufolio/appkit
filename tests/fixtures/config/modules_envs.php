<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Module\Bare\BareModule;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoModule;

/*
 * A manifest gating entries by environment: the reserved "envs" key sits next
 * to ordinary config and never reaches the module.
 */
return [
    DemoModule::class => ['envs' => ['dev', 'test'], 'per_page' => 25],
    BareModule::class => ['envs' => ['prod']],
];
