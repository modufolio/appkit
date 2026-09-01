<?php

declare(strict_types=1);

use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugMiddleware;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Doctrine\OrmConfigurator;

/**
 * SQLite in memory by default; set DB_DRIVER to run the same suite against a
 * real engine (see docker-compose.yml for ready-made servers):
 *
 *   docker compose up -d mysql
 *   DB_DRIVER=pdo_mysql DB_PORT=3308 DB_USER=root DB_PASSWORD=secret composer test:db
 *   DB_DRIVER=pdo_pgsql DB_PORT=5434 DB_USER=postgres DB_PASSWORD=secret composer test:db
 */
return function (OrmConfigurator $orm): void {
    $testsDir = dirname(__DIR__, 3);

    $driver = getenv('DB_DRIVER') ?: 'pdo_sqlite';

    if ('pdo_sqlite' === $driver) {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
    } else {
        $params = [
            'driver' => $driver,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'dbname' => getenv('DB_NAME') ?: 'appkit_test',
            'user' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ];

        if (getenv('DB_PORT') !== false && getenv('DB_PORT') !== '') {
            $params['port'] = (int) getenv('DB_PORT');
        }

        // ODBC driver 18 encrypts by default and rejects the self-signed
        // certificate a containerised SQL Server presents. String-keyed
        // driverOptions are appended to the DSN verbatim, hence '1'.
        if (str_contains($driver, 'sqlsrv')) {
            $params['driverOptions']['TrustServerCertificate'] = '1';
        }
    }

    $orm->connection($params)
        ->entities(
            $testsDir.'/App/Entity'
        );

    $orm->middlewares([new DebugMiddleware(new DebugStack())]);
};
