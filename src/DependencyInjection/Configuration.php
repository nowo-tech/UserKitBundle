<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_user_kit';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('user_class')
                    ->info('FQCN of the application user entity.')
                    ->defaultNull()
                    ->example('App\\Entity\\User')
                ->end()
                ->arrayNode('account_status')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Register AccountStatusUserChecker when true.')
                        ->end()
                        ->scalarNode('field')
                            ->defaultValue('enabled')
                            ->cannotBeEmpty()
                        ->end()
                        ->booleanNode('invalidate_sessions_on_disable')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('last_activity')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('field')
                            ->defaultValue('lastActivityAt')
                            ->cannotBeEmpty()
                        ->end()
                        ->integerNode('online_threshold')
                            ->defaultValue(300)
                            ->min(1)
                        ->end()
                        ->integerNode('update_throttle')
                            ->defaultValue(30)
                            ->min(0)
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('twig')
                    ->defaultTrue()
                    ->info('Register user_is_online Twig helper when Twig is installed.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
