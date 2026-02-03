<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Exception;

class BadCredentialsException extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'Invalid credentials.';
    }
}
