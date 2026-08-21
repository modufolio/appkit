<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Base exception for account status related authentication failures
 * For SOC 2 compliance: CC6.1, CC6.7.
 *
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class AccountStatusException extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'Account status prevents authentication.';
    }
}
