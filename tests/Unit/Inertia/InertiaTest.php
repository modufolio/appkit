<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Inertia;

use Modufolio\Appkit\Inertia\CallableRootView;
use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\Inertia;
use Modufolio\Appkit\Inertia\InertiaRenderer;
use Modufolio\Appkit\Inertia\Page;
use Modufolio\Appkit\Inertia\Testing\InertiaPage;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

/** The page value, and how the renderer delivers it. */
final class InertiaTest extends TestCase
{
    /** @param string|\Closure(): string $version */
    private function renderer(string|\Closure $version = ''): InertiaRenderer
    {
        return new InertiaRenderer(
            new CallableRootView(static fn (Page $page): string => '<html><body>'.Inertia::snippet($page).'</body></html>'),
            $version,
        );
    }

    public function testAnXhrVisitGetsThePageAsJsonWithVary(): void
    {
        $response = $this->renderer('v1')->respond(
            Inertia::render('Users/Edit', ['user' => ['name' => 'Ada']]),
            new ServerRequest('GET', '/users/1', [Header::INERTIA => 'true']),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('X-Inertia', $response->getHeaderLine('Vary'));
        self::assertSame('true', $response->getHeaderLine(Header::INERTIA));

        $page = InertiaPage::fromResponse($response);
        self::assertSame('Users/Edit', $page->component());
        self::assertSame(['user' => ['name' => 'Ada']], $page->props());
        self::assertSame('/users/1', $page->url());
        self::assertSame('v1', $page->version());
    }

    public function testAFirstVisitGetsTheDocumentFromTheRootView(): void
    {
        $response = $this->renderer()->respond(
            Inertia::render('Users/Edit', ['user' => ['name' => 'Ada <b>']]),
            new ServerRequest('GET', '/users/1'),
        );

        $html = (string) $response->getBody();
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('X-Inertia', $response->getHeaderLine('Vary'));
        self::assertStringContainsString('<script data-page="app" type="application/json">', $html);
        self::assertStringContainsString('</script><div id="app"></div>', $html);
        self::assertStringContainsString('"component":"Users\\/Edit"', $html);
        self::assertStringNotContainsString('<b>', $html, 'A value can never close the script element.');
    }

    public function testAStaleClientIsToldToReloadInFull(): void
    {
        $response = $this->renderer(fn (): string => 'v2')->respond(
            Inertia::render('Users/Edit'),
            new ServerRequest('GET', '/users/1?x=1', [Header::INERTIA => 'true', Header::VERSION => 'v1']),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('/users/1?x=1', $response->getHeaderLine(Header::LOCATION));
        self::assertSame('v2', $response->getHeaderLine(Header::VERSION), 'The 409 names the current version.');
        self::assertSame('X-Inertia', $response->getHeaderLine('Vary'));
    }

    public function testAMatchingOrAbsentVersionHeaderPasses(): void
    {
        $renderer = $this->renderer('v1');
        $matching = $renderer->respond(Inertia::render('X'), new ServerRequest('GET', '/', [Header::INERTIA => 'true', Header::VERSION => 'v1']));
        $absent = $renderer->respond(Inertia::render('X'), new ServerRequest('GET', '/', [Header::INERTIA => 'true']));

        self::assertSame(200, $matching->getStatusCode());
        self::assertSame(200, $absent->getStatusCode());
    }

    public function testAStaleWriteIsNeverReplayed(): void
    {
        $response = $this->renderer('v2')->respond(
            Inertia::render('X'),
            new ServerRequest('POST', '/users', [Header::INERTIA => 'true', Header::VERSION => 'v1']),
        );

        self::assertSame(200, $response->getStatusCode(), 'Only a GET is asked to reload; a POST answers.');
    }

    public function testLocationIsA409WithTheUrl(): void
    {
        $response = Inertia::location('https://example.com/export.csv');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('https://example.com/export.csv', $response->getHeaderLine(Header::LOCATION));
        self::assertSame(409, $this->renderer()->location('/login')->getStatusCode(), 'From the renderer too.');
    }

    public function testWithAddsPropsAndTheLaterValueWins(): void
    {
        $page = Inertia::render('X', ['a' => 1, 'b' => 1])->with('b', 2)->with(['c' => 3]);

        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $page->props());
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $this->renderer()->toPage($page, new ServerRequest('GET', '/'))->props());
    }

    public function testThePageValueKeepsWhatTheControllerAskedFor(): void
    {
        $page = Inertia::render('X')->encryptHistory()->clearHistory()->preserveFragment()->flash('saved', true)->flash(['n' => 2]);

        self::assertSame('X', $page->component());
        self::assertTrue($page->encryptsHistory());
        self::assertTrue($page->clearsHistory());
        self::assertTrue($page->preservesFragment());
        self::assertSame(['saved' => true, 'n' => 2], $page->flashed());

        $array = $this->renderer()->toPage($page, new ServerRequest('GET', '/'))->toArray();
        self::assertTrue($array['encryptHistory']);
        self::assertTrue($array['clearHistory']);
        self::assertTrue($array['preserveFragment']);
        self::assertSame(['saved' => true, 'n' => 2], $array['flash']);
    }

    public function testAnEmptyPathIsTheRoot(): void
    {
        $page = $this->renderer()->toPage(Inertia::render('X'), new ServerRequest('GET', 'https://example.com'));

        self::assertSame('/', $page->url());
    }
}
