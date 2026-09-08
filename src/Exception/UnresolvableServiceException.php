<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

use Psr\Container\ContainerExceptionInterface;

/**
 * A service factory ran but could not build its service: the constructor it
 * calls has required arguments the factory did not pass.
 *
 * Thrown by the container when a factory closure raises \ArgumentCountError.
 * That is a wiring bug in services.php, controllers.php or a console runner
 * — never something a client did — so it extends \LogicException and maps to
 * a 500 with its detail hidden outside dev, like every other developer error.
 * Earlier versions wrapped the same failure in a bare \InvalidArgumentException,
 * which the exception handler answers with a 400 and echoes verbatim.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class UnresolvableServiceException extends \LogicException implements ContainerExceptionInterface
{
    public function __construct(
        public readonly string $serviceId,
        \ArgumentCountError $previous,
    ) {
        parent::__construct(
            \sprintf('Service "%s" cannot be built: %s', $serviceId, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
