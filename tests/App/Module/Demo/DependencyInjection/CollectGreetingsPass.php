<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Demo\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The module-owned half of a cross-boundary compile: the module declares the
 * tag and the pass; the application and other modules only tag services.
 */
final class CollectGreetingsPass implements CompilerPassInterface
{
    public const TAG = 'demo.greeting';

    public function process(ContainerBuilder $container): void
    {
        $ids = array_keys($container->findTaggedServiceIds(self::TAG));
        sort($ids);

        $container->getDefinition(GreetingRegistry::class)
            ->setArguments(array_map(static fn (string $id): Reference => new Reference($id), $ids));
    }
}
