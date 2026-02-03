<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Base exception for account status related authentication failures
 * For SOC 2 compliance: CC6.1, CC6.7
 */
class AccountStatusException extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'Account status prevents authentication.';
    }
}
