<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl;

/**
 * The single implementation of security path matching.
 *
 * Both firewall selection and access-control rules match request paths with
 * this class, so the two views of a path can never diverge — an encoded byte
 * or a segment-boundary difference that slips one matcher but not the other
 * would otherwise be an authentication bypass.
 *
 * Pattern syntax is plain — NOT regex — to avoid ReDoS risk:
 *  - "/api"  → prefix match on whole path segments: matches "/api" and
 *    "/api/users" but NOT "/apix". A leading slash is added if missing,
 *    and "/" matches every path (catch-all).
 *  - "api:0" → matches when the zero-indexed path segment equals the value:
 *    "api:0" matches "/api/...", "users:1" matches "/api/users/...".
 */
final class RequestMatcher
{
    public static function matches(string $pattern, string $path): bool
    {
        if (str_contains($pattern, ':')) {
            [$value, $pos] = explode(':', $pattern, 2);

            return self::matchesSegment($value, (int) $pos, $path);
        }

        return self::matchesPrefix($pattern, $path);
    }

    /**
     * Matches a specific path segment at a given position.
     */
    public static function matchesSegment(string $value, int $position, string $path): bool
    {
        $segments = explode('/', trim($path, '/'));

        return isset($segments[$position]) && $segments[$position] === $value;
    }

    /**
     * Matches if the path starts with the pattern, on whole path segments:
     * "/admin" matches "/admin" and "/admin/users" but NOT "/administrator".
     */
    public static function matchesPrefix(string $pattern, string $path): bool
    {
        if (!isset($pattern[0]) || '/' !== $pattern[0]) {
            $pattern = '/'.ltrim($pattern, '/');
        }

        $normalized = rtrim($pattern, '/');

        return '' === $normalized
            || $path === $normalized
            || str_starts_with($path, $normalized.'/');
    }

    /**
     * The request path as the router will see it, for security matching.
     *
     * The Symfony URL matcher rawurldecode()s the path before resolving the
     * controller (see UrlMatcher::match). Firewall and access-control matching
     * must decode the same way, or an encoded byte in a protected prefix
     * (e.g. "/%61pi" for "/api") slips past the firewall while the controller
     * still runs — an authentication bypass. Decoding here keeps the security
     * view and the routing view of the path identical.
     */
    public static function securityPath(\Psr\Http\Message\UriInterface $uri): string
    {
        return rawurldecode($uri->getPath());
    }
}
