<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Exception thrown when attempting to authenticate with a locked account
 * For SOC 2 compliance: CC6.1, CC6.7 - Security incident response
 */
class LockedAccountException extends AccountStatusException
{
    public function getMessageKey(): string
    {
        return 'Your account has been locked. Please contact an administrator.';
    }
}
