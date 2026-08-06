<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Controller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

class ControllerBindTarget
{
    public string $name = 'bound';

    public function closure(): \Closure
    {
        return function (string $suffix): string {
            return $this->name.$suffix;
        };
    }
}

#[CoversClass(Controller::class)]
class ControllerTest extends TestCase
{
    protected string $tmp;

    public function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-controller-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    public function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    public function testArguments(): void
    {
        $controller = new Controller(fn ($a, $b) => $a.$b);

        $arguments = $controller->arguments([
            'a' => 'foo',
            'b' => 'bar',
            'c' => 'ignored',
        ]);

        $this->assertSame(['a' => 'foo', 'b' => 'bar'], $arguments);
    }

    public function testArgumentsMissingWithoutDefault(): void
    {
        $controller = new Controller(fn ($a, $b) => [$a, $b]);

        $this->assertSame(['a' => null, 'b' => null], $controller->arguments());
    }

    public function testArgumentsMissingWithDefault(): void
    {
        $controller = new Controller(fn ($a, $b = 'default') => [$a, $b]);

        // arguments with defaults are left out so the default applies
        $this->assertSame(['a' => null], $controller->arguments());
    }

    public function testArgumentsVariadic(): void
    {
        $controller = new Controller(fn (...$args) => $args);

        $arguments = $controller->arguments(['a' => 'foo', 'b' => 'bar']);

        $this->assertSame(['a' => 'foo', 'b' => 'bar'], $arguments);
    }

    public function testCall(): void
    {
        $controller = new Controller(fn ($a, $b) => $a.$b);

        $this->assertSame('foobar', $controller->call(null, ['a' => 'foo', 'b' => 'bar']));
    }

    public function testCallWithBind(): void
    {
        $bind = new ControllerBindTarget();

        $controller = new Controller($bind->closure());

        $this->assertSame('bound!', $controller->call($bind, ['suffix' => '!']));
    }

    public function testLoad(): void
    {
        $file = $this->tmp.'/controller.php';
        file_put_contents($file, '<?php return fn ($a) => strtoupper($a);');

        $controller = Controller::load($file);

        $this->assertInstanceOf(Controller::class, $controller);
        $this->assertSame('FOO', $controller->call(null, ['a' => 'foo']));
    }

    public function testLoadMissingFile(): void
    {
        $this->assertNull(Controller::load($this->tmp.'/does-not-exist.php'));
    }

    public function testLoadNonClosure(): void
    {
        $file = $this->tmp.'/invalid.php';
        file_put_contents($file, '<?php return "not a closure";');

        $this->assertNull(Controller::load($file));
    }

    public function testLoadInsideRoot(): void
    {
        $file = $this->tmp.'/inside.php';
        file_put_contents($file, '<?php return fn () => "ok";');

        $controller = Controller::load($file, $this->tmp);

        $this->assertInstanceOf(Controller::class, $controller);
        $this->assertSame('ok', $controller->call());
    }

    public function testLoadOutsideRoot(): void
    {
        $file = $this->tmp.'/outside.php';
        file_put_contents($file, '<?php return fn () => "ok";');

        $this->assertNull(Controller::load($file, $this->tmp.'/subdir-that-does-not-contain-file'));
    }

    public function testLoadWithInvalidRoot(): void
    {
        $file = $this->tmp.'/inside.php';
        file_put_contents($file, '<?php return fn () => "ok";');

        $this->assertNull(Controller::load($file, $this->tmp.'/missing-root'));
    }
}
