<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Routing\Loader;


use Doctrine\ORM\Mapping\Entity as DoctrineEntity;
use Modufolio\JsonApi\JsonApiConfigurator;
use ReflectionClass;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class JsonApiRouteLoader extends Loader
{
    public function __construct(
        private FileLocatorInterface $fileLocator,
        private readonly string $controllerClass,
        private readonly string $prefix = '/api',
        private readonly bool $debug = false
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $filePath = $this->fileLocator->locate($resource);
        $configFile = include $filePath;

        // Initialize JsonApiConfigurator and build config
        $configurator = new JsonApiConfigurator();

        if (is_callable($configFile)) {
            $configFile($configurator);
        }

        $jsonApiConfig = $configurator->buildConfig();

        $routes = new RouteCollection();

        foreach ($jsonApiConfig as $entityClass => $entityConfig) {
            [$resourceKey, $readOnly] = $this->debug
                ? $this->validateEntityClass($entityClass, $entityConfig)
                : [$entityConfig['resource_key'] ?? $this->extractResourceKey($entityClass), false];

            $operations = $entityConfig['operations'] ?? [];

            // Always allow read operations
            if ($operations['index'] ?? false) {
                $routes->add(
                    "api_{$resourceKey}_index",
                    $this->createRoute("/{$resourceKey}", ['GET'], 'index', $entityClass)
                );
            }

            if ($operations['show'] ?? false) {
                $routes->add(
                    "api_{$resourceKey}_show",
                    $this->createRoute("/{$resourceKey}/{id}", ['GET'], 'show', $entityClass, ['id' => '\d+'])
                );
            }

            // Skip write operations if entity is read-only
            if (!$readOnly) {
                if ($operations['create'] ?? false) {
                    $routes->add(
                        "api_{$resourceKey}_create",
                        $this->createRoute("/{$resourceKey}", ['POST'], 'create', $entityClass)
                    );
                }

                if ($operations['update'] ?? false) {
                    $routes->add(
                        "api_{$resourceKey}_update",
                        $this->createRoute("/{$resourceKey}/{id}", ['PATCH', 'PUT'], 'update', $entityClass, ['id' => '\d+'])
                    );
                }

                if ($operations['delete'] ?? false) {
                    $routes->add(
                        "api_{$resourceKey}_delete",
                        $this->createRoute("/{$resourceKey}/{id}", ['DELETE'], 'delete', $entityClass, ['id' => '\d+'])
                    );
                }
            }

            // Relationship routes (always allowed)
            foreach ($entityConfig['relationships'] ?? [] as $relationship) {
                $routes->add(
                    "api_{$resourceKey}_related_{$relationship}",
                    $this->createRoute(
                        "/{$resourceKey}/{id}/{$relationship}",
                        ['GET'],
                        'related',
                        $entityClass,
                        ['id' => '\d+'],
                        ['relationship' => $relationship]
                    )
                );
            }
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'json_api';
    }

    private function createRoute(
        string $path,
        array $methods,
        string $operation,
        string $entityClass,
        array $requirements = [],
        array $defaults = []
    ): Route {
        return new Route(
            path: $this->prefix . $path,
            defaults: array_merge([
                '_controller' => [$this->controllerClass, 'handle'],
                'entityClass' => $entityClass,
                'operation' => $operation,
            ], $defaults),
            requirements: $requirements,
            methods: $methods
        );
    }

    /**
     * Validate the entity class and determine read-only mode.
     *
     * Only called when debug = true.
     *
     * @return array{string, bool} [resourceKey, readOnly]
     */
    private function validateEntityClass(string $entityClass, array $entityConfig): array
    {
        if (!class_exists($entityClass)) {
            throw new \InvalidArgumentException(sprintf(
                'Configured entity class "%s" does not exist.',
                $entityClass
            ));
        }

        if (!isset($entityConfig['resource_key'])) {
            throw new \InvalidArgumentException(sprintf(
                'Missing "resource_key" for entity "%s".',
                $entityClass
            ));
        }

        $readOnly = false;
        $reflection = new ReflectionClass($entityClass);
        $attributes = $reflection->getAttributes(DoctrineEntity::class);

        if (!empty($attributes)) {
            $doctrineEntity = $attributes[0]->newInstance();
            $readOnly = (bool)$doctrineEntity->readOnly;
        }

        return [$entityConfig['resource_key'], $readOnly];
    }

    /**
     * @throws \ReflectionException
     */
    private function extractResourceKey(string $entityClass): string
    {
        // Simple fallback: take lowercase short class name
        return strtolower((new \ReflectionClass($entityClass))->getShortName());
    }
}
