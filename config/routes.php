<?php

declare(strict_types = 1);


use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {

    $routes->import('../config/routes/test.php', 'array');
};
