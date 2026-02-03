<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Exception thrown when attempting to authenticate with expired credentials
 * For SOC 2 compliance: CC6.1 - Password expiration controls
 */
class CredentialsExpiredException extends AccountStatusException
{
    public function getMessageKey(): string
    {
        return 'Your credentials have expired. Please reset your password.';
    }
}
