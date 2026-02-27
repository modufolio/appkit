<?php

declare(strict_types = 1);


use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    $routes->import('../tests/App/Controller/', 'attribute');
    $routes->import('../config/routes/test.php', 'array');
    $routes->import('../config/json_api.php', 'json_api');
};
