<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\TwoFactor;

/**
 * Marks an exception whose message is safe to show the person making the
 * request, and whose failure is a two-factor problem rather than a server
 * error — "that code was wrong", "you are locked out for another 40 seconds".
 *
 * The kernel's ExceptionHandler answers these with 422 and echoes the message
 * verbatim, in production as well as development. That is a promise the
 * exception makes by implementing this interface: no internal state, no
 * identifiers, nothing an anonymous caller should not read. Exceptions that
 * cannot promise it should not implement it — they fall through to the
 * RuntimeException / LogicException handlers, which hide their detail outside
 * dev, which is the right default for anything uncertain.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface TwoFactorExceptionInterface extends \Throwable
{
}
