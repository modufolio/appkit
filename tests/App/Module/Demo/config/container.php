<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Module\Demo\DemoSymfonyService;
use Modufolio\Appkit\Tests\App\Module\Demo\DependencyInjection\CollectGreetingsPass;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The module's bundle half. The kernel published the merged module config as
 * "module.demo" and each scalar key as "module.demo.<key>" before this loads.
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(DemoSymfonyService::class)
            ->args(['%module.demo.per_page%', '%module.demo.flavor%'])
            ->tag(CollectGreetingsPass::TAG)
            ->public();
};
