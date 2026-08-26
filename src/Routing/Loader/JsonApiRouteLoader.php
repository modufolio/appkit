<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Routing\Loader;

use Doctrine\ORM\Mapping\Entity as DoctrineEntity;
use Modufolio\JsonApi\JsonApiConfigurator;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class JsonApiRouteLoader extends Loader
{
    public function __construct(
        private FileLocatorInterface $fileLocator,
        private readonly string $controllerClass,
        private readonly string $prefix = '/api',
        private readonly bool $debug = false,
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
            if (!class_exists($entityClass)) {
                throw new \InvalidArgumentException(sprintf('Configured JSON:API entity class "%s" does not exist.', $entityClass));
            }

            // Config sanity checks (existence, resource_key presence) are dev-only
            // conveniences and stay behind the debug flag.
            if ($this->debug) {
                $this->validateEntityConfig($entityClass, $entityConfig);
            }

            $resourceKey = $entityConfig['resource_key'] ?? $this->extractResourceKey($entityClass);

            // Roles declared on the resource are enforced by the kernel, not
            // here: writing them to the route as `_is_granted_roles` is exactly
            // what #[IsGranted] does, so AccessDecisionEngine::enforceRoleGroups()
            // applies the role hierarchy before the controller is reached.
            $roleGroups = $this->roleGroups($entityConfig);

            // The read-only guard is a security control and MUST be enforced in
            // every environment, not just debug.
            $readOnly = $this->isReadOnly($entityClass);

            $operations = $entityConfig['operations'] ?? [];

            // Collected per entity so the declared roles can be applied to
            // all of its routes in one place, rather than threaded through
            // every createRoute() call.
            $entityRoutes = new RouteCollection();

            // Always allow read operations
            if ($operations['index'] ?? false) {
                $entityRoutes->add(
                    "api_{$resourceKey}_index",
                    $this->createRoute("/{$resourceKey}", ['GET'], 'index', $entityClass)
                );
            }

            if ($operations['show'] ?? false) {
                $entityRoutes->add(
                    "api_{$resourceKey}_show",
                    $this->createRoute("/{$resourceKey}/{id}", ['GET'], 'show', $entityClass)
                );
            }

            // Skip write operations if entity is read-only
            if (!$readOnly) {
                if ($operations['create'] ?? false) {
                    $entityRoutes->add(
                        "api_{$resourceKey}_create",
                        $this->createRoute("/{$resourceKey}", ['POST'], 'create', $entityClass)
                    );
                }

                if ($operations['update'] ?? false) {
                    $entityRoutes->add(
                        "api_{$resourceKey}_update",
                        $this->createRoute("/{$resourceKey}/{id}", ['PATCH', 'PUT'], 'update', $entityClass)
                    );
                }

                if ($operations['delete'] ?? false) {
                    $entityRoutes->add(
                        "api_{$resourceKey}_delete",
                        $this->createRoute("/{$resourceKey}/{id}", ['DELETE'], 'delete', $entityClass)
                    );
                }
            }

            // Relationship routes (always allowed)
            foreach ($entityConfig['relationships'] ?? [] as $relationship) {
                $entityRoutes->add(
                    "api_{$resourceKey}_related_{$relationship}",
                    $this->createRoute(
                        "/{$resourceKey}/{id}/{$relationship}",
                        ['GET'],
                        'related',
                        $entityClass,
                        [],
                        ['relationship' => $relationship]
                    )
                );
            }
            if ($roleGroups !== []) {
                $entityRoutes->addDefaults(['_is_granted_roles' => $roleGroups]);
            }

            $routes->addCollection($entityRoutes);
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'json_api' === $type;
    }

    /**
     * @param list<string>          $methods
     * @param array<string, string> $requirements
     * @param array<string, mixed>  $defaults
     */
    private function createRoute(
        string $path,
        array $methods,
        string $operation,
        string $entityClass,
        array $requirements = [],
        array $defaults = [],
    ): Route {
        return new Route(
            path: $this->prefix.$path,
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
     * Normalise the declared roles into the shape the kernel enforces.
     *
     * `AccessDecisionEngine::enforceRoleGroups()` ANDs across groups and ORs
     * within one, so the roles become a single group — any one of them grants
     * access. A flat list would instead demand every listed role.
     *
     * @param array<string, mixed> $entityConfig
     *
     * @return list<list<string>>
     */
    private function roleGroups(array $entityConfig): array
    {
        $roles = $entityConfig['roles'] ?? [];

        if (!is_array($roles)) {
            return [];
        }

        $roles = array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && '' !== $role,
        ));

        return $roles === [] ? [] : [$roles];
    }

    /**
     * Validate the entity configuration.
     *
     * These are developer-facing sanity checks, so they only run when debug = true.
     *
     * @param array<string, mixed> $entityConfig
     */
    private function validateEntityConfig(string $entityClass, array $entityConfig): void
    {
        if (!class_exists($entityClass)) {
            throw new \InvalidArgumentException(sprintf('Configured entity class "%s" does not exist.', $entityClass));
        }

        if (!isset($entityConfig['resource_key'])) {
            throw new \InvalidArgumentException(sprintf('Missing "resource_key" for entity "%s".', $entityClass));
        }
    }

    /**
     * Determine whether an entity is marked read-only via its #[Entity(readOnly: true)]
     * Doctrine attribute. Read-only entities must never expose write routes, so this
     * runs in all environments (not just debug).
     */
    private function isReadOnly(string $entityClass): bool
    {
        if (!class_exists($entityClass)) {
            // In production we don't throw on a bad class here; but if we can't
            // reflect it, fail safe by treating it as read-only.
            return true;
        }

        $attributes = (new \ReflectionClass($entityClass))->getAttributes(DoctrineEntity::class);

        if (empty($attributes)) {
            return false;
        }

        return (bool) $attributes[0]->newInstance()->readOnly;
    }

    /**
     * @throws \ReflectionException
     */
    /**
     * @param class-string $entityClass
     */
    private function extractResourceKey(string $entityClass): string
    {
        // Simple fallback: take lowercase short class name
        return strtolower((new \ReflectionClass($entityClass))->getShortName());
    }
}
