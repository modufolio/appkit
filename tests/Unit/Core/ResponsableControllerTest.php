<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Inertia\Testing\InertiaPage;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * A controller may return what a response is made of — an Inertia page, or
 * something that becomes a response once it knows the request — and the
 * kernel finishes it. Run without a firewall, like TrustedHostsKernelTest:
 * the conversion is all this is about.
 */
final class ResponsableControllerTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app()->configureSecurity(new SecurityConfigurator());
    }

    public function tearDown(): void
    {
        $this->app()->accessControlRules = null;
        parent::tearDown();
    }

    public function testAResponsableReturnedByAControllerIsConvertedWithTheRequest(): void
    {
        $response = $this->get('/public/responsable');

        $response->assertOk();
        self::assertSame('converted', $response->getContent());
        self::assertSame('/public/responsable', $response->getResponse()->getHeaderLine('X-Converted-For'));
    }

    public function testAnInertiaPageIsFinishedByTheRendererTheHostWired(): void
    {
        $response = $this->get('/public/inertia', [], [Header::INERTIA => 'true']);

        $response->assertOk();
        self::assertSame('true', $response->getResponse()->getHeaderLine(Header::INERTIA));

        $page = InertiaPage::fromResponse($response->getResponse());
        self::assertSame('Test/Page', $page->component());
        self::assertSame(['auth' => ['user' => null], 'a' => 1, 'lazy' => 'computed'], $page->props(), 'Shared props underneath the page\'s own.');
        self::assertSame('test-assets', $page->version(), 'The module\'s version.');
        self::assertSame(['saved' => true], $page->flash());
        self::assertSame('/public/inertia', $page->url());
    }

    public function testAFirstVisitGetsTheDocumentFromTheHostsRootView(): void
    {
        $response = $this->get('/public/inertia');

        $response->assertOk();
        self::assertStringStartsWith('text/html', $response->getResponse()->getHeaderLine('Content-Type'));
        self::assertStringContainsString('X-Inertia', $response->getResponse()->getHeaderLine('Vary'));
        self::assertSame('Test/Page', InertiaPage::fromResponse($response->getResponse())->component());
    }

    public function testAPartialReloadComputesOnlyWhatItAsksFor(): void
    {
        $response = $this->get('/public/inertia', [], [
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'Test/Page',
            Header::PARTIAL_DATA => 'a',
        ]);

        self::assertSame(['a' => 1], InertiaPage::fromResponse($response->getResponse())->props());
    }
}
