<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Inertia;

use Modufolio\Appkit\Inertia\CallableRootView;
use Modufolio\Appkit\Inertia\Flash\ArrayFlashStore;
use Modufolio\Appkit\Inertia\Flash\FlashStoreInterface;
use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\Inertia;
use Modufolio\Appkit\Inertia\InertiaRenderer;
use Modufolio\Appkit\Inertia\Page;
use Modufolio\Appkit\Inertia\Props\ScrollMetadata;
use Modufolio\Appkit\Inertia\Testing\InertiaPage;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

/** The Inertia 3 protocol beyond partial reloads: once, scroll, prepend, match-on, flash, fragments, shared keys, error bags. */
final class InertiaProtocolTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function request(array $headers = [], string $method = 'GET'): ServerRequest
    {
        return new ServerRequest($method, '/feed', [Header::INERTIA => 'true', ...$headers]);
    }

    /**
     * @param array<string, mixed>  $props
     * @param array<string, mixed>  $shared
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    private function page(array $props, array $headers = [], array $shared = []): InertiaPage
    {
        $page = new Page('Feed', $props, '/feed', 'v1', $this->request($headers), shared: $shared);

        return InertiaPage::fromJson($page->toJson());
    }

    /**
     * @throws \JsonException
     */
    public function testAOncePropIsSentOnceThenKeptByTheClient(): void
    {
        $props = ['settings' => Inertia::once(fn () => ['theme' => 'dark'])];

        $first = $this->page($props);
        self::assertSame(['theme' => 'dark'], $first->prop('settings'));
        self::assertSame(['settings' => ['prop' => 'settings', 'expiresAt' => null]], $first->onceProps());

        $later = $this->page($props, [Header::EXCEPT_ONCE_PROPS => 'settings']);
        self::assertFalse($later->has('settings'), 'The client says it holds it: not sent again.');
        self::assertSame(['settings'], array_keys($later->onceProps()), 'But still listed, so the client keeps it.');
    }

    /**
     * @throws \JsonException
     */
    public function testAOncePropCanBeNamedExpiredAndForcedFresh(): void
    {
        $named = $this->page(['settings' => Inertia::once(fn () => 1)->as('prefs')->until(60)], [Header::EXCEPT_ONCE_PROPS => 'prefs']);
        self::assertFalse($named->has('settings'));
        self::assertArrayHasKey('prefs', $named->onceProps());
        self::assertGreaterThan(time() * 1000, $named->onceProps()['prefs']['expiresAt']);

        $fresh = $this->page(['settings' => Inertia::once(fn () => 1)->fresh()], [Header::EXCEPT_ONCE_PROPS => 'settings']);
        self::assertSame(1, $fresh->prop('settings'), 'fresh() sends it regardless.');
    }

    /**
     * @throws \JsonException
     */
    public function testAScrollPropMergesInsideItsWrapperAndCarriesPageMetadata(): void
    {
        $page = ['data' => [['id' => 3], ['id' => 4]], 'total' => 90];
        // A prop instance carries the merge intent it was configured with, so
        // each request gets its own — as a controller builds one per request.
        $props = fn (): array => ['feed' => Inertia::scroll($page, ScrollMetadata::fromPage(2, 25, 90))];

        $appended = $this->page($props());
        self::assertSame(['feed.data'], $appended->mergeProps());
        self::assertSame([], $appended->prependProps());
        self::assertSame([
            'feed' => ['pageName' => 'page', 'previousPage' => 1, 'nextPage' => 3, 'currentPage' => 2, 'reset' => false],
        ], $appended->scrollProps());
        self::assertSame($page, $appended->prop('feed'));

        $prepended = $this->page($props(), [Header::INFINITE_SCROLL_MERGE_INTENT => 'prepend']);
        self::assertSame(['feed.data'], $prepended->prependProps());
        self::assertSame([], $prepended->mergeProps());

        $reset = $this->page($props(), [Header::RESET => 'feed']);
        self::assertTrue($reset->scrollProps()['feed']['reset']);
        self::assertSame([], $reset->mergeProps(), 'A reset scroll prop replaces this once.');
    }

    public function testAScrollPropMayBeDeferred(): void
    {
        $props = ['feed' => Inertia::scroll(fn () => self::fail('deferred: not computed on the first load'), new ScrollMetadata('page', null, 2, 1))->defer('feed')];

        $first = $this->page($props);
        self::assertFalse($first->has('feed'));
        self::assertSame(['feed' => ['feed']], $first->deferredProps());
        // Announced at its root: the wrapper is configured only when the prop
        // resolves, on the fetch that carries the data — as the reference does.
        self::assertSame(['feed'], $first->mergeProps());
    }

    /**
     * @throws \JsonException
     */
    public function testMergePropsCanPrependAndMatchOnAKey(): void
    {
        $props = [
            'messages' => Inertia::merge(['data' => [['id' => 9]]])->prepend('data', 'id'),
            'users' => Inertia::merge([['id' => 1]])->matchOn('id'),
        ];

        $page = $this->page($props);
        self::assertSame(['users'], $page->mergeProps());
        self::assertSame(['messages.data'], $page->prependProps());
        self::assertSame(['messages.data.id', 'users.id'], $page->matchPropsOn());
    }

    /**
     * @throws \JsonException
     */
    public function testPartialReloadsMatchNestedPathsAndUnpackDotProps(): void
    {
        $props = [
            'user' => ['name' => 'Ada', 'roles' => fn () => self::fail('user.roles was not asked for')],
            'user.email' => 'ada@example.com',
            'stats' => fn () => self::fail('stats was not asked for'),
        ];

        $page = $this->page($props, [Header::PARTIAL_COMPONENT => 'Feed', Header::PARTIAL_DATA => 'user.name,user.email']);
        self::assertSame(['user' => ['name' => 'Ada', 'email' => 'ada@example.com']], $page->props());
    }

    /**
     * @throws \JsonException
     */
    public function testARescuedDeferredPropFailsSoftly(): void
    {
        $props = ['risky' => Inertia::defer(fn () => throw new \RuntimeException('down'), rescue: true), 'ok' => 1];

        $page = $this->page($props, [Header::PARTIAL_COMPONENT => 'Feed', Header::PARTIAL_DATA => 'risky,ok']);
        self::assertFalse($page->has('risky'));
        self::assertSame(['risky'], $page->rescuedProps());
        self::assertSame(1, $page->prop('ok'));

        $this->expectException(\RuntimeException::class);
        $this->page(['risky' => Inertia::defer(fn () => throw new \RuntimeException('down'))], [Header::PARTIAL_COMPONENT => 'Feed', Header::PARTIAL_DATA => 'risky']);
    }

    /**
     * @throws \JsonException
     */
    public function testSharedPropKeysAreListedAndTheErrorBagWrapsErrors(): void
    {
        $shared = ['auth' => fn () => ['user' => 'Ada'], 'errors' => ['email' => 'Taken.'], 'flash' => []];

        $page = $this->page(['own' => 1], [], $shared);
        self::assertSame(['auth', 'errors', 'flash'], $page->sharedProps());
        self::assertSame(['email' => 'Taken.'], $page->prop('errors'));

        $bagged = $this->page(['own' => 1], [Header::ERROR_BAG => 'signup'], $shared);
        self::assertSame(['signup' => ['email' => 'Taken.']], $bagged->prop('errors'));

        $partial = $this->page(['own' => 1], [Header::PARTIAL_COMPONENT => 'Feed', Header::PARTIAL_DATA => 'own', Header::ERROR_BAG => 'signup'], $shared);
        self::assertSame(['signup' => ['email' => 'Taken.']], $partial->prop('errors'), 'Bagged errors always travel.');
    }

    private function renderer(?FlashStoreInterface $store = null, string $version = ''): InertiaRenderer
    {
        return new InertiaRenderer(new CallableRootView(static fn (Page $page): string => Inertia::snippet($page)), $version, null, $store);
    }

    public function testFlashTravelsOnTheResponseAndSurvivesARedirectThroughTheStore(): void
    {
        $store = new ArrayFlashStore();
        $renderer = $this->renderer($store);

        $direct = Inertia::render('Feed', ['a' => 1])->flash('saved', true)->flash(['count' => 2]);
        $page = InertiaPage::fromResponse($renderer->respond($direct, $this->request()));
        self::assertSame(['saved' => true, 'count' => 2], $page->flash());

        $renderer->flash('saved', 'from the redirect');
        self::assertSame(['saved' => 'from the redirect'], $store->peek(), 'Waits in the store across the redirect.');
        $next = InertiaPage::fromResponse($renderer->respond(Inertia::render('Feed'), $this->request()));
        self::assertSame(['saved' => 'from the redirect'], $next->flash());
        self::assertSame([], $store->pull(), 'Delivered: the store is drained.');

        $none = InertiaPage::fromResponse($renderer->respond(Inertia::render('Feed'), $this->request()));
        self::assertSame([], $none->flash());
        self::assertArrayNotHasKey('flash', $none->toArray(), 'Absent when empty; the client defaults it.');
    }

    public function testAPrefetchLeavesTheFlashStoreForTheRealVisit(): void
    {
        $store = new ArrayFlashStore();
        $store->put(['saved' => true]);
        $renderer = $this->renderer($store);

        $prefetched = InertiaPage::fromResponse($renderer->respond(
            Inertia::render('Feed')->flash('hint', 'on the page itself'),
            $this->request([Header::PURPOSE => 'prefetch']),
        ));
        self::assertSame(['hint' => 'on the page itself'], $prefetched->flash(), 'Only what the controller put on this page.');
        self::assertSame(['saved' => true], $store->peek(), 'The store is not drained by a request that may never be shown.');

        $visit = InertiaPage::fromResponse($renderer->respond(Inertia::render('Feed'), $this->request()));
        self::assertSame(['saved' => true], $visit->flash(), 'The visit that follows gets it.');
    }

    public function testPreserveFragmentAndTheVersionHeaderOnAStaleReload(): void
    {
        $page = InertiaPage::fromResponse($this->renderer()->respond(Inertia::render('Feed')->preserveFragment(), $this->request()));
        self::assertTrue($page->preservesFragment());

        $stale = $this->renderer(null, 'v2')->respond(Inertia::render('Feed'), $this->request([Header::VERSION => 'v1']));
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('v2', $stale->getHeaderLine(Header::VERSION), 'The 409 names the current version.');

        $json = $this->renderer()->respond(Inertia::render('Feed'), $this->request());
        self::assertSame('true', $json->getHeaderLine(Header::INERTIA));
    }

    /**
     * @throws \JsonException
     */
    public function testAClosureMayReturnAPropType(): void
    {
        $page = $this->page(['late' => fn () => Inertia::defer(fn () => 'x')]);

        self::assertFalse($page->has('late'));
        self::assertSame(['default' => ['late']], $page->deferredProps());
    }
}
