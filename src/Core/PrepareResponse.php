<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Core;

use Modufolio\Appkit\Debug\ProfilerInterface;
use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\InertiaRenderer;
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
     * @param ProfilerInterface|null $profiler collects once the response is final —
     *                                         this is the last step of every handle(),
     *                                         which is what makes it the profiling seam
     */
    public function __construct(private readonly ?ProfilerInterface $profiler = null)
    {
    }

    /**
     * Prepare the response before emission.
     *
     * @param ServerRequestInterface $request  The server request
     * @param ResponseInterface      $response The response to prepare
     *
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
            if (null !== $length && !$response->hasHeader('Transfer-Encoding')) {
                $response = $response->withHeader('Content-Length', (string) $length);
            }
        }

        // HEAD method – clear content, preserve Content-Length
        if ('HEAD' === $request->getMethod()) {
            $len = $response->getHeaderLine('Content-Length');
            $response = $response->withBody(Stream::create(''));
            if ('' !== $len) {
                $response = $response->withHeader('Content-Length', $len);
            }
        }

        // Inertia support
        if ($request->hasHeader('X-Inertia')) {
            if (
                302 === $response->getStatusCode()
                && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)
            ) {
                $response = $response->withStatus(303);
            }

            // A redirect whose target carries a fragment cannot be followed
            // by XHR without losing the fragment, so the protocol replaces it
            // with a 409 the client visits itself. Not for a prefetch, which
            // may never be shown. Same rule as the reference middleware.
            if (
                self::isRedirect($response)
                && str_contains($response->getHeaderLine('Location'), '#')
                && !InertiaRenderer::isPrefetch($request)
            ) {
                $response = $response
                    ->withStatus(409)
                    ->withHeader(Header::REDIRECT, $response->getHeaderLine('Location'))
                    ->withoutHeader('Location')
                    ->withBody(Stream::create(''))
                    ->withHeader('Content-Length', '0');
            }

            // One URL answers HTML or JSON depending on X-Inertia (and on
            // Accept), so a cache in between must key on both. Merged, not
            // set: an adapter upstream may already have named X-Inertia.
            $response = $response
                ->withHeader('Vary', self::vary($response, ['Accept', 'X-Inertia']))
                ->withHeader('X-Inertia', 'true');
        }

        return $this->profiler?->collect($request, $response) ?? $response;
    }

    /** A response that names another URL for the client to go to. */
    private static function isRedirect(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true)
            && $response->hasHeader('Location');
    }

    /**
     * The Vary header with the given names added once each, in the order
     * they were first seen.
     *
     * @param list<string> $names
     */
    private static function vary(ResponseInterface $response, array $names): string
    {
        $values = [];

        foreach ($response->getHeader('Vary') as $line) {
            foreach (explode(',', $line) as $value) {
                $value = trim($value);

                if ($value !== '' && !in_array(strtolower($value), array_map('strtolower', $values), true)) {
                    $values[] = $value;
                }
            }
        }

        foreach ($names as $name) {
            if (!in_array(strtolower($name), array_map('strtolower', $values), true)) {
                $values[] = $name;
            }
        }

        return implode(', ', $values);
    }
}
