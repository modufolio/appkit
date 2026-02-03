<?php

namespace Modufolio\Appkit\Security;

class RoleHierarchy
{
    private array $cache = [];

    public function __construct(private array $hierarchy)
    {
    }

    public function getReachableRoles(array $roles): array
    {
        $cacheKey = implode(',', $roles);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $reachableRoles = $roles;
        foreach ($roles as $role) {
            $this->collectRoles($role, $reachableRoles);
        }

        $reachableRoles = array_unique($reachableRoles);
        $this->cache[$cacheKey] = $reachableRoles;
        return $reachableRoles;
    }

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
