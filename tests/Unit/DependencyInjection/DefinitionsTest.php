<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Modufolio\Appkit\DependencyInjection\DefinitionsInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Tests\App\Module\Defs\Greeter;
use Modufolio\Appkit\Tests\App\Module\Defs\GreeterInterface;
use Modufolio\Appkit\Tests\App\Module\Defs\GreetingDefinitions;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Container\ContainerInterface;

/**
 * A definition set — the array a PSR-11 container consumes — loads into the
 * kernel through the configurator: closures become factories that receive
 * the application, strings become aliases.
 */
class DefinitionsTest extends AppTestCase
{
    public function testAnArrayOfFactoriesAndAliasesLoads(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator->load([
            Greeter::class => static fn (ContainerInterface $c): Greeter => new Greeter('hi'),
            GreeterInterface::class => Greeter::class,
        ]);
        $this->app()->configureServices($configurator);

        $greeter = $this->app()->get(GreeterInterface::class);

        $this->assertInstanceOf(Greeter::class, $greeter);
        $this->assertSame('hi', $greeter->word);
    }

    public function testADefinitionsObjectLoadsTheSameWay(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator->load(new GreetingDefinitions());
        $this->app()->configureServices($configurator);

        $this->assertSame('hello', $this->app()->get(Greeter::class)->word);
        $this->assertInstanceOf(Greeter::class, $this->app()->get(GreeterInterface::class));
    }

    public function testTheFactoryReceivesTheApplicationAsTheContainer(): void
    {
        $seen = null;
        $configurator = new ServiceConfigurator();
        $configurator->load([
            \ArrayObject::class => static function (ContainerInterface $c) use (&$seen): \ArrayObject {
                $seen = $c;

                return new \ArrayObject();
            },
        ]);
        $this->app()->configureServices($configurator);
        $this->app()->get(\ArrayObject::class);

        $this->assertSame($this->app(), $seen);
    }

    public function testSharedLoadsCacheWithinARequest(): void
    {
        $configurator = new ServiceConfigurator();
        $configurator->load([\ArrayObject::class => static fn (): \ArrayObject => new \ArrayObject()], shared: true);
        $this->app()->configureServices($configurator);

        $this->assertSame($this->app()->get(\ArrayObject::class), $this->app()->get(\ArrayObject::class));
    }

    public function testAValueThatIsNeitherAClosureNorAnIdIsRefused(): void
    {
        $definitions = new class implements DefinitionsInterface {
            public function getDefinitions(): array
            {
                /** @phpstan-ignore return.type */
                return [\ArrayObject::class => 42];
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"ArrayObject" must be a factory closure or the id it aliases, int given');

        (new ServiceConfigurator())->load($definitions);
    }

    public function testAnUnkeyedDefinitionIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('keyed by a service id, found key 0');

        /** @phpstan-ignore argument.type */
        (new ServiceConfigurator())->load([static fn (): \ArrayObject => new \ArrayObject()]);
    }
}
