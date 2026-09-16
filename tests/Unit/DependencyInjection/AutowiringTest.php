<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Clock\ClockInterface;

/**
 * Autowiring is a fallback the application switches on: an undeclared,
 * instantiable class is built from its constructor types, after every
 * declared source has had its say.
 */
class AutowiringTest extends AppTestCase
{
    public function tearDown(): void
    {
        $this->app()->configureAutowiring(false);
        parent::tearDown();
    }

    public function testOffByDefaultAnUndeclaredClassIsNotFound(): void
    {
        $this->assertFalse($this->app()->has(AutowiredPlain::class));

        $this->expectException(NotFoundException::class);
        $this->app()->get(AutowiredPlain::class);
    }

    public function testSwitchedOnAnUndeclaredClassIsBuiltFromItsConstructorTypes(): void
    {
        $this->app()->configureAutowiring();

        $this->assertTrue($this->app()->has(AutowiredReport::class));

        $report = $this->app()->get(AutowiredReport::class);

        $this->assertInstanceOf(AutowiredReport::class, $report);
        $this->assertInstanceOf(EntityManagerInterface::class, $report->entityManager);
        $this->assertInstanceOf(ClockInterface::class, $report->clock);
        $this->assertSame(10, $report->perPage, 'A parameter the container cannot answer takes its default.');
        $this->assertNull($report->label, 'A nullable one without a default is null.');
    }

    public function testADeclaredIdStillWinsOverAutowiring(): void
    {
        $this->app()->configureAutowiring();

        $configurator = new ServiceConfigurator();
        $configurator->set(AutowiredOverridden::class, fn ($app) => new AutowiredOverridden(99));
        $this->app()->configureServices($configurator);

        $this->assertSame(99, $this->app()->get(AutowiredOverridden::class)->perPage);
    }

    public function testAutowiredDependenciesAreResolvedThroughTheContainer(): void
    {
        $this->app()->configureAutowiring();

        $configurator = new ServiceConfigurator();
        $configurator->shared(AutowiredShared::class, fn () => new AutowiredShared(7));
        $this->app()->configureServices($configurator);

        $digest = $this->app()->get(AutowiredDigest::class);

        $this->assertSame(7, $digest->shared->perPage, 'The declared, shared service is what the digest receives.');
        $this->assertSame($this->app()->get(AutowiredShared::class), $digest->shared);
    }

    public function testARequiredParameterNothingAnswersIsNotFoundWithTheReason(): void
    {
        $this->app()->configureAutowiring();

        $this->assertFalse($this->app()->has(AutowiredNeedy::class));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('could not be autowired: its constructor parameter $port (int) is neither a service this container answers nor optional');

        $this->app()->get(AutowiredNeedy::class);
    }

    public function testAnInterfaceOrAbstractClassIsNotAutowired(): void
    {
        $this->app()->configureAutowiring();

        $this->assertFalse($this->app()->has(AutowiredContract::class));
        $this->assertFalse($this->app()->has(AutowiredBase::class));
    }

    public function testAutowiredServicesAreBuiltOnEveryGetLikeSet(): void
    {
        $this->app()->configureAutowiring();

        $this->assertNotSame($this->app()->get(AutowiredPlain::class), $this->app()->get(AutowiredPlain::class));
    }
}

final class AutowiredReport
{
    public function __construct(
        public readonly EntityManagerInterface $entityManager,
        public readonly ClockInterface $clock,
        public readonly int $perPage = 10,
        public readonly ?string $label = null,
    ) {
    }
}

final class AutowiredOverridden
{
    public function __construct(public readonly int $perPage = 10)
    {
    }
}

final class AutowiredShared
{
    public function __construct(public readonly int $perPage = 10)
    {
    }
}

final class AutowiredDigest
{
    public function __construct(public readonly AutowiredShared $shared)
    {
    }
}

final class AutowiredPlain
{
}

final class AutowiredNeedy
{
    public function __construct(public readonly int $port)
    {
    }
}

interface AutowiredContract
{
}

abstract class AutowiredBase
{
}
