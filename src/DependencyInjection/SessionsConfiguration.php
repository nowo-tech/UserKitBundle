<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use Nowo\UserKitBundle\Sessions\Client\SessionClientFactory;
use Nowo\UserKitBundle\Sessions\Model\SessionLimitPolicy;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

use function array_map;
use function array_values;
use function is_array;
use function is_string;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function trim;

/**
 * `nowo_user_kit.sessions`: the opt-in session registry (list, revoke, concurrent-session limits). Safe defaults:
 * off, IP truncated, browser / OS names only, database revocation check, pages off, `ROLE_USER` / `ROLE_ADMIN`.
 */
final class SessionsConfiguration
{
    public const DEFAULT_PATH_PREFIX = '/account/sessions';

    public const DEFAULT_ADMIN_PATH_PREFIX = '/admin/user-sessions';

    /**
     * Normalised role list (trimmed, empty entries dropped).
     *
     * @return list<string>
     */
    public static function roles(mixed $roles): array
    {
        $list = [];
        foreach (is_array($roles) ? array_values($roles) : [] as $role) {
            if (is_string($role) && trim($role) !== '') {
                $list[] = trim($role);
            }
        }

        return $list;
    }

    /**
     * The `sessions` node.
     */
    public function node(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('sessions'))->getRootNode();
        $node
            ->info('Session registry: one row per signed-in session (list, revoke one / others / all, concurrent-session limit). Off by default.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Register the registry, its request / login / logout listeners and the purge command (needs the user_kit_session table).')
                ->end()
                ->scalarNode('hash_secret')
                    ->defaultValue('%kernel.secret%')
                    ->cannotBeEmpty()
                    ->info('HMAC key of the stored session id hashes (and of the "hash" IP mode). After a change, each row is re-keyed on the next request of its session.')
                ->end()
                ->integerNode('idle_lifetime')
                    ->defaultValue(86400)
                    ->min(60)
                    ->info('Seconds after which an idle session is expired (keep it >= framework.session.gc_maxlifetime). Expired sessions are signed out and purged.')
                ->end()
                ->integerNode('touch_interval')
                    ->defaultValue(300)
                    ->min(1)
                    ->info('Write last_seen_at at most once per this many seconds per session.')
                ->end()
                ->arrayNode('remember_me_cookies')
                    ->info('Extra cookies removed and cleared (path /) when a revoked session is signed out. The remember-me cookie of every firewall is read from the security configuration (name, path, domain, secure, SameSite).')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('flash')
                    ->defaultTrue()
                    ->info('Add a "You were signed out because…" warning flash to the browser of a revoked session.')
                ->end()
                ->arrayNode('remember_me_epoch')
                    ->info('Per-user remember-me epoch: revokeAll / revokeOthers refuse every remember-me cookie issued before them (built-in signature and persistent handlers). Off by default.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Bump the epoch on revokeAll / revokeOthers and check it on remember-me sign-in (needs the user_kit_session_epoch table, or store).')
                        ->end()
                        ->scalarNode('store')
                            ->defaultNull()
                            ->info('Service id implementing Sessions\\Epoch\\SessionEpochStoreInterface (null = the bundle table user_kit_session_epoch).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('early_check')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('json_response')
                            ->defaultFalse()
                            ->info('XHR / Accept: application/json requests of a revoked, missing or idle session get 401 {"error":"session_signed_out"} from the early check instead of going on as a guest.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('purge')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('revoked_retention')
                            ->defaultNull()
                            ->min(0)
                            ->info('Seconds a revoked row is kept by sessions:purge (null = like the other rows: idle_lifetime + touch_interval after its last activity).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('owner')
                    ->info('Who a session belongs to (registry rows, remember-me epoch, revocations).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('resolver')
                            ->defaultNull()
                            ->info('Service id implementing Sessions\\Owner\\SessionOwnerResolverInterface (null = Doctrine root class + id, else class + user identifier). The security user and the entity the Doctrine listeners see must resolve to the same owner.')
                        ->end()
                        ->enumNode('on_unresolved')
                            ->values(['log', 'exception'])
                            ->defaultValue('log')
                            ->info('A revocation (revoke*, forgetUser, password_change, user_removal, account disabled) for a user without owner: log a warning, or throw UnresolvedSessionOwnerException.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('user_removal')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('delete_sessions')
                            ->defaultFalse()
                            ->info('Delete the registry rows (and remember-me epoch) of a profile user removed through Doctrine (the table has no foreign key).')
                        ->end()
                    ->end()
                ->end()
                ->append($this->revocationNode())
                ->append($this->clientNode())
                ->append($this->limitNode())
                ->append($this->passwordChangeNode())
                ->append($this->doctrineNode())
                ->append($this->webUiNode())
                ->append($this->securityNode())
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['touch_interval'] >= $v['idle_lifetime'])
                ->thenInvalid('nowo_user_kit.sessions.touch_interval must be lower than nowo_user_kit.sessions.idle_lifetime.')
            ->end();

        return $node;
    }

    private function revocationNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('revocation'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('store')
                    ->values(['database', 'cache'])
                    ->defaultValue('database')
                    ->info('database: one indexed query per signed-in request, works everywhere. cache: no query; needs a pool shared by every web server (cache_pool); touch_interval is then the worst-case revocation delay.')
                ->end()
                ->scalarNode('cache_pool')
                    ->defaultNull()
                    ->info('PSR-6 pool service id of the "cache" store, shared by every web server (e.g. a Redis or Memcached pool). Required with store: cache; per-server pools (filesystem, array, APCu, PHP files) are refused.')
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['store'] === 'cache' && (!is_string($v['cache_pool']) || trim($v['cache_pool']) === ''))
                ->thenInvalid('nowo_user_kit.sessions.revocation.cache_pool is required with revocation.store: cache (the service id of a pool shared by every web server, e.g. a Redis pool).')
            ->end();

        return $node;
    }

    private function clientNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('client'))->getRootNode();
        $node
            ->info('What is kept about the client (data minimisation).')
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('user_agent')
                    ->values(SessionClientFactory::USER_AGENT_MODES)
                    ->defaultValue('label')
                    ->info('label: browser and OS names only (no versions, no raw user agent). none: nothing.')
                ->end()
                ->enumNode('ip')
                    ->values(SessionClientFactory::IP_MODES)
                    ->defaultValue('truncate')
                    ->info('truncate: the network (ipv4_prefix / ipv6_prefix). hash: keyed HMAC of that network (not shown). none: nothing.')
                ->end()
                ->enumNode('ipv4_prefix')
                    ->values([8, 16, 24])
                    ->defaultValue(24)
                ->end()
                ->enumNode('ipv6_prefix')
                    ->values([8, 16, 24, 32, 40, 48, 56, 64])
                    ->defaultValue(48)
                ->end()
                ->scalarNode('device_id_resolver')
                    ->defaultNull()
                    ->info('Service id implementing Sessions\\Client\\DeviceIdProviderInterface (or the deprecated DeviceIdResolverInterface): optional device id per session.')
                ->end()
                ->arrayNode('context_attributes')
                    ->info('Request attributes (exact names) a DeviceIdProviderInterface receives with their raw value, objects included, through DeviceContext::attribute() (e.g. "_device" from DeviceIntelligence). Default: none. Name only what your provider needs. Since 1.16.0.')
                    ->scalarPrototype()
                        ->cannotBeEmpty()
                        ->validate()
                            ->ifTrue(static fn (mixed $value): bool => !is_string($value))
                            ->thenInvalid('nowo_user_kit.sessions.client.context_attributes entries must be attribute names (strings), got %s.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();

        return $node;
    }

    private function limitNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('limit'))->getRootNode();
        $node
            ->info('Concurrent-session limit applied at sign-in.')
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('max_sessions')
                    ->defaultNull()
                    ->min(1)
                    ->info('Live sessions a user may hold at once (null = unlimited).')
                ->end()
                ->enumNode('policy')
                    ->values(array_map(static fn (SessionLimitPolicy $p): string => $p->value, SessionLimitPolicy::cases()))
                    ->defaultValue(SessionLimitPolicy::EvictOldest->value)
                    ->info('evict_oldest: sign the least recently active sessions out. reject_new: refuse the sign-in.')
                ->end()
                ->integerNode('max_trusted_devices')
                    ->defaultNull()
                    ->min(0)
                    ->info('Trusted devices a user may keep (null = unlimited). Not enforced by the bundle: carried in SessionLimit for the host that keeps trusted devices.')
                ->end()
                ->scalarNode('resolver')
                    ->defaultNull()
                    ->info('Service id implementing Sessions\\Limit\\SessionLimitResolverInterface (per-user limits); replaces max_sessions / policy / max_trusted_devices.')
                ->end()
            ->end();

        return $node;
    }

    private function passwordChangeNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('password_change'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('revoke_sessions')
                    ->defaultTrue()
                    ->info('Sign out the other sessions of a profile user whose password field changes (Doctrine preUpdate).')
                ->end()
                ->scalarNode('field')
                    ->defaultValue('password')
                    ->cannotBeEmpty()
                    ->info('Mapped password field of the profile user classes.')
                ->end()
            ->end();

        return $node;
    }

    private function doctrineNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('doctrine'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('table_prefix')
                    ->defaultValue('')
                    ->info('Prefix of the bundle table and index names (e.g. "app_" → app_user_kit_session).')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z0-9_]{0,32}$/', $v) !== 1)
                        ->thenInvalid('nowo_user_kit.sessions.doctrine.table_prefix must match [A-Za-z0-9_]{0,32}, got %s.')
                    ->end()
                ->end()
                ->scalarNode('entity_manager')
                    ->defaultNull()
                    ->info('Entity manager that maps the registry entity (null = the default one).')
                ->end()
            ->end();

        return $node;
    }

    private function webUiNode(): ArrayNodeDefinition
    {
        $path = static fn (mixed $v): bool => !is_string($v) || trim($v, '/ ') === '' || !str_starts_with($v, '/');
        $node = (new TreeBuilder('web_ui'))->getRootNode();
        $node
            ->info('Session pages (UiKit list scaffold, FormKit CSRF-only actions). Mount them with the nowo_user_kit_sessions route loader.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Register the "My sessions" and administration pages (needs Twig, UiKit, FormKit and form CSRF).')
                ->end()
                ->scalarNode('path_prefix')
                    ->defaultValue(self::DEFAULT_PATH_PREFIX)
                    ->info('URL prefix of "My sessions" (a signed-in area of the firewall).')
                    ->validate()->ifTrue($path)->thenInvalid('nowo_user_kit.sessions.web_ui.path_prefix must start with "/" and not be "/" (got %s).')->end()
                ->end()
                ->booleanNode('admin')
                    ->defaultTrue()
                    ->info('Register the administration page of the sessions of a user.')
                ->end()
                ->scalarNode('admin_path_prefix')
                    ->defaultValue(self::DEFAULT_ADMIN_PATH_PREFIX)
                    ->info('URL prefix of the administration page; protect it with security.access_control in the host.')
                    ->validate()->ifTrue($path)->thenInvalid('nowo_user_kit.sessions.web_ui.admin_path_prefix must start with "/" and not be "/" (got %s).')->end()
                ->end()
                ->scalarNode('layout_template')
                    ->defaultNull()
                    ->info('Layout the pages extend (null = nowo_ui_kit.layouts.admin).')
                ->end()
                ->arrayNode('templates')
                    ->info('Per-page template overrides: index, admin.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('index')->defaultNull()->end()
                        ->scalarNode('admin')->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['admin'] && rtrim((string) $v['path_prefix'], '/') === rtrim((string) $v['admin_path_prefix'], '/'))
                ->thenInvalid('nowo_user_kit.sessions.web_ui.path_prefix and admin_path_prefix must differ.')
            ->end();

        return $node;
    }

    private function securityNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('security'))->getRootNode();
        $node
            ->info('REQ-UI-002 access to the session pages.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('access_roles')
                    ->info('Roles that open "My sessions" (any of them). Must not be empty unless access_checker is set or allow_unauthenticated is true.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['ROLE_USER'])
                ->end()
                ->arrayNode('manage_roles')
                    ->info('Roles that open the administration page of the sessions of any user (any of them). Same non-empty rule.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['ROLE_ADMIN'])
                ->end()
                ->scalarNode('access_checker')
                    ->defaultNull()
                    ->info('Service id implementing Sessions\\Security\\SessionsAccessCheckerInterface (replaces the role checks).')
                ->end()
                ->booleanNode('allow_unauthenticated')
                    ->defaultFalse()
                    ->info('DEV / DEMO only (refused unless kernel.debug): skip the role checks.')
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static function (array $v): bool {
                    if ($v['allow_unauthenticated'] === true || (is_string($v['access_checker']) && $v['access_checker'] !== '')) {
                        return false;
                    }

                    return self::roles($v['access_roles']) === [] || self::roles($v['manage_roles']) === [];
                })
                ->thenInvalid('nowo_user_kit.sessions.security.access_roles and manage_roles must each contain at least one non-empty role when no access_checker is set and allow_unauthenticated is false (an empty list would deny everybody).')
            ->end();

        return $node;
    }
}
