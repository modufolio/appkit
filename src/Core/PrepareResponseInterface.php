<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface for preparing HTTP responses before emission.
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
interface PrepareResponseInterface
{
    /**
     * Prepare the response before emission.
     *
     * @param ServerRequestInterface $request The server request
     * @param ResponseInterface $response The response to prepare
     * @return ResponseInterface The prepared response
     */
    public function prepare(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;
}
