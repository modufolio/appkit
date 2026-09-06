<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Query;

use Modufolio\Appkit\Query\Query;
use Modufolio\Appkit\Query\Segment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** An entity in the Doctrine style: private state, get/is/has accessors. */
final class Movie
{
    public function __construct(
        private readonly string $title,
        private readonly ?\DateTimeImmutable $releasedOn,
        private readonly bool $released,
        private readonly ?Studio $studio,
    ) {
    }

    public function getTitle(): string { return $this->title; }
    public function getReleasedOn(): ?\DateTimeImmutable { return $this->releasedOn; }
    public function isReleased(): bool { return $this->released; }
    public function hasStudio(): bool { return $this->studio !== null; }
    public function getStudio(): ?Studio { return $this->studio; }

    /** Named the Kirby way: the accessor fallback must never shadow it. */
    public function title(): string { return 'method wins'; }
}

final class Studio
{
    public function __construct(private readonly string $name) {}
    public function getName(): string { return $this->name; }
}

#[CoversClass(Segment::class)]
final class SegmentAccessorFallbackTest extends TestCase
{
    private function movie(): Movie
    {
        return new Movie('Heat', new \DateTimeImmutable('1995-12-15'), true, new Studio('Warner Bros.'));
    }

    public function testASegmentFallsBackToTheGetter(): void
    {
        self::assertSame('Warner Bros.', Query::factory('movie.studio.name')->resolve(['movie' => $this->movie()]));
    }

    public function testSnakeCaseReachesTheCamelCaseAccessor(): void
    {
        self::assertSame('1995-12-15', Query::factory('movie.released_on.format("Y-m-d")')->resolve(['movie' => $this->movie()]));
    }

    public function testIsAndHasAccessorsAreTried(): void
    {
        self::assertTrue(Query::factory('movie.released')->resolve(['movie' => $this->movie()]));
        self::assertTrue(Query::factory('movie.studio')->resolve(['movie' => new Movie('x', null, false, new Studio('s'))]) instanceof Studio);
        self::assertTrue(Query::factory('movie.hasStudio')->resolve(['movie' => $this->movie()]));
    }

    public function testAMethodOfTheSameNameIsNeverSecondGuessed(): void
    {
        self::assertSame('method wins', Query::factory('movie.title')->resolve(['movie' => $this->movie()]));
    }

    public function testAnUnknownSegmentStillErrors(): void
    {
        $this->expectException(\BadMethodCallException::class);

        Query::factory('movie.director')->resolve(['movie' => $this->movie()]);
    }
}
