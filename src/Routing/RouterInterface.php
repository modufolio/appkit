<?php

namespace Modufolio\Appkit\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Router interface for matching requests to routes and generating URLs
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface RouterInterface
{
    /**
     * Match a PSR-7 server request to route parameters
     *
     * @param ServerRequestInterface $request The request to match
     * @return array The matched route parameters including _controller, _route, etc.
     * @throws ResourceNotFoundException
     */
    public function match(ServerRequestInterface $request): array;

    /**
     * Match a path string to route parameters
     *
     * @param string $pathinfo The path to match
     * @return array The matched route parameters
     * @throws ResourceNotFoundException
     */
    public function matchPath(string $pathinfo): array;

    /**
     * Generate a URL from route name and parameters
     *
     * @param string $name Route name
     * @param array $parameters Route parameters
     * @param int $referenceType Type of reference (absolute path, absolute URL, etc.)
     * @return string The generated URL
     */
    public function generateUrl(
        string $name,
        array $parameters = [],
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH
    ): string;

    /**
     * Get the URL generator instance
     *
     * @return UrlGeneratorInterface
     */
    public function getUrlGenerator(): UrlGeneratorInterface;

    /**
     * Get the route collection
     *
     * @return \Symfony\Component\Routing\RouteCollection
     */
    public function getRouteCollection(): \Symfony\Component\Routing\RouteCollection;
}