<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Routing;

/**
 * Declares redirects for the `redirect` route type.
 *
 * Two forms:
 *
 *  - `redirect('/old', '/new')` — a literal target, for external URLs and
 *    paths outside the app. The target must stay a static string: the moment
 *    request data reaches it, this becomes an open-redirect primitive.
 *  - `redirectToRoute('/old', 'blog_index')` — an internal route name,
 *    resolved through the UrlGenerator when the redirect is served. Prefer
 *    this for anything the app routes itself: renames propagate, and an
 *    unknown name fails loudly instead of silently redirecting into a 404.
 *
 * Cycles between literal-path redirects are refused at load time with the
 * full chain printed (see RedirectRouteLoader).
 */
final class RedirectConfigurator
{
    /**
     * Codes with redirect semantics. 301/308 are cached aggressively by
     * browsers; 307/308 preserve the request method where 301/302 allow a
     * POST to be replayed as GET.
     */
    private const ALLOWED_STATUS_CODES = [301, 302, 303, 307, 308];

    /** @var list<array{source: string, target: ?string, routeName: ?string, routeParams: array<string, mixed>, statusCode: int}> */
    private array $redirects = [];

    public function redirect(string $source, string $target, int $statusCode = 301): self
    {
        $this->assertRedirectStatus($statusCode, $source);

        $this->redirects[] = [
            'source' => $source,
            'target' => $target,
            'routeName' => null,
            'routeParams' => [],
            'statusCode' => $statusCode,
        ];

        return $this;
    }

    /**
     * Redirect to a named route. The target URL is generated when the
     * redirect is served, so route renames propagate and an unknown route
     * name throws a loud RouteNotFoundException instead of pointing at a 404.
     *
     * @param array<string, mixed> $routeParams
     */
    public function redirectToRoute(string $source, string $routeName, array $routeParams = [], int $statusCode = 301): self
    {
        $this->assertRedirectStatus($statusCode, $source);

        $this->redirects[] = [
            'source' => $source,
            'target' => null,
            'routeName' => $routeName,
            'routeParams' => $routeParams,
            'statusCode' => $statusCode,
        ];

        return $this;
    }

    /**
     * @return list<array{source: string, target: ?string, routeName: ?string, routeParams: array<string, mixed>, statusCode: int}>
     */
    public function getRedirects(): array
    {
        return $this->redirects;
    }

    private function assertRedirectStatus(int $statusCode, string $source): void
    {
        if (!\in_array($statusCode, self::ALLOWED_STATUS_CODES, true)) {
            throw new \InvalidArgumentException(sprintf('Redirect for "%s" declares status %d, which has no redirect semantics — use one of %s.', $source, $statusCode, implode(', ', self::ALLOWED_STATUS_CODES)));
        }
    }
}
