<?php

declare(strict_types=1);

use Modufolio\Appkit\Security\SecurityConfigurator;

/*
 * Test-app security configuration.
 *
 * Only firewalls, access control, and role hierarchy are consumed by the
 * Kernel.
 */
return function (SecurityConfigurator $security): void {
    $security->firewall('main', [
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

    $security
        ->accessControl('/profile', ['ROLE_USER'], ['GET', 'POST'])
        ->accessControl('/login', [], ['GET', 'POST']);

    $security->roleHierarchy([
        'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
        'ROLE_ADMIN' => ['ROLE_USER'],
        'ROLE_USER' => ['ROLE_GUEST'],
        'ROLE_API_USER' => ['ROLE_GUEST'],
    ]);
};
