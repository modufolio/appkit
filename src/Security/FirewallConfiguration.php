<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Config schema for the `firewalls` section of the security configuration.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class FirewallConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('security');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->arrayNode('firewalls')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        // Keep app-specific keys (e.g. 'context') instead of
                        // erroring on them — false = do not strip them either.
                        ->ignoreExtraKeys(false)
                        ->children()
                            ->scalarNode('pattern')->cannotBeEmpty()->end()
                            ->arrayNode('authenticators')
                                ->scalarPrototype()->end()
                            ->end()
                            // Firewall restrictions (Symfony-style): a firewall
                            // handles the request only if all declared
                            // restrictions match.
                            ->arrayNode('methods')
                                ->scalarPrototype()->end()
                            ->end()
                            ->scalarNode('host')->end()
                            ->arrayNode('ips')
                                ->scalarPrototype()->end()
                            ->end()
                            ->scalarNode('entry_point')->end()
                            ->scalarNode('two_factor_path')->end()
                            ->scalarNode('csrf_token_id')->end()
                            ->booleanNode('stateless')->end()
                            ->booleanNode('security')->end()
                            ->booleanNode('csrf')->end()
                            ->arrayNode('csrf_delegated_paths')
                                ->scalarPrototype()->end()
                            ->end()
                            ->arrayNode('csrf_form_tokens')
                                ->useAttributeAsKey('name')
                                ->scalarPrototype()->end()
                            ->end()
                            ->variableNode('csrf_validator')
                                ->validate()
                                    ->ifTrue(static fn ($v): bool => null !== $v && !is_callable($v))
                                    ->thenInvalid('A firewall "csrf_validator" must be callable.')
                                ->end()
                            ->end()
                            ->arrayNode('logout')
                                ->ignoreExtraKeys(false)
                                ->children()
                                    ->scalarNode('path')->end()
                                    ->scalarNode('target')->end()
                                ->end()
                            ->end()
                            // User impersonation ("su"). Declaring the section
                            // is not enough — `enabled` must be true, matching
                            // Symfony's `switch_user` semantics.
                            ->arrayNode('switch_user')
                                ->canBeEnabled()
                                ->children()
                                    // Request parameter carrying the target
                                    // user identifier; the value `_exit`
                                    // (SWITCH_USER_EXIT) returns to the
                                    // impersonator's own account.
                                    ->scalarNode('parameter')
                                        ->cannotBeEmpty()
                                        ->defaultValue('_switch_user')
                                    ->end()
                                    // Role the *impersonator* must hold. Read
                                    // through the role hierarchy, so a role
                                    // that reaches it also grants the switch.
                                    ->scalarNode('role')
                                        ->cannotBeEmpty()
                                        ->defaultValue('ROLE_ALLOWED_TO_SWITCH')
                                    ->end()
                                    // Where to land after switching. Defaults
                                    // to the current URI with the parameter
                                    // stripped, like Symfony.
                                    ->scalarNode('target')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
