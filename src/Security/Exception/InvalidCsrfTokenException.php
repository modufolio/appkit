<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Exception thrown when CSRF token validation fails
 */
class InvalidCsrfTokenException extends AuthenticationException
{
    public function __construct(string $message = 'Invalid CSRF token', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
