<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

use Modufolio\Psr7\Http\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Prepares HTTP responses before emission.
 *
 * Handles response finalization tasks such as:
 * - Setting Content-Length headers
 * - Handling HEAD requests
 * - Framework-specific integrations (Inertia.js support)
 * - Transfer encoding adjustments
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class PrepareResponse implements PrepareResponseInterface
{

    /**
     * Prepare the response before emission.
     *
     * @param ServerRequestInterface $request The server request
     * @param ResponseInterface $response The response to prepare
     * @return ResponseInterface The prepared response
     */
    public function prepare(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Remove Content-Length if Transfer-Encoding is chunked
        if ($response->hasHeader('Transfer-Encoding')) {
            $response = $response->withoutHeader('Content-Length');
        }

        // Set Content-Length if not present
        if (!$response->hasHeader('Content-Length')) {
            $length = $response->getBody()->getSize();
            if ($length !== null && !$response->hasHeader('Transfer-Encoding')) {
                $response = $response->withHeader('Content-Length', (string)$length);
            }
        }

        // HEAD method – clear content, preserve Content-Length
        if ($request->getMethod() === 'HEAD') {
            $len = $response->getHeaderLine('Content-Length');
            $response = $response->withBody(Stream::create(''));
            if ($len !== '') {
                $response = $response->withHeader('Content-Length', $len);
            }
        }

        // Inertia support
        if ($request->hasHeader('X-Inertia')) {
            if (
                $response->getStatusCode() === 302 &&
                in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)
            ) {
                $response = $response->withStatus(303);
            }

            $response = $response
                ->withHeader('Vary', 'Accept')
                ->withHeader('X-Inertia', 'true');
        }

        return $response;
    }
}
