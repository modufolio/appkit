<?php

declare(strict_types=1);

use Modufolio\Appkit\DependencyInjection\Symfony\PublicServicesPass;
use Modufolio\Appkit\Tests\App\Module\Demo\DependencyInjection\CollectGreetingsPass;
use Modufolio\Appkit\Tests\App\Symfony\Greeter;
use Modufolio\Appkit\Tests\App\Symfony\GreeterEndpoint;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The test application's Symfony definitions — the file an application
 * writes once it opted in with Kernel::configureContainer(). Plain Symfony
 * PHP DSL: the kernel's own services are already there to autowire.
 */
return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('greeting.prefix', 'Hello');

    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->bind('$prefix', '%greeting.prefix%');

    $services->load('Modufolio\\Appkit\\Tests\\App\\Symfony\\', '../../App/Symfony/');

    // Not named *Controller: a controller only through the tag.
    $services->get(GreeterEndpoint::class)->tag(PublicServicesPass::CONTROLLER_TAG);

    // Tagged for a pass the demo module owns: the application never names
    // the module, the module never names the application.
    $services->get(Greeter::class)->tag(CollectGreetingsPass::TAG);
};
