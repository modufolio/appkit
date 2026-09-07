<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Something a controller may return instead of a response: it becomes one
 * once it knows the request. The kernel calls toResponse() with the request
 * it is handling, after the controller has returned.
 *
 * The kernel converts an Inertia page itself; this is the generic seam for
 * anything else — a download that needs the request's range header, a
 * document that negotiates its format.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ResponsableInterface
{
    public function toResponse(ServerRequestInterface $request): ResponseInterface;
}
