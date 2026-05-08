<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security;

/**
 * Security Configurator
 *
 * Fluent API for declaring the three things the Kernel actually consumes:
 * firewalls, access-control rules, and the role hierarchy.
 *
 * Pattern syntax (firewalls and access-control rules) is plain — NOT regex —
 * to avoid ReDoS risk:
 *  - A path-like pattern (`/admin`, `/api`) does prefix matching against the
 *    request path. A leading slash is added automatically if missing.
 *  - A `value:position` pattern matches when the URL segment at the given
 *    zero-indexed position equals `value`. E.g. `api:0` matches `/api/...`,
 *    `users:1` matches `/api/users/...`.
 *
 * Usage:
 * ```php
 * return function (SecurityConfigurator $security): void {
 *     $security
 *         ->firewall('main', [
 *             'pattern' => '/',                  // prefix match on path
 *             'authenticators' => ['form_login'],
 *             'entry_point' => '/login',
 *         ])
 *         ->accessControl('/admin', ['ROLE_ADMIN'])
 *         ->accessControl('api:0', ['ROLE_API_USER'])
 *         ->roleHierarchy([
 *             'ROLE_ADMIN' => ['ROLE_USER'],
 *         ]);
 * };
 * ```
 */
final class SecurityConfigurator
{
    /** @var array<string, array<string, mixed>> */
    public array $firewalls = [];

    /** @var array<int, array<string, mixed>> */
    public array $accessControlRules = [];

    /** @var array<string, array<int, string>> */
    public array $roleHierarchyConfig = [];

    public ?RoleHierarchy $roleHierarchy = null;

    /**
     * Configure a firewall.
     *
     * @param array<string, mixed> $config
     */
    public function firewall(string $name, array $config): self
    {
        $this->firewalls[$name] = $config;
        return $this;
    }

    /**
     * Add multiple firewalls at once.
     *
     * @param array<string, array<string, mixed>> $firewalls
     */
    public function firewalls(array $firewalls): self
    {
        foreach ($firewalls as $name => $config) {
            $this->firewall($name, $config);
        }
        return $this;
    }

    /**
     * Add an access control rule.
     *
     * @param string $path Plain pattern, NOT regex. Either a path prefix
     *                     (`/admin`) or a `segment:position` match (`api:0`).
     *                     See class docblock for syntax details.
     * @param array<int, string> $roles
     * @param array<int, string>|null $methods
     * @param array<string, mixed> $options
     */
    public function accessControl(
        string $path,
        array $roles = [],
        ?array $methods = null,
        array $options = [],
    ): self {
        $rule = array_merge($options, [
            'path'  => $path,
            'roles' => $roles,
        ]);

        if ($methods !== null) {
            $rule['methods'] = $methods;
        }

        $this->accessControlRules[] = $rule;
        return $this;
    }

    /**
     * Add multiple access control rules at once.
     *
     * @param array<int, array<string, mixed>> $rules
     */
    public function accessControlRules(array $rules): self
    {
        foreach ($rules as $rule) {
            $this->accessControlRules[] = $rule;
        }
        return $this;
    }

    /**
     * Configure role hierarchy.
     *
     * @param array<string, array<int, string>> $hierarchy
     */
    public function roleHierarchy(array $hierarchy): self
    {
        $this->roleHierarchyConfig = $hierarchy;
        $this->roleHierarchy = new RoleHierarchy($hierarchy);
        return $this;
    }

    /** @return array<string, array<string, mixed>> */
    public function getFirewalls(): array
    {
        return $this->firewalls;
    }

    /** @return array<int, array<string, mixed>> */
    public function getAccessControlRules(): array
    {
        return $this->accessControlRules;
    }

    public function getRoleHierarchy(): RoleHierarchy
    {
        return $this->roleHierarchy ??= new RoleHierarchy($this->roleHierarchyConfig);
    }
}
