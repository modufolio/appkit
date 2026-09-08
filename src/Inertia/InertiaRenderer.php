<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia;

use Modufolio\Appkit\Inertia\Flash\ArrayFlashStore;
use Modufolio\Appkit\Inertia\Flash\FlashStoreInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns an {@see Inertia} page into a response. The one object a host
 * wires: it knows the root view (the HTML document a first visit receives),
 * the asset version, the shared props and where flash data waits between
 * requests. The kernel asks it through {@see \Modufolio\Appkit\Core\Kernel::inertia()}
 * whenever a controller returns a page.
 *
 * Shared props go underneath a page's own for the same key. Both may hold
 * closures; nothing is computed before the request has said which props
 * travel. The shared keys are listed on the page as `sharedProps`, which
 * the client keeps across its own client-side visits.
 *
 * The version handshake: an XHR GET whose `X-Inertia-Version` differs from
 * the server's answers 409 with `X-Inertia-Location`, and the client reloads
 * the page in full. Every response carries `Vary: X-Inertia`.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class InertiaRenderer
{
    /** @var array<string, string> Hashed once per process, per file */
    private static array $fileVersions = [];

    private readonly FlashStoreInterface $flashStore;

    /**
     * @param string|\Closure(): string $version    the asset version, or how to get it;
     *                                              a closure is asked once per response
     * @param FlashStoreInterface|null  $flashStore where flash survives a redirect; the
     *                                              in-memory store when a host has no session
     */
    public function __construct(
        private readonly RootViewInterface $rootView,
        private readonly string|\Closure $version = '',
        private readonly ?SharedPropsInterface $shared = null,
        ?FlashStoreInterface $flashStore = null,
    ) {
        $this->flashStore = $flashStore ?? new ArrayFlashStore();
    }

    /**
     * The response for a page: the host's document for a browser visit, the
     * page object as JSON for the client's XHR, or a 409 telling a client
     * built against other assets to reload before it renders props its
     * components no longer understand (GET only: a write must not be
     * replayed; the flash stays put for the reload).
     */
    public function respond(Inertia $page, ServerRequestInterface $request): ResponseInterface
    {
        if (!$request->hasHeader(Header::INERTIA)) {
            return $this->rootView->render($this->toPage($page, $request), $request)
                ->withAddedHeader('Vary', Header::INERTIA);
        }

        $version = $this->version();

        if (
            'GET' === strtoupper($request->getMethod())
            && $request->hasHeader(Header::VERSION)
            && $request->getHeaderLine(Header::VERSION) !== $version
        ) {
            return Inertia::location(self::urlOf($request))->withHeader(Header::VERSION, $version);
        }

        return Response::json($this->toPage($page, $request)->toJson(), 200, null, [
            'Vary' => Header::INERTIA,
            Header::INERTIA => 'true',
        ]);
    }

    /**
     * The page object this request receives. Pulls what the flash store
     * holds — except for a prefetch, which the client may never show: the
     * store is left for the visit that does, and the page carries no flash
     * beyond what the controller put on it.
     */
    public function toPage(Inertia $page, ServerRequestInterface $request): Page
    {
        // Shared props first, and only then the pull: a host's shared props
        // may read the same flash bag the store drains (an auth page showing
        // one message inline), and reading a drained bag yields nothing.
        // Hoisting the pull above this call is what broke it once.
        $shared = $this->shared?->create() ?? [];

        $flash = self::isPrefetch($request)
            ? $page->flashed()
            : [...$this->flashStore->pull(), ...$page->flashed()];

        return new Page(
            $page->component(),
            $page->props(),
            self::urlOf($request),
            $this->version(),
            $request,
            $page->encryptsHistory(),
            $page->clearsHistory(),
            $shared,
            $flash,
            $page->preservesFragment(),
        );
    }

    /**
     * {@see respond()} for a component and props, in one call.
     *
     * @param array<string, mixed> $props
     */
    public function render(string $component, array $props, ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(Inertia::render($component, $props), $request);
    }

    /**
     * Flash data for the next page — before a redirect, typically:
     * `$inertia->flash('saved', true); return Response::redirect(...)`.
     *
     * @param string|array<string, mixed> $key
     */
    public function flash(string|array $key, mixed $value = null): self
    {
        $this->flashStore->put(is_array($key) ? $key : [$key => $value]);

        return $this;
    }

    public function location(string $url): ResponseInterface
    {
        return Inertia::location($url);
    }

    public function version(): string
    {
        return $this->version instanceof \Closure ? ($this->version)() : $this->version;
    }

    public function flashStore(): FlashStoreInterface
    {
        return $this->flashStore;
    }

    /**
     * A version from a file's contents: the build's manifest, an SRI map —
     * anything that changes when the assets do. Hashed once per process;
     * an absent file means no version, and no handshake.
     *
     * @return \Closure(): string
     */
    public static function versionFromFile(string $path): \Closure
    {
        return static function () use ($path): string {
            return self::$fileVersions[$path] ??= is_file($path) ? (string) md5_file($path) : '';
        };
    }

    /** A request the client makes ahead of a visit it may never make. */
    public static function isPrefetch(ServerRequestInterface $request): bool
    {
        return 'prefetch' === strtolower($request->getHeaderLine(Header::PURPOSE));
    }

    /** Path and query, the way the client keys history: never the host. */
    private static function urlOf(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = '' === $uri->getPath() ? '/' : $uri->getPath();

        return '' === $uri->getQuery() ? $path : $path.'?'.$uri->getQuery();
    }
}
