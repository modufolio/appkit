<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

/**
 * The request's Host header is not on the configured trusted-hosts allowlist.
 *
 * Raised by the kernel before request state is built (and by the router as a
 * second line of defence) so an attacker-supplied host never reaches absolute
 * URL generation. The exception handler answers with 400 Bad Request; the
 * offending host is kept out of the response body and only reaches the log.
 *
 * @see \Modufolio\Appkit\Http\TrustedHosts
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class UntrustedHostException extends \RuntimeException
{
    public function __construct(private readonly string $host)
    {
        parent::__construct(sprintf('Untrusted request host "%s".', $host));
    }

    public function getHost(): string
    {
        return $this->host;
    }
}
