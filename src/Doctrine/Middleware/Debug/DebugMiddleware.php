<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine\Middleware\Debug;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

/**
 * @author    Filippo Tessarotto <zoeslam@gmail.com>
 *
 * @see       https://github.com/Slamdunk/dbal-debugstack-middleware
 *
 * @copyright Filippo Tessarotto
 * @license   https://opensource.org/licenses/MIT
 */
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
