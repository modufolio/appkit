<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A response is final and about to be emitted.
 *
 * Dispatched as the last step of response preparation, after the profiler
 * has collected — for every request, including one that ended in the
 * exception handler. Request logging, metrics and the debug toolbar's
 * bookkeeping listen here rather than wrapping `handle()`.
 *
 * The response is the one being sent; a listener cannot replace it. Duration
 * is measured from the server's `REQUEST_TIME_FLOAT`, so it includes the
 * time before the kernel saw the request; null when the SAPI did not
 * provide one.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class RequestHandledEvent
{
    public function __construct(
        public ServerRequestInterface $request,
        public ResponseInterface $response,
        public ?float $durationMs,
    ) {
    }
}
