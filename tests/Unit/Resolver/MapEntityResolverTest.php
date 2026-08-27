<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Modufolio\Appkit\Attributes\MapEntity;
use Modufolio\Appkit\Resolver\MapEntityResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * The criteria the resolver builds decide which record an action receives, and
 * a wrong one fails as a 404 rather than as an error — so what goes into
 * findOneBy() is asserted directly.
 */
#[CoversClass(MapEntityResolver::class)]
class MapEntityResolverTest extends TestCase
{
    /** @var array<string, mixed>|null The criteria the last query was built from. */
    private ?array $capturedCriteria = null;

    private MapEntityResolver $resolver;

    /** Null stands for "no row matched". */
    private ?object $found;

    protected function setUp(): void
    {
        $this->capturedCriteria = null;
        $this->found = new MapEntityResolverTestEntity();

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?object {
                $this->capturedCriteria = $criteria;

                return $this->found;
            }
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $this->resolver = new MapEntityResolver($entityManager);
    }

    /**
     * @param array<string, mixed> $providedParameters
     */
    private function resolve(object $controller, array $providedParameters, string $method = 'action'): ?object
    {
        $parameter = (new \ReflectionMethod($controller, $method))->getParameters()[0];

        return $this->resolver->resolve($parameter, $providedParameters);
    }

    public function testSupportsOnlyParametersCarryingTheAttribute(): void
    {
        $controller = new class {
            public function mapped(#[MapEntity(mapping: ['uuid' => 'uuid'])] MapEntityResolverTestEntity $entity): void
            {
            }

            public function plain(MapEntityResolverTestEntity $entity): void
            {
            }
        };

        $reflection = new \ReflectionClass($controller);

        self::assertTrue($this->resolver->supports($reflection->getMethod('mapped')->getParameters()[0]));
        self::assertFalse($this->resolver->supports($reflection->getMethod('plain')->getParameters()[0]));
    }

    public function testMappingTranslatesRouteParametersIntoFields(): void
    {
        $controller = new class {
            public function action(#[MapEntity(mapping: ['contactUuid' => 'uuid'])] MapEntityResolverTestEntity $entity): void
            {
            }
        };

        $this->resolve($controller, ['contactUuid' => 'abc-123']);

        self::assertSame(['uuid' => 'abc-123'], $this->capturedCriteria);
    }

    /**
     * The regression this test exists for: on `/parent/{uuid}/child/{id}` the
     * route's id belongs to the child, so folding it into a mapped parent's
     * criteria looks for a parent that does not exist.
     */
    public function testRouteIdIsNotFoldedIntoAMappedArgument(): void
    {
        $controller = new class {
            public function action(#[MapEntity(mapping: ['uuid' => 'uuid'])] MapEntityResolverTestEntity $entity): void
            {
            }
        };

        $this->resolve($controller, ['uuid' => 'parent-uuid', 'id' => '42']);

        self::assertSame(
            ['uuid' => 'parent-uuid'],
            $this->capturedCriteria,
            "The route's id belongs to another argument and must not constrain this one.",
        );
    }

    public function testRouteIdIsUsedWhenNoMappingWasDeclared(): void
    {
        $controller = new class {
            public function action(#[MapEntity] MapEntityResolverTestEntity $entity): void
            {
            }
        };

        $this->resolve($controller, ['id' => '42']);

        self::assertSame(['id' => '42'], $this->capturedCriteria);
    }

    /**
     * `criteria` holds fixed extra constraints rather than identifying a
     * record, so it must still combine with the route's id.
     */
    public function testFixedCriteriaStillCombineWithTheRouteId(): void
    {
        $controller = new class {
            public function action(
                #[MapEntity(criteria: ['status' => 'published'])] MapEntityResolverTestEntity $entity,
            ): void {
            }
        };

        $this->resolve($controller, ['id' => '7']);

        self::assertSame(['status' => 'published', 'id' => '7'], $this->capturedCriteria);
    }

    public function testAMappedIdWins(): void
    {
        $controller = new class {
            public function action(#[MapEntity(mapping: ['parentId' => 'id'])] MapEntityResolverTestEntity $entity): void
            {
            }
        };

        $this->resolve($controller, ['parentId' => '9', 'id' => '42']);

        self::assertSame(['id' => '9'], $this->capturedCriteria);
    }

    public function testExcludeDropsCriteriaBeforeQuerying(): void
    {
        $controller = new class {
            public function action(
                #[MapEntity(mapping: ['uuid' => 'uuid', 'slug' => 'slug'], exclude: ['slug'])] MapEntityResolverTestEntity $entity,
            ): void {
            }
        };

        $this->resolve($controller, ['uuid' => 'abc', 'slug' => 'ignored']);

        self::assertSame(['uuid' => 'abc'], $this->capturedCriteria);
    }

    public function testStripNullRemovesNullCriteria(): void
    {
        $controller = new class {
            public function action(
                #[MapEntity(mapping: ['uuid' => 'uuid', 'slug' => 'slug'], stripNull: true)] MapEntityResolverTestEntity $entity,
            ): void {
            }
        };

        $this->resolve($controller, ['uuid' => 'abc', 'slug' => null]);

        self::assertSame(['uuid' => 'abc'], $this->capturedCriteria);
    }

    public function testUnmatchedRouteParametersAreSkipped(): void
    {
        $controller = new class {
            public function action(
                #[MapEntity(mapping: ['uuid' => 'uuid', 'absent' => 'other'])] MapEntityResolverTestEntity $entity,
            ): void {
            }
        };

        $this->resolve($controller, ['uuid' => 'abc']);

        self::assertSame(['uuid' => 'abc'], $this->capturedCriteria);
    }

    public function testEmptyCriteriaIsARoutingMistakeNotA404(): void
    {
        $controller = new class {
            public function action(#[MapEntity] MapEntityResolverTestEntity $entity): void
            {
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no id or criteria available/');

        $this->resolve($controller, ['uuid' => 'abc']);
    }

    public function testMissingEntityThrowsNotFoundForANonNullableParameter(): void
    {
        $this->found = null;

        $controller = new class {
            public function action(#[MapEntity(mapping: ['uuid' => 'uuid'])] MapEntityResolverTestEntity $entity): void
            {
            }
        };

        $this->expectException(ResourceNotFoundException::class);

        $this->resolve($controller, ['uuid' => 'nope']);
    }

    public function testMissingEntityIsNullForANullableParameter(): void
    {
        $this->found = null;

        $controller = new class {
            public function action(#[MapEntity(mapping: ['uuid' => 'uuid'])] ?MapEntityResolverTestEntity $entity): void
            {
            }
        };

        self::assertNull($this->resolve($controller, ['uuid' => 'nope']));
    }
}

/** Stands in for an entity; the repository is mocked, so it needs no mapping. */
class MapEntityResolverTestEntity
{
}
