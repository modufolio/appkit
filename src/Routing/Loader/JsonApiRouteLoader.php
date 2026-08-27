<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Routing\Loader;

use Doctrine\ORM\Mapping\Entity as DoctrineEntity;
use Modufolio\Appkit\Security\AuthenticationTrustResolverInterface;
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

            // The read-only guard is a security control and MUST be enforced in
            // every environment, not just debug.
            $readOnly = $this->isReadOnly($entityClass);

            $operations = $entityConfig['operations'] ?? [];

            // Whether this entity exposes any generated write route at all —
            // decides if the default write gate below has anything to protect.
            $hasWriteRoutes = !$readOnly && (
                ($operations['create'] ?? false)
                || ($operations['update'] ?? false)
                || ($operations['delete'] ?? false)
            );

            // Roles declared on the resource are enforced by the kernel, not
            // here: writing them to the route as `_is_granted_roles` is exactly
            // what #[IsGranted] does, so AccessDecisionEngine::enforceRoleGroups()
            // applies the role hierarchy before the controller is reached.
            $roleGroups = $this->roleGroups($entityClass, $entityConfig, $hasWriteRoutes);

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
            if ([] !== $roleGroups) {
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
     * HTTP methods the generated write routes answer to. HEAD is deliberately
     * absent: it belongs to the read gate (the router treats HEAD as GET).
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Normalise the declared roles into the shape the kernel enforces.
     *
     * Two declaration shapes are accepted:
     *
     *   'roles' => ['ROLE_USER']                  // one gate for every route
     *   'roles' => [                              // split by operation kind
     *       'read'  => ['ROLE_USER'],             // index/show/related (GET|HEAD)
     *       'write' => ['ROLE_ADMIN'],            // create/update/delete
     *   ]
     *
     * The split shape exists because "readable by users, writable by admins"
     * is the common case, and a single flat list forces either over-granting
     * writes or over-protecting reads. In the split shape a key that is
     * *present but empty* deliberately leaves that side ungated
     * (`'read' => []` — public reads), while an *absent* `write` key falls
     * back to the default write gate below.
     *
     * Generated write endpoints are never silently ungated: an entity that
     * exposes write routes but declares no write roles (no roles at all, or a
     * split shape without a `write` key) gets `IS_AUTHENTICATED` stamped on
     * the write methods. Deliberately public writes must say so with
     * `'roles' => ['read' => [], 'write' => []]`.
     *
     * `AccessDecisionEngine::enforceRoleGroups()` ANDs across groups and ORs
     * within one, so each side becomes a single group — any one of its roles
     * grants access. A group carrying a `methods` list only applies to those
     * HTTP methods, which is how the read/write split is expressed on routes
     * whose collection shares one `_is_granted_roles` default.
     *
     * @param array<string, mixed> $entityConfig
     *
     * @return list<list<string>|array{roles: list<string>, methods: list<string>}>
     */
    private function roleGroups(string $entityClass, array $entityConfig, bool $hasWriteRoutes): array
    {
        $roles = $entityConfig['roles'] ?? [];

        if (!is_array($roles)) {
            $roles = [];
        }

        // Flat shape: a list of role strings guards every route of the entity.
        if (array_is_list($roles)) {
            $flat = $this->sanitizeRoles($roles);

            if ([] !== $flat) {
                return [$flat];
            }

            // No roles declared at all: reads stay open (they always were),
            // writes get the default gate.
            return $hasWriteRoutes
                ? [['roles' => [AuthenticationTrustResolverInterface::IS_AUTHENTICATED], 'methods' => self::WRITE_METHODS]]
                : [];
        }

        // Split shape.
        if ($this->debug) {
            $unknown = array_diff(array_keys($roles), ['read', 'write']);
            if ([] !== $unknown) {
                throw new \InvalidArgumentException(sprintf('Unknown roles key(s) "%s" for entity "%s"; expected "read" and/or "write".', implode('", "', $unknown), $entityClass));
            }
        }

        $groups = [];

        if (($read = $this->sanitizeRoles((array) ($roles['read'] ?? []))) !== []) {
            // GET implies HEAD, same as #[IsGranted]: the router answers HEAD
            // requests with the GET route, so leaving it out would let a HEAD
            // request slip past the read gate.
            $groups[] = ['roles' => $read, 'methods' => ['GET', 'HEAD']];
        }

        if (\array_key_exists('write', $roles)) {
            if (($write = $this->sanitizeRoles((array) $roles['write'])) !== []) {
                $groups[] = ['roles' => $write, 'methods' => self::WRITE_METHODS];
            }
        // Present but empty: writes are deliberately public.
        } elseif ($hasWriteRoutes) {
            $groups[] = ['roles' => [AuthenticationTrustResolverInterface::IS_AUTHENTICATED], 'methods' => self::WRITE_METHODS];
        }

        return $groups;
    }

    /**
     * @param array<int|string, mixed> $roles
     *
     * @return list<string>
     */
    private function sanitizeRoles(array $roles): array
    {
        return array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && '' !== $role,
        ));
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
