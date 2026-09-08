<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Inertia;

use Modufolio\Appkit\Inertia\CallableRootView;
use Modufolio\Appkit\Inertia\Flash\FlashStoreInterface;
use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\Inertia;
use Modufolio\Appkit\Inertia\InertiaRenderer;
use Modufolio\Appkit\Inertia\Page;
use Modufolio\Appkit\Inertia\SharedPropsInterface;
use Modufolio\Appkit\Inertia\Testing\InertiaPage;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Shared props are created before the flash store is drained.
 *
 * A host whose shared props read the same place the store pulls from — the
 * session's flash bag, an auth page showing one message inline next to the
 * page's own `flash` key — sees the messages only while they are still
 * there. Pulling first empties the bag under them.
 */
final class SharedPropsFlashOrderTest extends TestCase
{
    public function testSharedPropsSeeTheFlashTheStoreIsAboutToDrain(): void
    {
        $store = new class implements FlashStoreInterface {
            public bool $drained = false;
            /** @var array<string, mixed> */
            private array $data = [];

            public function put(array $data): void
            {
                $this->data = [...$this->data, ...$data];
            }

            public function peek(): array
            {
                return $this->data;
            }

            public function pull(): array
            {
                $this->drained = true;
                $data = $this->data;
                $this->data = [];

                return $data;
            }
        };
        $store->put(['saved' => true]);

        $shared = new class($store) implements SharedPropsInterface {
            public function __construct(private readonly FlashStoreInterface $store)
            {
            }

            /** Read eagerly, the way a host that shows a message inline does. */
            public function create(): array
            {
                return ['inline' => $this->store->peek()];
            }
        };

        $renderer = new InertiaRenderer(
            new CallableRootView(static fn (Page $page): string => Inertia::snippet($page)),
            '',
            $shared,
            $store,
        );

        $page = InertiaPage::fromResponse(
            $renderer->render('Dashboard', [], new ServerRequest('GET', '/panel', [Header::INERTIA => 'true'])),
        );

        self::assertTrue($store->drained, 'The store is still pulled for the page itself.');
        self::assertSame(['saved' => true], $page->prop('inline'), 'The shared prop read a bag that was already empty.');
        self::assertSame(['saved' => true], $page->flash());
    }
}
