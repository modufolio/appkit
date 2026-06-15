<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Attributes\MapQueryParameter;
use Modufolio\Appkit\Resolver\MapQueryParameterResolver;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}

class MapQueryParameterController
{
    public function int(#[MapQueryParameter] int $page): void
    {
    }

    public function nullable(#[MapQueryParameter] ?int $page): void
    {
    }

    public function withDefault(#[MapQueryParameter] int $page = 7): void
    {
    }

    public function named(#[MapQueryParameter(name: 'q')] string $search): void
    {
    }

    public function bool(#[MapQueryParameter] bool $active): void
    {
    }

    public function enum(#[MapQueryParameter] SortDirection $sort): void
    {
    }

    public function array(#[MapQueryParameter] array $tags): void
    {
    }

    public function uuid(#[MapQueryParameter] Uuid $id): void
    {
    }

    public function nullableOnFailure(#[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?int $page): void
    {
    }
}

class MapQueryParameterResolverTest extends TestCase
{
    private function resolveArgument(string $method, array $query): mixed
    {
        $request = (new ServerRequest(method: 'GET', uri: new Uri('/?'.http_build_query($query))))
            ->withQueryParams($query);

        $resolver = new MapQueryParameterResolver($request);
        $parameter = (new \ReflectionMethod(MapQueryParameterController::class, $method))->getParameters()[0];

        return $resolver->resolve($parameter, []);
    }

    public function testCoercesIntFromString(): void
    {
        $this->assertSame(2, $this->resolveArgument('int', ['page' => '2']));
    }

    public function testMissingRequiredThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing query parameter "page".');
        $this->resolveArgument('int', []);
    }

    public function testMissingNullableReturnsNull(): void
    {
        $this->assertNull($this->resolveArgument('nullable', []));
    }

    public function testMissingUsesDefault(): void
    {
        $this->assertSame(7, $this->resolveArgument('withDefault', []));
    }

    public function testCustomName(): void
    {
        $this->assertSame('shoes', $this->resolveArgument('named', ['q' => 'shoes']));
    }

    public function testBool(): void
    {
        $this->assertTrue($this->resolveArgument('bool', ['active' => 'true']));
        $this->assertFalse($this->resolveArgument('bool', ['active' => '0']));
    }

    public function testBackedEnum(): void
    {
        $this->assertSame(SortDirection::Desc, $this->resolveArgument('enum', ['sort' => 'desc']));
    }

    public function testArray(): void
    {
        $this->assertSame(['a', 'b'], $this->resolveArgument('array', ['tags' => ['a', 'b']]));
    }

    public function testUuid(): void
    {
        $uuid = '0188b6a1-3c8e-7c2a-9b1a-0a1b2c3d4e5f';
        $result = $this->resolveArgument('uuid', ['id' => $uuid]);
        $this->assertInstanceOf(Uuid::class, $result);
        $this->assertSame($uuid, (string) $result);
    }

    public function testInvalidValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid query parameter "page".');
        $this->resolveArgument('int', ['page' => 'notanumber']);
    }

    public function testInvalidEnumThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolveArgument('enum', ['sort' => 'sideways']);
    }

    public function testNullOnFailureFlagReturnsNull(): void
    {
        $this->assertNull($this->resolveArgument('nullableOnFailure', ['page' => 'notanumber']));
    }
}
