<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Config schema for the `firewalls` section of the security configuration.
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
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
