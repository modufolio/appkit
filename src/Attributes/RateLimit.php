<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Attributes;

/**
 * Throttle a route through a rate limiter declared in config/security.php.
 *
 *     #[RateLimit('api')]
 *     #[Route('/api/search', methods: ['GET'])]
 *     public function search(): ResponseInterface
 *
 * The limiter's policy and window are the configuration's; the attribute
 * only names it and says what a client is. Several attributes on one route
 * all apply (a class-level one for the controller, a stricter method-level
 * one for an expensive action), and a request that exhausts any of them is
 * answered 429 with a Retry-After header before the controller runs.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RateLimit
{
    /** Count per signed-in user, per client address for anonymous requests. */
    public const BY_AUTO = 'auto';
    /** Count per client address (REMOTE_ADDR), signed in or not. */
    public const BY_IP = 'ip';
    /** Count per signed-in user; an anonymous request falls back to the address. */
    public const BY_USER = 'user';

    public const BY = [self::BY_AUTO, self::BY_IP, self::BY_USER];

    /**
     * @param string $limiter the name given to SecurityConfigurator::rateLimiter()
     * @param string $by      what one client is: one of the BY_* constants
     */
    public function __construct(
        public readonly string $limiter,
        public readonly string $by = self::BY_AUTO,
    ) {
        if ('' === $limiter) {
            throw new \InvalidArgumentException('#[RateLimit] must name a limiter.');
        }
        if (!\in_array($by, self::BY, true)) {
            throw new \InvalidArgumentException(sprintf('#[RateLimit] "by" must be one of "%s"; "%s" given.', implode('", "', self::BY), $by));
        }
    }
}
