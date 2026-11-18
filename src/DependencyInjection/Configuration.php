<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree of `nowo_user_kit` (profiles, Twig, personal data and session registry options).
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_user_kit';

    /**
     * Builds the `nowo_user_kit` configuration tree.
     */
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
                    ->info('Register the user_is_online and user_ui_html_attributes Twig helpers when Twig is installed.')
                ->end()
                ->append($this->createConflictResponseNode())
                ->append($this->createPersonalDataNode())
                ->append((new SessionsConfiguration())->node())
                ->append((new OwnerImagesConfiguration())->node())
            ->end();

        return $treeBuilder;
    }

    /**
     * 1.21.0 (REQ-RUNTIME-003 rule 6): how a UserKit lock conflict (UserKitStorageFailure) is answered.
     */
    private function createConflictResponseNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('conflict_response'))->getRootNode();
        $node
            ->info('Answer to a UserKit lock conflict (deadlock, lock wait timeout, serialization failure, a lock UserKit waits for): 409 + Retry-After + X-Conflict-Code: CONFLICT_001, or CONFLICT_002 without Retry-After when part of the request was committed.')
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('status')
                    ->values([null, 409, 503])
                    ->defaultNull()
                    ->info('409 or 503 (the 1.20 status). Null: nowo_transaction_doctrine.conflict_response.status when NowoTransactionDoctrineBundle is registered, else 409.')
                ->end()
                ->integerNode('retry_after')
                    ->min(0)
                    ->defaultNull()
                    ->info('Retry-After seconds of a CONFLICT_001 answer. Null: nowo_transaction_doctrine.conflict_response.retry_after when NowoTransactionDoctrineBundle is registered, else 1.')
                ->end()
                ->enumNode('platform_answer')
                    ->values([null, true, false])
                    ->defaultNull()
                    ->info('true: answer with the platform conflict answer of nowo-tech/transaction-doctrine (JSON {"error", "code", "message"} or the "try again" page); false: your application\'s error rendering (with X-Conflict-Code). Null (auto): the platform answer only when nowo_transaction_doctrine.conflict_response.map_transient_failures is true.')
                ->end()
            ->end();

        return $node;
    }

    private function createPersonalDataNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('personal_data'))->getRootNode();
        $node
            ->info('GDPR self-service SPI: PersonalDataExporter / PersonalDataEraser and their tagged providers, erasers and guards.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Register the exporter, the eraser and autoconfigure the provider / eraser / guard interfaces.')
                ->end()
                ->scalarNode('schema')
                    ->defaultValue('nowo-user-kit/personal-data')
                    ->cannotBeEmpty()
                    ->info('Value of the "schema" key of the export document.')
                ->end()
                ->integerNode('version')
                    ->defaultValue(1)
                    ->min(1)
                    ->info('Value of the "version" key of the export document (bump it when the document shape changes).')
                ->end()
                ->booleanNode('builtin_providers')
                    ->defaultTrue()
                    ->info('Register the account_status, last_activity, ui_preferences and product_tour export sections.')
                ->end()
                ->booleanNode('builtin_erasers')
                    ->defaultTrue()
                    ->info('Register the last_activity, ui_preferences and product_tour erasers.')
                ->end()
            ->end();

        return $node;
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
