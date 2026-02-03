<?php

declare(strict_types=1);

use Modufolio\Appkit\Security\SecurityConfigurator;

/**
 * Security Configuration
 *
 * Configure application security using the fluent SecurityConfigurator API.
 * This includes firewalls, access control, role hierarchy, and security features.
 */
return function (SecurityConfigurator $security): void {
    // Configure Firewalls
    $security
        ->firewall('main', [
            'pattern' => '/',
            'authenticators' => ['form_login'],
            'entry_point' => '/login',
            'logout' => [
                'path' => '/logout',
                'target' => '/login',
            ],
            'switch_user' => [
                'enabled' => true,
                'role' => 'ROLE_ADMIN',
                'parameter' => '_switch_user',
            ],
        ]);

    // Configure Access Control Rules
    // Uses simplified pattern matching (no regex, no ReDoS risk):
    //   - "/api" matches paths starting with "/api" (prefix match)
    //   - "api:0" matches if segment 0 equals "api" (segment match)
    $security
        ->accessControl('/profile', ['ROLE_USER'], ['GET', 'POST'])
        ->accessControl('/login', [], ['GET', 'POST']);

    // Configure Role Hierarchy
    $security->roleHierarchy([
        'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
        'ROLE_ADMIN' => ['ROLE_USER'],
        'ROLE_USER' => ['ROLE_GUEST'],
        'ROLE_API_USER' => ['ROLE_GUEST'],
    ]);

    // Configure CSRF Protection
    $security
        ->csrfProtection(true)
        ->csrfExempt('/api/webhooks');

    // Configure Session
    $security->session([
        'name' => 'APPKIT_SESSION',
        'cookie_httponly' => true,
        'cookie_secure' => true,
        'cookie_samesite' => 'lax',
    ]);
};
