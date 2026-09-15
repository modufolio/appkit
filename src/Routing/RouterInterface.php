<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Router interface for matching requests to routes and generating URLs.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface RouterInterface
{
    /**
     * Match a PSR-7 server request to route parameters.
     *
     * @param ServerRequestInterface $request The request to match
     *
     * @return array<string, mixed> the matched route parameters including _controller, _route, etc
     *
     * @throws ResourceNotFoundException
     */
    public function match(ServerRequestInterface $request): array;

    /**
     * Match a path string to route parameters.
     *
     * @param string $pathinfo The path to match
     *
     * @return array<string, mixed> The matched route parameters
     *
     * @throws ResourceNotFoundException
     */
    public function matchPath(string $pathinfo): array;

    /**
     * Generate a URL from route name and parameters.
     *
     * @param string               $name          Route name
     * @param array<string, mixed> $parameters    Route parameters
     * @param int                  $referenceType Type of reference (absolute path, absolute URL, etc.)
     *
     * @return string The generated URL
     */
    public function generateUrl(
        string $name,
        array $parameters = [],
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH,
    ): string;

    /**
     * Get the URL generator instance.
     */
    public function getUrlGenerator(): UrlGeneratorInterface;

    /**
     * Get the route collection.
     */
    public function getRouteCollection(): RouteCollection;

    /**
     * A request-independent projection of the routes, cached beside the
     * compiled matcher and generator and invalidated by the same resources.
     *
     * Cheaper than walking getRouteCollection(), which reloads every route
     * from source. $project runs only on a cache miss, so it must not read the
     * request, and must return an array var_export() can round-trip.
     *
     * @param string                                  $key     Cache key; a bare filename, [a-z0-9_-]
     * @param \Closure(RouteCollection): array<mixed> $project
     *
     * @return array<mixed>
     */
    public function cachedRouteData(string $key, \Closure $project): array;
}
