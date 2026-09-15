<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Resolver\AssociativeArrayResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssociativeArrayResolver::class)]
final class AssociativeArrayResolverTest extends TestCase
{
    /**
     * @param array<string, mixed> $provided
     *
     * @return array<string, mixed>
     */
    private function resolve(object $target, array $provided): array
    {
        return (new AssociativeArrayResolver())->getParameters(new \ReflectionMethod($target, 'method'), $provided, []);
    }

    public function testIntegerStringsAreCastAndOtherValuesPassThroughUnchanged(): void
    {
        $target = new class {
            public function method(int $id, float $ratio, bool $flag, string $slug): void
            {
            }
        };

        $resolved = $this->resolve($target, ['id' => '42', 'ratio' => '0.5', 'flag' => 'yes', 'slug' => 7]);

        $this->assertSame(['id' => 42, 'ratio' => 0.5, 'flag' => true, 'slug' => '7'], $resolved);
    }

    /**
     * `/posts/1.5` must not become post 1: a value that is not an int is
     * left alone for the call to reject.
     */
    public function testANonIntegerValueIsNotTruncatedToAnInt(): void
    {
        $target = new class {
            public function method(int $id): void
            {
            }
        };

        $this->assertSame(['id' => '1.5'], $this->resolve($target, ['id' => '1.5']));
        $this->assertSame(['id' => 'abc'], $this->resolve($target, ['id' => 'abc']));
    }

    /**
     * An object provided under the parameter's name is the argument itself,
     * not a constructor argument for a second instance.
     */
    public function testAnInstanceOfTheDeclaredClassIsHandedThrough(): void
    {
        $target = new class {
            public function method(\DateTimeImmutable $bag): void
            {
            }
        };
        $bag = new \DateTimeImmutable('2026-01-01');

        $resolved = $this->resolve($target, ['bag' => $bag]);

        $this->assertSame($bag, $resolved['bag']);
    }

    public function testBackedEnumsAreResolvedFromTheirValue(): void
    {
        $target = new class {
            public function method(TestSuit $suit): void
            {
            }
        };

        $this->assertSame(TestSuit::Hearts, $this->resolve($target, ['suit' => 'H'])['suit']);
    }
}

enum TestSuit: string
{
    case Hearts = 'H';
    case Spades = 'S';
}
