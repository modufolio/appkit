<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Thrown when an authenticated user is denied access to a resource (HTTP 403).
 *
 * This is distinct from AuthenticationException (HTTP 401): the user IS
 * authenticated, they simply lack the required roles or fail an access rule.
 */
class AccessDeniedException extends RuntimeException
{
}
