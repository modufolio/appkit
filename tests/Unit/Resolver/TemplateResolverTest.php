<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Attributes\Template as TemplateAttribute;
use Modufolio\Appkit\Resolver\TemplateResolver;
use Modufolio\Appkit\Template\Template;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

class TemplateController
{
    public function plain(#[TemplateAttribute('home')] Template $template): void
    {
    }

    public function withLayout(#[TemplateAttribute('home', layout: 'admin')] Template $template): void
    {
    }

    public function untagged(Template $template): void
    {
    }
}

#[CoversClass(TemplateResolver::class)]
class TemplateResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/appkit-template-resolver-'.uniqid();
        mkdir($this->dir.'/layouts', 0777, true);
        file_put_contents($this->dir.'/home.php', '<p>Hello <?= $name ?> at <?= $this->url() ?></p>');
        file_put_contents($this->dir.'/layouts/admin.php', '<main><?= $this->content ?? $this->slot ?? "" ?></main>');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/layouts/*') ?: []);
        array_map('unlink', glob($this->dir.'/*.php') ?: []);
        rmdir($this->dir.'/layouts');
        rmdir($this->dir);
    }

    private function resolver(): TemplateResolver
    {
        return new TemplateResolver(
            [$this->dir],
            [$this->dir.'/layouts'],
            new ServerRequest(method: 'GET', uri: new Uri('https://example.com/dashboard')),
        );
    }

    private function parameter(string $method): \ReflectionParameter
    {
        return (new \ReflectionMethod(TemplateController::class, $method))->getParameters()[0];
    }

    public function testSupportsOnlyParametersCarryingTheAttribute(): void
    {
        $this->assertTrue($this->resolver()->supports($this->parameter('plain')));
        $this->assertFalse($this->resolver()->supports($this->parameter('untagged')));
    }

    public function testResolvesATemplateWithPathsAndRequestWiredIn(): void
    {
        $template = $this->resolver()->resolve($this->parameter('plain'), []);

        $this->assertSame('home', (string) $template);
        // The paths found the view and the request drove url().
        $this->assertSame('<p>Hello Ada at https://example.com</p>', $template->render(['name' => 'Ada']));
    }

    public function testAppliesTheLayoutNamedOnTheAttribute(): void
    {
        $template = $this->resolver()->resolve($this->parameter('withLayout'), []);

        $layout = new \ReflectionProperty(Template::class, 'layout');
        $this->assertSame('admin', $layout->getValue($template));
    }
}
