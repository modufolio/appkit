<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection\Symfony;

use Modufolio\Appkit\DependencyInjection\ContainerFactoryInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Choose the firewall's user provider from the `appkit.user_provider` tag,
 * so a module can own authentication without the application naming its
 * classes — the tag + collector-pass pattern, discovery by contract.
 *
 * Exactly one service may carry the tag. When one does, it replaces the
 * bridged UserProviderInterface (which would ask the kernel back) as the
 * alias inside the Symfony graph, and is published under
 * {@see ContainerFactoryInterface::USER_PROVIDER_ID} so the application's
 * userProvider() can hand it out with `$this->get('appkit.user_provider')`.
 * When none does, nothing changes: the application's own userProvider()
 * stays in charge on both sides.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class UserProviderPass implements CompilerPassInterface
{
    public const TAG = ContainerFactoryInterface::USER_PROVIDER_ID;

    public function process(ContainerBuilder $container): void
    {
        $tagged = array_keys($container->findTaggedServiceIds(self::TAG));

        if ([] === $tagged) {
            return;
        }

        if (\count($tagged) > 1) {
            throw new \LogicException(sprintf('Multiple services are tagged "%s" (%s); exactly one user provider is expected.', self::TAG, implode(', ', $tagged)));
        }

        $id = $tagged[0];

        // Public: the kernel fetches the provider by id, whatever the
        // application chose for everything else (see PublicServicesPass).
        $container->getDefinition($id)->setPublic(true);
        $container->setAlias(UserProviderInterface::class, $id)->setPublic(true);
        $container->setAlias(ContainerFactoryInterface::USER_PROVIDER_ID, $id)->setPublic(true);
    }
}
