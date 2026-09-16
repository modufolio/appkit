<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Template\Asset;

use Modufolio\Appkit\Template\Asset\AssetIntegrity;
use Modufolio\Appkit\Template\Asset\AssetVersioningInterface;
use Modufolio\Appkit\Template\Template;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\TestCase;

/**
 * What a template renders once versioning and SRI are wired: versioned
 * URLs under the base URL, integrity attributes keyed by the queued path,
 * absolute URLs left alone, and layouts rendered with the same wiring.
 */
class TemplateAssetsTest extends TestCase
{
    private const TEMPLATES = __DIR__.'/../fixtures/site/templates';
    private const LAYOUTS = __DIR__.'/../fixtures/site/layouts';

    private function versioning(): AssetVersioningInterface
    {
        return new class implements AssetVersioningInterface {
            public function version(string $path): string
            {
                return str_contains($path, '://') ? $path : preg_replace('/\.(css|js)$/', '.v1.$1', $path) ?? $path;
            }
        };
    }

    private function template(): Template
    {
        return new Template(
            name: 'default',
            templatePaths: [self::TEMPLATES],
            layoutPaths: [self::LAYOUTS],
            request: new ServerRequest('GET', new Uri('https://example.com/page')),
            versioning: $this->versioning(),
            integrity: new AssetIntegrity(['/assets/js/app.js' => 'sha384-abc']),
        );
    }

    public function testQueuedAssetsAreVersionedUnderTheBaseUrl(): void
    {
        $template = $this->template();
        $template->css('/assets/css/app.css');
        $template->js('/assets/js/app.js');

        $this->assertSame('<link href="https://example.com/assets/css/app.v1.css" rel="stylesheet">', $template->renderCss());
        $this->assertStringContainsString('src="https://example.com/assets/js/app.v1.js"', $template->renderJs());
    }

    public function testIntegrityIsRenderedForTheQueuedPathNotTheVersionedOne(): void
    {
        $template = $this->template();
        $template->js('/assets/js/app.js');
        $template->js('/assets/js/other.js');

        $tags = explode(PHP_EOL, $template->renderJs());

        $this->assertStringContainsString('integrity="sha384-abc"', $tags[0]);
        $this->assertStringContainsString('crossorigin="anonymous"', $tags[0]);
        $this->assertStringNotContainsString('integrity', $tags[1]);
    }

    public function testAssetHelperVersionsAnyPath(): void
    {
        $this->assertSame('https://example.com/assets/css/print.v1.css', $this->template()->asset('/assets/css/print.css'));
    }

    public function testAbsoluteUrlsPassThroughUntouched(): void
    {
        $template = $this->template();
        $template->css('https://cdn.example.com/lib.css');

        $this->assertSame('<link href="https://cdn.example.com/lib.css" rel="stylesheet">', $template->renderCss());
        $this->assertSame('//cdn.example.com/x.js', $template->url('//cdn.example.com/x.js'));
    }

    public function testWithoutWiringNothingChanges(): void
    {
        $template = new Template('default', [self::TEMPLATES], [self::LAYOUTS], request: new ServerRequest('GET', new Uri('https://example.com/')));
        $template->css('/assets/css/app.css');

        $this->assertSame('<link href="https://example.com/assets/css/app.css" rel="stylesheet">', $template->renderCss());
    }

    public function testASubclassRendersItsLayoutAsItself(): void
    {
        $template = new class('default', [self::TEMPLATES], [self::LAYOUTS]) extends Template {
            public static int $built = 0;

            public function render(array $data = []): string
            {
                ++self::$built;

                return parent::render($data);
            }
        };
        $template::$built = 0;
        $template->layout('default');

        $template->render();

        $this->assertSame(2, $template::$built, 'The page and its layout both went through the subclass');
    }
}
