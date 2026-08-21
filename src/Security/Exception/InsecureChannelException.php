<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * Thrown when a request arrives over an insecure channel (http) for a path that
 * requires https (`requires_channel => 'https'`).
 *
 * Unlike a plain authorization failure this is not an error the user must fix —
 * the same request over https is fine — so the exception carries the https URL
 * to upgrade to, and the exception handler turns it into a redirect rather than
 * a 401/403. Extends AuthenticationException only so any existing security
 * catch-all still treats it as a security concern if the redirect handling is
 * ever removed.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class InsecureChannelException extends AuthenticationException
{
    public function __construct(private readonly string $targetUrl, string $message = 'HTTPS required for this path.')
    {
        parent::__construct($message);
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }
}
