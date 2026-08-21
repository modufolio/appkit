<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Exception thrown when a class or a value is not found in the container.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class NotFoundException extends \Exception implements NotFoundExceptionInterface
{
}
