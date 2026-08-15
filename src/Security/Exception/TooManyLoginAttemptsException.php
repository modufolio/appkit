<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Exception thrown when the brute-force throttle rejects a login attempt.
 * For SOC 2 compliance: CC6.1, CC6.7 - Brute-force mitigation.
 */
class TooManyLoginAttemptsException extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'Too many failed login attempts. Please try again later.';
    }
}
