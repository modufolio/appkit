<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Inertia;

use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\Inertia;
use Modufolio\Appkit\Inertia\Page;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function request(array $headers = [], string $uri = '/users/1'): ServerRequest
    {
        return new ServerRequest('GET', $uri, [Header::INERTIA => 'true', ...$headers]);
    }

    /** @param array<string, mixed> $props */
    private function page(array $props, ServerRequest $request): Page
    {
        return new Page('Users/Edit', $props, '/users/1', 'v1', $request);
    }

    public function testAFullLoadSendsPlainAndAlwaysPropsAndListsTheDeferredOnes(): void
    {
        $computed = 0;
        $page = $this->page([
            'user'     => ['name' => 'Ada'],
            'roles'    => function () use (&$computed): array { ++$computed; return ['admin']; },
            'flash'    => Inertia::always(['ok']),
            'optional' => Inertia::optional(fn () => self::fail('optional is never computed on a full load')),
            'activity' => Inertia::defer(fn () => self::fail('deferred is never computed on a full load')),
            'stats'    => Inertia::defer(fn () => self::fail('deferred is never computed on a full load'), 'aside'),
        ], $this->request());

        self::assertSame(['user' => ['name' => 'Ada'], 'roles' => ['admin'], 'flash' => ['ok']], $page->props());
        self::assertSame(1, $computed, 'A closure prop is computed exactly once.');
        self::assertSame(['default' => ['activity'], 'aside' => ['stats']], $page->toArray()['deferredProps']);
        self::assertFalse($page->isPartial());
        self::assertSame(['component', 'props', 'url', 'version', 'deferredProps'], array_keys($page->toArray()));
    }

    public function testAPartialReloadNarrowsToOnlyAndKeepsAlwaysProps(): void
    {
        $page = $this->page([
            'user'     => fn () => self::fail('not asked for, not computed'),
            'roles'    => fn () => ['admin'],
            'flash'    => Inertia::always(fn () => ['ok']),
            'activity' => Inertia::defer(fn () => ['a', 'b']),
        ], $this->request([
            Header::PARTIAL_COMPONENT => 'Users/Edit',
            Header::PARTIAL_DATA => 'roles, activity',
        ]));

        self::assertTrue($page->isPartial());
        // Declared order: an always-prop keeps its place, it is not appended.
        self::assertSame(['roles' => ['admin'], 'flash' => ['ok'], 'activity' => ['a', 'b']], $page->props());
        self::assertArrayNotHasKey('deferredProps', $page->toArray(), 'A partial reload answers with data, not a list to fetch.');
    }

    public function testAPartialReloadHonoursExcept(): void
    {
        $page = $this->page([
            'user'  => ['name' => 'Ada'],
            'roles' => fn () => self::fail('excepted, not computed'),
            'flash' => Inertia::always(['ok']),
        ], $this->request([
            Header::PARTIAL_COMPONENT => 'Users/Edit',
            Header::PARTIAL_EXCEPT => 'roles,flash',
        ]));

        self::assertSame(['user' => ['name' => 'Ada'], 'flash' => ['ok']], $page->props(), 'Always wins over except.');
    }

    public function testAPartialHeaderForAnotherComponentIsAFullLoad(): void
    {
        $page = $this->page([
            'user'     => ['name' => 'Ada'],
            'activity' => Inertia::defer(fn () => []),
        ], $this->request([
            Header::PARTIAL_COMPONENT => 'Users/Index',
            Header::PARTIAL_DATA => 'activity',
        ]));

        self::assertFalse($page->isPartial());
        self::assertSame(['user' => ['name' => 'Ada']], $page->props());
        self::assertSame(['default' => ['activity']], $page->toArray()['deferredProps']);
    }

    public function testOptionalPropsTravelOnlyWhenNamed(): void
    {
        $page = $this->page([
            'user'  => ['name' => 'Ada'],
            'heavy' => Inertia::optional(fn () => 'computed'),
        ], $this->request([Header::PARTIAL_COMPONENT => 'Users/Edit', Header::PARTIAL_DATA => 'heavy']));

        self::assertSame(['heavy' => 'computed'], $page->props());
    }

    public function testMergePropsAreListedUnlessReset(): void
    {
        $props = [
            'feed'  => Inertia::merge(['c']),
            'tree'  => Inertia::deepMerge(['x' => 1]),
            'more'  => Inertia::defer(fn () => ['d'])->merge(),
            'plain' => 1,
        ];

        $full = $this->page($props, $this->request())->toArray();
        // A deferred prop that merges is announced on the first load too, so the
        // client merges the page it fetches right after rendering.
        self::assertSame(['feed', 'more'], $full['mergeProps']);
        self::assertSame(['tree'], $full['deepMergeProps']);

        $partial = $this->page($props, $this->request([
            Header::PARTIAL_COMPONENT => 'Users/Edit',
            Header::PARTIAL_DATA => 'feed,more,tree',
            Header::RESET => 'feed',
        ]))->toArray();
        self::assertSame(['more'], $partial['mergeProps'], 'A reset prop is replaced this once; a merging deferred prop merges.');
        self::assertSame(['tree'], $partial['deepMergeProps']);
        self::assertSame(['feed' => ['c'], 'tree' => ['x' => 1], 'more' => ['d']], $partial['props']);
    }

    public function testClosuresInsideSharedValuesResolveToo(): void
    {
        $page = $this->page([
            'auth' => ['user' => fn () => ['name' => 'Ada'], 'impersonating' => false],
        ], $this->request());

        self::assertSame(['auth' => ['user' => ['name' => 'Ada'], 'impersonating' => false]], $page->props());
    }

    public function testHistoryFlagsAppearOnlyWhenSet(): void
    {
        $plain = new Page('X', [], '/', '', $this->request());
        self::assertSame(['component', 'props', 'url', 'version'], array_keys($plain->toArray()));

        $flagged = new Page('X', [], '/', '', $this->request(), encryptHistory: true, clearHistory: true);
        self::assertTrue($flagged->toArray()['encryptHistory']);
        self::assertTrue($flagged->toArray()['clearHistory']);
    }

    public function testJsonIsSafeInsideAnHtmlAttribute(): void
    {
        $page = new Page('X', ['html' => '<b>&"\''], '/', '', $this->request());

        self::assertStringNotContainsString('<', $page->toJson());
        self::assertStringNotContainsString('"\'', $page->toJson());
        $decoded = json_decode($page->toJson(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['props']);
        self::assertSame('<b>&"\'', $decoded['props']['html']);
    }
}
