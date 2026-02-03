<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security;

/**
 * Security Configurator
 *
 * Fluent API for configuring application security settings including firewalls,
 * access control, authentication, and security features.
 *
 * Pattern matches OrmConfigurator for consistency across framework.
 *
 * Usage:
 * ```php
 * return function (SecurityConfigurator $security): void {
 *     $security
 *         ->firewall('main', [
 *             'pattern' => '^/',
 *             'authenticators' => ['form_login'],
 *             'entry_point' => '/login',
 *         ])
 *         ->accessControl('^/admin', ['ROLE_ADMIN'])
 *         ->roleHierarchy([
 *             'ROLE_ADMIN' => ['ROLE_USER'],
 *         ])
 *         ->csrf(['enabled' => true])
 *         ->bruteForceProtection(['adapter' => 'redis']);
 * };
 * ```
 */
final class SecurityConfigurator
{
    /**
     * Firewall configurations
     * @var array<string, array>
     */
    public array $firewalls = [];

    /**
     * Access control rules
     * @var array<array>
     */
    public array $accessControlRules = [];

    /**
     * Role hierarchy configuration
     * @var array<string, array>
     */
    public array $roleHierarchyConfig = [];

    /**
     * Role hierarchy instance (computed from config)
     */
    public ?RoleHierarchy $roleHierarchy = null;

    /**
     * CSRF protection configuration
     */
    public array $csrfConfig = [
        'enabled' => true,
        'token_parameter' => '_csrf_token',
        'token_header' => 'X-CSRF-Token',
        'default_token_id' => 'csrf_token',
        'exempt_paths' => [],
        'token_length' => 32,
        'max_tokens' => 100,
    ];

    /**
     * Brute force protection configuration
     */
    public array $bruteForceConfig = [
        'enabled' => true,
        'adapter' => 'file',
        'max_attempts' => 5,
        'lockout_duration' => 900, // 15 minutes
        'window_duration' => 300,  // 5 minutes
    ];

    /**
     * Security headers configuration
     */
    public array $securityHeadersConfig = [
        'enabled' => true,
        'x_frame_options' => [
            'enabled' => true,
            'value' => 'DENY',
        ],
        'csp' => [
            'enabled' => true,
            'report_only' => false,
        ],
        'hsts' => [
            'enabled' => true,
            'max_age' => 31536000,
            'include_subdomains' => true,
            'preload' => false,
        ],
    ];

    /**
     * Password policy configuration
     */
    public array $passwordPolicyConfig = [
        'enabled' => true,
        'current_version' => 2,
        'minimum_length' => 12,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special_chars' => true,
        'expiration_days' => 0,
        'history_count' => 0,
    ];

    /**
     * Session configuration
     */
    public array $sessionConfig = [
        'name' => 'APPKIT_SESSION',
        'cookie_lifetime' => 0,
        'cookie_httponly' => true,
        'cookie_secure' => true,
        'cookie_samesite' => 'lax',
    ];

    /**
     * Remember-me configuration
     */
    public array $rememberMeConfig = [
        'enabled' => false,
        'cookie_name' => 'REMEMBERME',
        'cookie_lifetime' => 2592000, // 30 days
        'cookie_secure' => true,
        'cookie_httponly' => true,
    ];

    /**
     * Security event subscribers
     * @var array<object>
     */
    private array $subscribers = [];

    /**
     * Features to enable/disable
     */
    public array $features = [
        'csrf_protection' => true,
        'brute_force_protection' => true,
        'security_headers' => true,
        'password_policy' => true,
        'audit_logging' => true,
    ];

    public function __construct()
    {
        // Initialize with defaults from environment if available
        $this->loadFromEnvironment();
    }

    /**
     * Configure a firewall
     *
     * @param string $name Firewall name
     * @param array $config Firewall configuration
     * @return self
     */
    public function firewall(string $name, array $config): self
    {
        $this->firewalls[$name] = $config;
        return $this;
    }

    /**
     * Add multiple firewalls at once
     *
     * @param array<string, array> $firewalls
     * @return self
     */
    public function firewalls(array $firewalls): self
    {
        foreach ($firewalls as $name => $config) {
            $this->firewall($name, $config);
        }
        return $this;
    }

    /**
     * Add an access control rule
     *
     * @param string $path Path pattern (regex)
     * @param array $roles Required roles
     * @param array<string>|null $methods HTTP methods (optional)
     * @param array $options Additional options (requires_channel, ips, etc.)
     * @return self
     */
    public function accessControl(
        string $path,
        array $roles = [],
        ?array $methods = null,
        array $options = []
    ): self {
        $rule = array_merge($options, [
            'path' => $path,
            'roles' => $roles,
        ]);

        if ($methods !== null) {
            $rule['methods'] = $methods;
        }

        $this->accessControlRules[] = $rule;
        return $this;
    }

    /**
     * Add multiple access control rules at once
     *
     * @param array<array> $rules
     * @return self
     */
    public function accessControlRules(array $rules): self
    {
        foreach ($rules as $rule) {
            $this->accessControlRules[] = $rule;
        }
        return $this;
    }

    /**
     * Configure role hierarchy
     *
     * @param array<string, array> $hierarchy Role inheritance map
     * @return self
     */
    public function roleHierarchy(array $hierarchy): self
    {
        $this->roleHierarchyConfig = $hierarchy;
        $this->roleHierarchy = new RoleHierarchy($hierarchy);
        return $this;
    }

    /**
     * Configure CSRF protection
     *
     * @param array $config CSRF configuration
     * @return self
     */
    public function csrf(array $config): self
    {
        $this->csrfConfig = array_merge($this->csrfConfig, $config);
        return $this;
    }

    /**
     * Enable/disable CSRF protection
     *
     * @param bool $enabled
     * @return self
     */
    public function csrfProtection(bool $enabled = true): self
    {
        $this->csrfConfig['enabled'] = $enabled;
        $this->features['csrf_protection'] = $enabled;
        return $this;
    }

    /**
     * Add CSRF exempt path
     *
     * @param string $path Path pattern to exempt from CSRF
     * @return self
     */
    public function csrfExempt(string $path): self
    {
        $this->csrfConfig['exempt_paths'][] = $path;
        return $this;
    }

    /**
     * Configure brute force protection
     *
     * @param array $config Brute force configuration
     * @return self
     */
    public function bruteForceProtection(array $config): self
    {
        $this->bruteForceConfig = array_merge($this->bruteForceConfig, $config);
        return $this;
    }

    /**
     * Configure security headers
     *
     * @param array $config Security headers configuration
     * @return self
     */
    public function securityHeaders(array $config): self
    {
        $this->securityHeadersConfig = array_merge($this->securityHeadersConfig, $config);
        return $this;
    }

    /**
     * Configure password policy
     *
     * @param array $config Password policy configuration
     * @return self
     */
    public function passwordPolicy(array $config): self
    {
        $this->passwordPolicyConfig = array_merge($this->passwordPolicyConfig, $config);
        return $this;
    }

    /**
     * Set password policy version (forces users to upgrade)
     *
     * @param int $version
     * @return self
     */
    public function passwordPolicyVersion(int $version): self
    {
        $this->passwordPolicyConfig['current_version'] = $version;
        return $this;
    }

    /**
     * Configure session
     *
     * @param array $config Session configuration
     * @return self
     */
    public function session(array $config): self
    {
        $this->sessionConfig = array_merge($this->sessionConfig, $config);
        return $this;
    }

    /**
     * Configure remember-me functionality
     *
     * @param array $config Remember-me configuration
     * @return self
     */
    public function rememberMe(array $config): self
    {
        $this->rememberMeConfig = array_merge($this->rememberMeConfig, $config);
        $this->rememberMeConfig['enabled'] = true;
        return $this;
    }

    /**
     * Add a security event subscriber
     *
     * @param object $subscriber Event subscriber instance
     * @return self
     */
    public function addSubscriber(object $subscriber): self
    {
        $this->subscribers[] = $subscriber;
        return $this;
    }

    /**
     * Get all registered subscribers
     *
     * @return array<object>
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }

    /**
     * Enable a security feature
     *
     * @param string $feature Feature name
     * @param bool $enabled
     * @return self
     */
    public function feature(string $feature, bool $enabled = true): self
    {
        $this->features[$feature] = $enabled;
        return $this;
    }

    /**
     * Check if a feature is enabled
     *
     * @param string $feature
     * @return bool
     */
    public function isFeatureEnabled(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    /**
     * Get firewall configuration
     *
     * @return array<string, array>
     */
    public function getFirewalls(): array
    {
        return $this->firewalls;
    }

    /**
     * Get access control rules
     *
     * @return array<array>
     */
    public function getAccessControlRules(): array
    {
        return $this->accessControlRules;
    }

    /**
     * Get role hierarchy
     *
     * @return RoleHierarchy
     */
    public function getRoleHierarchy(): RoleHierarchy
    {
        if ($this->roleHierarchy === null) {
            $this->roleHierarchy = new RoleHierarchy($this->roleHierarchyConfig);
        }
        return $this->roleHierarchy;
    }

    /**
     * Export configuration as array (for backward compatibility)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'firewalls' => $this->firewalls,
            'access_control' => $this->accessControlRules,
            'role_hierarchy' => $this->roleHierarchyConfig,
            'csrf' => $this->csrfConfig,
            'brute_force' => $this->bruteForceConfig,
            'security_headers' => $this->securityHeadersConfig,
            'password_policy' => $this->passwordPolicyConfig,
            'session' => $this->sessionConfig,
            'remember_me' => $this->rememberMeConfig,
            'features' => $this->features,
        ];
    }

    /**
     * Load configuration from environment variables
     */
    private function loadFromEnvironment(): void
    {
        // CSRF
        if (isset($_ENV['CSRF_ENABLED'])) {
            $this->csrfConfig['enabled'] = filter_var($_ENV['CSRF_ENABLED'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_ENV['CSRF_TOKEN_PARAMETER'])) {
            $this->csrfConfig['token_parameter'] = $_ENV['CSRF_TOKEN_PARAMETER'];
        }
        if (isset($_ENV['CSRF_TOKEN_HEADER'])) {
            $this->csrfConfig['token_header'] = $_ENV['CSRF_TOKEN_HEADER'];
        }

        // Brute Force Protection
        if (isset($_ENV['BRUTEFORCE_ADAPTER'])) {
            $this->bruteForceConfig['adapter'] = $_ENV['BRUTEFORCE_ADAPTER'];
        }
        if (isset($_ENV['BRUTEFORCE_MAX_ATTEMPTS'])) {
            $this->bruteForceConfig['max_attempts'] = (int)$_ENV['BRUTEFORCE_MAX_ATTEMPTS'];
        }
        if (isset($_ENV['BRUTEFORCE_LOCKOUT_DURATION'])) {
            $this->bruteForceConfig['lockout_duration'] = (int)$_ENV['BRUTEFORCE_LOCKOUT_DURATION'];
        }

        // Password Policy
        if (isset($_ENV['PASSWORD_POLICY_VERSION'])) {
            $this->passwordPolicyConfig['current_version'] = (int)$_ENV['PASSWORD_POLICY_VERSION'];
        }
        if (isset($_ENV['PASSWORD_EXPIRATION_DAYS'])) {
            $this->passwordPolicyConfig['expiration_days'] = (int)$_ENV['PASSWORD_EXPIRATION_DAYS'];
        }

        // Security Headers
        if (isset($_ENV['HSTS_ENABLED'])) {
            $this->securityHeadersConfig['hsts']['enabled'] = filter_var($_ENV['HSTS_ENABLED'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_ENV['HSTS_MAX_AGE'])) {
            $this->securityHeadersConfig['hsts']['max_age'] = (int)$_ENV['HSTS_MAX_AGE'];
        }
    }
}
