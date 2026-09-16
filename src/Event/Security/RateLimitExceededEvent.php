<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * A client hit a rate limiter's ceiling and was answered 429.
 *
 * Dispatched before the RateLimitExceededException reaches the handler,
 * from both the firewall's login throttle and a route's `#[RateLimit]`. One
 * of these is a client with a retry loop; a burst from one address across
 * many keys is something else, and the key says which.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class RateLimitExceededEvent
{
    /**
     * @param string      $limiter           the limiter's configured name
     * @param string      $key               what was counted: `ip:…` or `user:…`
     * @param string      $path              the request path
     * @param string      $method            the HTTP method
     * @param string|null $userIdentifier    the signed-in user, when any
     * @param int         $retryAfterSeconds what the client was told to wait
     */
    public function __construct(
        public string $limiter,
        public string $key,
        public string $path,
        public string $method,
        public ?string $userIdentifier,
        public ?string $clientIp,
        public int $retryAfterSeconds,
    ) {
    }
}
