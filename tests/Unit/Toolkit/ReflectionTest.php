<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Reflection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

class InvokableFixture
{
    public function __invoke(string $name): string
    {
        return 'hello '.$name;
    }

    public function greet(string $name): string
    {
        return 'hi '.$name;
    }
}

#[CoversClass(Reflection::class)]
class ReflectionTest extends TestCase
{
    public function testCallableWithClosure(): void
    {
        $info = Reflection::callable(fn (string $a) => $a);

        $this->assertInstanceOf(\ReflectionFunction::class, $info);
        $this->assertSame('a', $info->getParameters()[0]->getName());
    }

    public function testCallableWithArrayCallable(): void
    {
        $info = Reflection::callable([new InvokableFixture(), 'greet']);

        $this->assertInstanceOf(\ReflectionMethod::class, $info);
        $this->assertSame('greet', $info->getName());
    }

    public function testCallableWithArrayCallableClassString(): void
    {
        $info = Reflection::callable([InvokableFixture::class, 'greet']);

        $this->assertInstanceOf(\ReflectionMethod::class, $info);
        $this->assertSame('greet', $info->getName());
    }

    public function testCallableWithMissingMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        Reflection::callable([InvokableFixture::class, 'missing']);
    }

    public function testCallableWithMissingMethodOnObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(InvokableFixture::class.'::missing does not exist');

        Reflection::callable([new InvokableFixture(), 'missing']);
    }

    public function testCallableWithInvokableObject(): void
    {
        $info = Reflection::callable(new InvokableFixture());

        $this->assertInstanceOf(\ReflectionMethod::class, $info);
        $this->assertSame('__invoke', $info->getName());
    }

    public function testCallableWithFunctionName(): void
    {
        $info = Reflection::callable('strtoupper');

        $this->assertInstanceOf(\ReflectionFunction::class, $info);
        $this->assertSame('strtoupper', $info->getName());
    }

    public function testCallableWithUnresolvable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Callable is not resolvable: integer');

        Reflection::callable(42);
    }

    public function testSortArguments(): void
    {
        $info = Reflection::callable(fn ($b, $a) => [$b, $a]);

        $args = Reflection::sortArguments($info, [
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ]);

        $this->assertSame(['b' => 2, 'a' => 1], $args);
    }

    public function testSortArgumentsWithMissingData(): void
    {
        $info = Reflection::callable(fn ($a, $b) => [$a, $b]);

        $this->assertSame(['a' => 1], Reflection::sortArguments($info, ['a' => 1]));
    }
}
