<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Resolver\DefaultValueResolver;
use PHPUnit\Framework\TestCase;

class DefaultValueController
{
    public function withDefault(int $page = 5): void
    {
    }

    public function nullableNoDefault(?string $filter): void
    {
    }

    public function requiredScalar(int $id): void
    {
    }

    public function variadic(string ...$tags): void
    {
    }

    public function mixed(string $name, ?string $sort, int $limit = 10): void
    {
    }
}

class DefaultValueResolverTest extends TestCase
{
    private function resolve(string $method, array $resolved): array
    {
        $reflection = new \ReflectionMethod(DefaultValueController::class, $method);

        return (new DefaultValueResolver())->getParameters($reflection, [], $resolved);
    }

    public function testFillsSignatureDefault(): void
    {
        $this->assertSame(['page' => 5], $this->resolve('withDefault', []));
    }

    public function testFillsNullForNullableWithoutDefault(): void
    {
        $this->assertSame(['filter' => null], $this->resolve('nullableNoDefault', []));
    }

    public function testLeavesRequiredParameterUnresolved(): void
    {
        $this->assertSame([], $this->resolve('requiredScalar', []));
    }

    public function testSkipsVariadic(): void
    {
        $this->assertSame([], $this->resolve('variadic', []));
    }

    public function testDoesNotOverrideAlreadyResolved(): void
    {
        $this->assertSame(['page' => 2], $this->resolve('withDefault', ['page' => 2]));
    }

    public function testOnlyFillsLeftovers(): void
    {
        // $name already resolved; $limit gets its default; $sort gets null.
        $result = $this->resolve('mixed', ['name' => 'Ada']);

        $this->assertSame(['name' => 'Ada', 'sort' => null, 'limit' => 10], $result);
    }
}
