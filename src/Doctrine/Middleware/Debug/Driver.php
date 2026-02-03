<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Doctrine\Middleware\Debug;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class Driver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private readonly DebugStack $debugStack,
    ) {
        parent::__construct($driver);
    }

    public function getDebugStack(): DebugStack
    {
        return $this->debugStack;
    }

    public function connect(
        #[SensitiveParameter]
        array $params
    ): Connection {
        $start      = Query::start();
        $connection = new Connection(
            parent::connect($params),
            $this->debugStack,
        );
        $elapsed    = Query::end($start);

        $this->debugStack->append(new Query(
            'CONNECT',
            $params,
            [],
            $elapsed,
        ));

        return $connection;
    }
}
