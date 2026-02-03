<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Doctrine\Middleware\Debug;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

final readonly class DebugMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DebugStack $debugStack,
    ) {
    }

    public function wrap(DriverInterface $driver): Driver
    {
        return new Driver($driver, $this->debugStack);
    }
}
