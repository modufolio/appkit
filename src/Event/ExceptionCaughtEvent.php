<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event;

use Psr\Http\Message\ServerRequestInterface;

/**
 * An exception reached the exception handler.
 *
 * Dispatched before the handler maps it to a response, for every throwable
 * the handler sees — a 404 from the router as much as a bug. An error
 * reporter listens here and decides for itself what to forward; the handler
 * goes on to render whatever it would have rendered.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class ExceptionCaughtEvent
{
    public function __construct(
        public \Throwable $exception,
        public ServerRequestInterface $request,
    ) {
    }
}
