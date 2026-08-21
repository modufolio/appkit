<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

use Symfony\Component\Console\Exception\ExceptionInterface;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class RuntimeCommandException extends \RuntimeException implements ExceptionInterface
{
}
