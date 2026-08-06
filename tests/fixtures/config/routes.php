<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    $routes->import('../../App/Controller/', 'attribute');
    $routes->import('routes/test.php', 'array');
    $routes->import('json_api.php', 'json_api');
};
