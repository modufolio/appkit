<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Thrown when a remember-me cookie presents a known series with a stale value —
 * the signature of a stolen-then-rotated cookie. All of the affected user's
 * remember-me tokens are revoked in response, forcing every device to
 * re-authenticate.
 */
class CookieTheftException extends AuthenticationException
{
}
