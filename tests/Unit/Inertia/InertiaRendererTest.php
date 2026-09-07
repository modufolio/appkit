<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Inertia;

use Modufolio\Appkit\Inertia\CallableRootView;
use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\Inertia;
use Modufolio\Appkit\Inertia\InertiaRenderer;
use Modufolio\Appkit\Inertia\Page;
use Modufolio\Appkit\Inertia\SharedPropsInterface;
use Modufolio\Appkit\Inertia\Testing\InertiaPage;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

final class InertiaRendererTest extends TestCase
{
    /** @param array<string, mixed> $shared */
    private function renderer(array $shared = [], string|\Closure $version = ''): InertiaRenderer
    {
        $props = new class ($shared) implements SharedPropsInterface {
            /** @param array<string, mixed> $shared */
            public function __construct(private readonly array $shared)
            {
            }

            public function create(): array
            {
                return $this->shared;
            }
        };

        return new InertiaRenderer(
            new CallableRootView(static fn (Page $page): string => Inertia::snippet($page)),
            $version,
            $props,
        );
    }

    public function testSharedPropsGoUnderneathThePagesOwn(): void
    {
        $navigationBuilt = 0;
        $renderer = $this->renderer([
            'auth'       => fn () => ['user' => 'Ada'],
            'navigation' => function () use (&$navigationBuilt): array { ++$navigationBuilt; return ['Home']; },
            'title'      => 'shared',
        ]);

        $page = InertiaPage::fromResponse($renderer->render('X', ['title' => 'own'], new ServerRequest('GET', '/', [Header::INERTIA => 'true'])));

        self::assertSame(['auth' => ['user' => 'Ada'], 'navigation' => ['Home'], 'title' => 'own'], $page->props());
        self::assertSame(1, $navigationBuilt);
    }

    public function testASharedClosureIsNotComputedForAPartialReloadThatLeavesItOut(): void
    {
        $renderer = $this->renderer([
            'navigation' => fn () => self::fail('navigation was built for a reload that did not ask for it'),
        ]);

        $page = InertiaPage::fromResponse($renderer->render('X', ['board' => [1, 2]], new ServerRequest('GET', '/', [
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'X',
            Header::PARTIAL_DATA => 'board',
        ])));

        self::assertSame(['board' => [1, 2]], $page->props());
    }

    public function testTheVersionReachesEveryResponse(): void
    {
        $renderer = $this->renderer([], fn (): string => 'abc');

        self::assertSame('abc', $renderer->version());
        self::assertSame('abc', InertiaPage::fromResponse($renderer->render('X', [], new ServerRequest('GET', '/', [Header::INERTIA => 'true'])))->version());
        self::assertSame('abc', InertiaPage::fromResponse($renderer->render('X', [], new ServerRequest('GET', '/')))->version(), 'From the document too.');
    }

    public function testVersionFromFileHashesTheFileAndIsEmptyWithoutOne(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'inertia');
        self::assertNotFalse($file);
        file_put_contents($file, 'assets-1');

        try {
            self::assertSame(md5('assets-1'), InertiaRenderer::versionFromFile($file)());
            self::assertSame('', InertiaRenderer::versionFromFile($file.'.missing')());
        } finally {
            unlink($file);
        }
    }

    public function testRespondDeliversAPageBuiltByTheController(): void
    {
        $renderer = $this->renderer(['auth' => 'shared']);

        $response = $renderer->respond(
            Inertia::render('X', ['a' => 1])->with('b', 2)->encryptHistory(),
            new ServerRequest('GET', '/', [Header::INERTIA => 'true']),
        );
        $page = InertiaPage::fromResponse($response);

        self::assertSame(['auth' => 'shared', 'a' => 1, 'b' => 2], $page->props());
        self::assertTrue($page->toArray()['encryptHistory']);
    }

    public function testLocationIsAvailableFromTheRendererToo(): void
    {
        self::assertSame(409, $this->renderer()->location('/login')->getStatusCode());
    }
}
