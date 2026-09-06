<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Keep services reachable by id after compilation.
 *
 * Symfony makes every service private unless told otherwise: a private
 * service can only be injected, never fetched, and one nothing injects is
 * removed at compile time. The kernel, though, *fetches* — `get()` on an id
 * it does not declare itself falls through to this container, and
 * getController() fetches controllers by class. So by default every
 * definition and alias is made public, and the Symfony graph behaves like
 * the kernel's own flat table: declared means reachable.
 *
 * With `$all = false` only controllers are made public — a definition tagged
 * `appkit.controller`, or a class whose name ends in "Controller", the same
 * suffix the attribute route loaders scan for — and everything else keeps
 * Symfony's private-by-default semantics, for an application that wants the
 * compiler's inlining and dead-service removal and fetches nothing else by id.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class PublicServicesPass implements CompilerPassInterface
{
    public const CONTROLLER_TAG = 'appkit.controller';

    public function __construct(private readonly bool $all = true)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isPublic() || $definition->isAbstract()) {
                continue;
            }

            if ($this->all || $this->isController($id, $definition)) {
                $definition->setPublic(true);
            }
        }

        foreach ($container->getAliases() as $id => $alias) {
            if ($alias->isPublic()) {
                continue;
            }

            $target = $container->hasDefinition((string) $alias) ? $container->getDefinition((string) $alias) : null;

            if ($this->all || $this->isController($id, $target)) {
                $alias->setPublic(true);
            }
        }
    }

    private function isController(string $id, ?Definition $definition): bool
    {
        if (null !== $definition && $definition->hasTag(self::CONTROLLER_TAG)) {
            return true;
        }

        $class = $definition?->getClass();

        return str_ends_with($id, 'Controller') || (null !== $class && str_ends_with($class, 'Controller'));
    }
}
