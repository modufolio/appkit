<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Defs;

use Modufolio\Appkit\DependencyInjection\DefinitionsInterface;
use Psr\Container\ContainerInterface;

/** A package's wiring in the PSR-11 array shape: one factory, one alias. */
final class GreetingDefinitions implements DefinitionsInterface
{
    public function getDefinitions(): array
    {
        return [
            Greeter::class => static fn (ContainerInterface $c): Greeter => new Greeter('hello'),
            GreeterInterface::class => Greeter::class,
        ];
    }
}
