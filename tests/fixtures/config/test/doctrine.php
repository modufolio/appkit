<?php

declare(strict_types=1);

use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugMiddleware;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Doctrine\OrmConfigurator;

return function (OrmConfigurator $orm): void {
    $testsDir = dirname(__DIR__, 3);
    $orm->connection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ])
        ->entities(
            $testsDir.'/App/Entity'
        );

    $orm->middlewares([new DebugMiddleware(new DebugStack())]);
};
