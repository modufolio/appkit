<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Exception;

/**
 * A client used up a rate limiter's window.
 *
 * Answered with 429 Too Many Requests and a Retry-After header by the
 * exception handler. Deliberately not an AuthenticationException: a
 * throttled request is neither unauthenticated nor forbidden, and must not
 * be turned into a login redirect.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        private readonly string $limiter,
        private readonly \DateTimeImmutable $retryAfter,
        private readonly int $limit,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Rate limit "%s" exceeded.', $limiter), 429, $previous);
    }

    /** The limiter's configured name. */
    public function getLimiter(): string
    {
        return $this->limiter;
    }

    /** When the next request will be accepted. */
    public function getRetryAfter(): \DateTimeImmutable
    {
        return $this->retryAfter;
    }

    /**
     * Whole seconds until then, never below 1 — what the Retry-After header
     * carries.
     */
    public function getRetryAfterSeconds(): int
    {
        return max(1, $this->retryAfter->getTimestamp() - time());
    }

    /** The window's size, for a RateLimit-Limit header or a message. */
    public function getLimit(): int
    {
        return $this->limit;
    }
}
