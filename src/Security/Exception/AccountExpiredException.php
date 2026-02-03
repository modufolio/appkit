<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Exception thrown when attempting to authenticate with an expired account
 * For SOC 2 compliance: CC6.1 - Account expiration controls
 */
class AccountExpiredException extends AccountStatusException
{
    public function getMessageKey(): string
    {
        return 'Your account has expired. Please contact an administrator.';
    }
}
