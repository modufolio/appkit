<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Inertia;

use Modufolio\Appkit\Inertia\CallableRootView;
use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\Inertia;
use Modufolio\Appkit\Inertia\InertiaRenderer;
use Modufolio\Appkit\Inertia\Page;
use Modufolio\Appkit\Inertia\Testing\InertiaPage;
use Modufolio\Psr7\Http\Response;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

final class InertiaPageTest extends TestCase
{
    private function renderer(string $version = ''): InertiaRenderer
    {
        return new InertiaRenderer(
            new CallableRootView(static fn (Page $page): string => '<html>'.Inertia::snippet($page).'</html>'),
            $version,
        );
    }

    public function testItReadsAJsonResponse(): void
    {
        $response = $this->renderer('v1')->respond(Inertia::render('Users/Edit', [
            'user'     => ['name' => 'Ada', 'roles' => ['admin']],
            'activity' => Inertia::defer(fn () => []),
            'feed'     => Inertia::merge([1]),
        ]), new ServerRequest('GET', '/users/1', [Header::INERTIA => 'true']));

        $page = InertiaPage::fromResponse($response);

        self::assertSame('Users/Edit', $page->component());
        self::assertSame('Ada', $page->prop('user.name'));
        self::assertSame(['admin'], $page->prop('user.roles'));
        self::assertNull($page->prop('user.missing.deeper'));
        self::assertTrue($page->has('user'));
        self::assertFalse($page->has('activity'));
        self::assertSame('/users/1', $page->url());
        self::assertSame('v1', $page->version());
        self::assertSame(['default' => ['activity']], $page->deferredProps());
        self::assertSame(['feed'], $page->mergeProps());
    }

    public function testItReadsARenderedDocument(): void
    {
        $response = $this->renderer()->respond(
            Inertia::render('Users/Edit', ['user' => ['name' => 'Ada & "Bob" <i>']]),
            new ServerRequest('GET', '/users/1'),
        );

        $page = InertiaPage::fromResponse($response);

        self::assertSame('Users/Edit', $page->component());
        self::assertSame('Ada & "Bob" <i>', $page->prop('user.name'), 'JSON escapes are undone.');
    }

    public function testItReadsTheOlderDataPageAttributeToo(): void
    {
        $json = htmlspecialchars('{"component":"Legacy","props":{"a":1},"url":"/","version":""}', ENT_QUOTES);
        $page = InertiaPage::fromResponse(Response::html('<div id="app" data-page="'.$json.'"></div>'));

        self::assertSame('Legacy', $page->component());
        self::assertSame(1, $page->prop('a'));
    }

    public function testItRefusesSomethingElse(): void
    {
        $this->expectException(\LogicException::class);

        InertiaPage::fromResponse(Response::html('<p>plain</p>'));
    }
}
