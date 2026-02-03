<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Exception thrown when attempting to authenticate with a disabled account
 * For SOC 2 compliance: CC6.1 - Account lifecycle management
 */
class DisabledAccountException extends AccountStatusException
{
    public function getMessageKey(): string
    {
        return 'Your account has been disabled. Please contact an administrator.';
    }
}
