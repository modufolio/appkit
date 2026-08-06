<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Template;

use Modufolio\Appkit\Template\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(View::class)]
class ViewTest extends TestCase
{
    protected string $tmp;

    public function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-view-'.uniqid();
        mkdir($this->tmp.'/partials', 0o777, true);

        file_put_contents($this->tmp.'/hello.php', 'Hello <?= $who ?>!');
        file_put_contents($this->tmp.'/partials/header.php', '<header><?= $title ?></header>');
    }

    public function tearDown(): void
    {
        unlink($this->tmp.'/hello.php');
        unlink($this->tmp.'/partials/header.php');
        rmdir($this->tmp.'/partials');
        rmdir($this->tmp);
    }

    public function testRender(): void
    {
        $view = new View([$this->tmp]);

        $this->assertSame('Hello World!', $view->render('hello', ['who' => 'World']));
    }

    public function testRenderWithSharedData(): void
    {
        $view = new View([$this->tmp], ['who' => 'Shared']);

        $this->assertSame('Hello Shared!', $view->render('hello'));
    }

    public function testRenderDataOverridesSharedData(): void
    {
        $view = new View([$this->tmp], ['who' => 'Shared']);

        $this->assertSame('Hello Local!', $view->render('hello', ['who' => 'Local']));
    }

    public function testRenderSubdirectory(): void
    {
        $view = new View([$this->tmp]);

        $this->assertSame('<header>Home</header>', $view->render('partials/header', ['title' => 'Home']));
    }

    public function testAddViewPath(): void
    {
        $view = new View();
        $result = $view->addViewPath($this->tmp.'/');

        $this->assertSame($view, $result);
        $this->assertSame('Hello World!', $view->render('hello', ['who' => 'World']));
    }

    public function testMissingViewThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('View file not found: missing');

        (new View([$this->tmp]))->render('missing');
    }

    public function testTraversalIsRejected(): void
    {
        file_put_contents(sys_get_temp_dir().'/appkit-view-outside.php', 'outside');

        try {
            $this->expectException(\RuntimeException::class);
            (new View([$this->tmp]))->render('../appkit-view-outside');
        } finally {
            unlink(sys_get_temp_dir().'/appkit-view-outside.php');
        }
    }

    public function testAbsolutePathIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        (new View([$this->tmp]))->render('/etc/hosts');
    }

    public function testBackslashIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        (new View([$this->tmp]))->render('partials\\header');
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        (new View([$this->tmp]))->render('');
    }

    public function testNullByteIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        (new View([$this->tmp]))->render("hello\0");
    }

    public function testInvalidViewPathIsSkipped(): void
    {
        $view = new View(['/does/not/exist', $this->tmp]);

        $this->assertSame('Hello World!', $view->render('hello', ['who' => 'World']));
    }
}
