<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Routing\Loader;

use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class ArrayRouteLoader extends Loader
{

    public function __construct(private FileLocatorInterface $fileLocator)
    {
        parent::__construct();
    }

    public function load($resource, $type = null): RouteCollection
    {

        // Locate the file using FileLocator
        $filePath = $this->fileLocator->locate($resource);

        // Include the routes from the file
        $routeDefinitions = include $filePath;

        $routes = new RouteCollection();

        foreach ($routeDefinitions as $name => $definition) {
            // Create the Symfony Route instance
            $route = new Route(
                path: $definition['pattern'],
                defaults: ['_controller' => $definition['controller']],
                requirements: $definition['requirements'] ?? [],
                methods: $definition['methods'] ?? ['GET']
            );

            // Use the array key as the route name
            $routeName = is_string($name) ? $name : $definition['pattern'];

            // Add the route to the collection
            $routes->add($routeName, $route);
        }

        return $routes;
    }

    public function supports($resource, $type = null): bool
    {
        return 'array' === $type;
    }
}
