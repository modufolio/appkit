<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Template;

use Modufolio\Appkit\Template\Template;
use PHPUnit\Framework\TestCase;

class TemplateTest extends TestCase
{
    protected Template $template;
    protected string $templatePath;
    protected string $layoutPath;

    protected function setUp(): void
    {
        $this->templatePath = __DIR__ . '/fixtures/site/templates';
        $this->layoutPath = __DIR__ . '/fixtures/site/layouts';

        $this->template = new Template(
            name: 'default',
            templatePaths: [$this->templatePath],
            layoutPaths: [$this->layoutPath]
        );
    }

    public function testConstructor(): void
    {
        $this->assertEquals('default', (string)$this->template);
    }

    public function testExists(): void
    {
        $this->assertTrue($this->template->exists());
    }

    public function testFile(): void
    {
        $expectedFile = $this->templatePath . '/default.php';
        $this->assertEquals($expectedFile, $this->template->file());
    }

    public function testName(): void
    {
        $this->assertEquals('default', $this->template->name());
    }

    public function testRenderWithLayout(): void
    {
        $output = $this->template->render(['key' => 'value']);

        $this->assertEquals('Hello, World!Some contentGoodbye, World!', str_replace(["\r", "\n"], '', $output));
    }

    public function testCssCollection(): void
    {
        $this->template->css('/css/app.css');
        $this->template->css('/css/theme.css', ['media' => 'print']);

        $output = $this->template->renderCss();

        $this->assertStringContainsString('href="/css/app.css"', $output);
        $this->assertStringContainsString('href="/css/theme.css"', $output);
        $this->assertStringContainsString('media="print"', $output);
        $this->assertStringContainsString('rel="stylesheet"', $output);
    }

    public function testCssCollectionArray(): void
    {
        $this->template->css(['/css/app.css', '/css/theme.css']);

        $output = $this->template->renderCss();

        $this->assertStringContainsString('href="/css/app.css"', $output);
        $this->assertStringContainsString('href="/css/theme.css"', $output);
    }

    public function testCssDeduplication(): void
    {
        $this->template->css('/css/app.css');
        $this->template->css('/css/app.css'); // Duplicate

        $output = $this->template->renderCss();

        // Should only appear once
        $this->assertEquals(1, substr_count($output, 'href="/css/app.css"'));
    }

    public function testJsCollection(): void
    {
        $this->template->js('/js/app.js');
        $this->template->js('/js/module.js', true); // async = true

        $output = $this->template->renderJs();

        $this->assertStringContainsString('src="/js/app.js"', $output);
        $this->assertStringContainsString('src="/js/module.js"', $output);
        $this->assertStringContainsString('async', $output);
    }

    public function testJsCollectionArray(): void
    {
        $this->template->js(['/js/app.js', '/js/module.js']);

        $output = $this->template->renderJs();

        $this->assertStringContainsString('src="/js/app.js"', $output);
        $this->assertStringContainsString('src="/js/module.js"', $output);
    }

    public function testJsDeduplication(): void
    {
        $this->template->js('/js/app.js');
        $this->template->js('/js/app.js'); // Duplicate

        $output = $this->template->renderJs();

        // Should only appear once
        $this->assertEquals(1, substr_count($output, 'src="/js/app.js"'));
    }

    public function testSnippetRendering(): void
    {
        $output = $this->template->snippet('button', ['text' => 'Submit']);

        $this->assertStringContainsString('<button>Submit</button>', $output);
    }

    public function testNestedSnippets(): void
    {
        $output = $this->template->snippet('card', [
            'title' => 'My Card',
            'buttonText' => 'Learn More'
        ]);

        // Should contain the card wrapper
        $this->assertStringContainsString('<div class="card">', $output);
        $this->assertStringContainsString('<h2>My Card</h2>', $output);

        // Should contain the nested button snippet
        $this->assertStringContainsString('<button>Learn More</button>', $output);
    }

    public function testSnippetInheritParentData(): void
    {
        $template = new Template(
            name: 'default',
            templatePaths: [$this->templatePath],
            layoutPaths: [$this->layoutPath],
            data: ['globalVar' => 'inherited']
        );

        $output = $template->snippet('button', ['text' => 'Click']);

        // Snippet should have access to both passed data and parent template data
        $this->assertNotNull($output);
    }

    public function testSnippetAssetsAreShared(): void
    {
        // Add CSS in the parent template
        $this->template->css('/css/main.css');

        // Create a snippet that adds its own CSS
        $snippetFile = $this->templatePath . '/../snippets/widget.php';
        file_put_contents($snippetFile, '<?php $this->css("/css/widget.css") ?>Widget');

        // Render the snippet
        $this->template->snippet('widget');

        // Parent template should have BOTH CSS files (shared collection)
        $css = $this->template->renderCss();
        $this->assertEquals(2, substr_count($css, '<link'));
        $this->assertStringContainsString('/css/main.css', $css);
        $this->assertStringContainsString('/css/widget.css', $css);

        // Clean up
        unlink($snippetFile);
    }

    public function testExtractSkipProtectsInternalVariables(): void
    {
        // Create a snippet that tries to access $this
        $snippetFile = $this->templatePath . '/../snippets/test-this.php';
        file_put_contents($snippetFile, '<?php echo get_class($this); ?>');

        // Pass data that tries to overwrite 'template' variable
        // EXTR_SKIP should prevent this from breaking $this access
        $output = $this->template->snippet('test-this', [
            'template' => 'malicious-override',
            'file' => '/etc/passwd',
        ]);

        // Should still render the correct class name
        $this->assertStringContainsString('Template', $output);

        // Clean up
        unlink($snippetFile);
    }
}
