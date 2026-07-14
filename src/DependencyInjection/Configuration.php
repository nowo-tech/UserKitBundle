<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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
            ->beforeNormalization()
                ->always()
                ->then(static function (?array $config): array {
                    $config ??= [];

                    if (!isset($config['profiles'])) {
                        $config['profiles'] = [
                            'default' => [
                                'user_class'     => $config['user_class'] ?? null,
                                'account_status' => $config['account_status'] ?? [],
                                'last_activity'  => $config['last_activity'] ?? [],
                            ],
                        ];
                        unset($config['user_class'], $config['account_status'], $config['last_activity']);
                    }

                    if (!isset($config['default_profile'])) {
                        $profileNames              = array_keys($config['profiles']);
                        $config['default_profile'] = $profileNames[0] ?? 'default';
                    }

                    return $config;
                })
            ->end()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('default_profile')
                    ->info('Profile name used when no profile is specified explicitly.')
                    ->defaultValue('default')
                ->end()
                ->arrayNode('profiles')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('user_class')
                                ->info('FQCN of the application user entity for this profile.')
                                ->defaultNull()
                                ->example('App\\Entity\\User')
                            ->end()
                            ->append($this->createAccountStatusNode())
                            ->append($this->createLastActivityNode())
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

    private function createAccountStatusNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('account_status'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Register AccountStatusUserChecker for this profile when true.')
                ->end()
                ->scalarNode('field')
                    ->defaultValue('enabled')
                    ->cannotBeEmpty()
                ->end()
                ->booleanNode('invalidate_sessions_on_disable')
                    ->defaultFalse()
                ->end()
            ->end();

        return $node;
    }

    private function createLastActivityNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('last_activity'))->getRootNode();
        $node
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
            ->end();

        return $node;
    }
}
