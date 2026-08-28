<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\OAuth\Google;

/**
 * Raised when the Google exchange or ID-token verification fails.
 *
 * Kept distinct from the security exceptions so the authenticator can decide
 * how to surface it — every cause here (a forged code, a bad signature, an
 * unverified email) is a failed login, never a hint returned to the caller.
 */
final class GoogleOAuthException extends \RuntimeException
{
}
