<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

/**
 * A request body over the limit the application accepts. Extends the PSR-7
 * package's exception of the same name, which its body parsers throw, so the
 * exception handler maps both to 413 through one registration.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class PayloadTooLargeException extends \Modufolio\Psr7\Http\Exception\PayloadTooLargeException
{
}
