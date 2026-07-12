<?php

namespace Modufolio\Appkit\Security;

class RoleHierarchy
{
    /**
     * Cap the cache to bound memory in long-running workers. The role universe
     * is normally small (under a few dozen distinct combinations); this just
     * keeps a pathological caller from growing the map indefinitely.
     */
    private const MAX_CACHE_ENTRIES = 256;

    /** @var array<string, list<string>> */
    private array $cache = [];

    /**
     * @param array<string, list<string>> $hierarchy
     */
    public function __construct(private array $hierarchy)
    {
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public function getReachableRoles(array $roles): array
    {
        $cacheKey = $this->cacheKey($roles);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $reachableRoles = $roles;
        foreach ($roles as $role) {
            $this->collectRoles($role, $reachableRoles);
        }

        $reachableRoles = array_values(array_unique($reachableRoles));

        if (count($this->cache) >= self::MAX_CACHE_ENTRIES) {
            array_shift($this->cache);
        }

        $this->cache[$cacheKey] = $reachableRoles;

        return $reachableRoles;
    }

    /**
     * Build a collision-free cache key. `implode(',', ['ROLE_A,B'])` and
     * `implode(',', ['ROLE_A', 'B'])` would otherwise share the same key.
     *
     * @param list<string> $roles
     */
    private function cacheKey(array $roles): string
    {
        $sorted = $roles;
        sort($sorted, SORT_STRING);

        return hash('xxh3', json_encode($sorted));
    }

    /**
     * @param array<string> $visited
     * @param-out array<string> $visited
     */
    private function collectRoles(string $role, array &$visited): void
    {
        if (isset($this->hierarchy[$role])) {
            foreach ($this->hierarchy[$role] as $inheritedRole) {
                if (!in_array($inheritedRole, $visited, true)) {
                    $visited[] = $inheritedRole;
                    $this->collectRoles($inheritedRole, $visited);
                }
            }
        }
    }
}
