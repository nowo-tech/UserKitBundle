<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UserKitBundle\DependencyInjection\Compiler\SessionsPass;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Sessions\Client\SessionClientFactory;
use Nowo\UserKitBundle\Sessions\Command\PurgeSessionsCommand;
use Nowo\UserKitBundle\Sessions\Controller\SessionsController;
use Nowo\UserKitBundle\Sessions\Doctrine\TablePrefixListener;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\EventListener\PasswordChangeListener;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\Limit\ConfiguredSessionLimitResolver;
use Nowo\UserKitBundle\Sessions\Limit\SessionLimitResolverInterface;
use Nowo\UserKitBundle\Sessions\PersonalData\SessionsEraser;
use Nowo\UserKitBundle\Sessions\PersonalData\SessionsExportProvider;
use Nowo\UserKitBundle\Sessions\RegistrySessionInvalidator;
use Nowo\UserKitBundle\Sessions\Revocation\CacheRevocationStore;
use Nowo\UserKitBundle\Sessions\Revocation\DatabaseRevocationStore;
use Nowo\UserKitBundle\Sessions\Revocation\RevocationStoreInterface;
use Nowo\UserKitBundle\Sessions\Routing\SessionsRouteLoader;
use Nowo\UserKitBundle\Sessions\Security\AccessGuard;
use Nowo\UserKitBundle\Sessions\Security\AllowAllSessionsAccessChecker;
use Nowo\UserKitBundle\Sessions\Security\RoleSessionsAccessChecker;
use Nowo\UserKitBundle\Sessions\Security\SessionsAccessCheckerInterface;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use Nowo\UserKitBundle\Sessions\Twig\SessionsTwigExtension;
use Nowo\UserKitBundle\Sessions\Ui\SessionsPages;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function array_filter;
use function array_key_exists;
use function array_replace_recursive;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Registers the services of `nowo_user_kit.sessions` (only when `sessions.enabled` is true) and prepends the Doctrine
 * mapping of the registry entity and the FormKit profile of the pages.
 */
final class SessionsLoader
{
    /** FormKit profile of the session pages (`nowo_form_kit.profiles.user_kit`). */
    public const FORM_KIT_PROFILE = 'user_kit';

    /** Container parameter holding the pool of the `cache` revocation store (null with the database store). */
    public const CACHE_POOL_PARAMETER = 'nowo_user_kit.sessions.revocation.cache_pool';

    /** Container parameter holding `sessions.security.allow_unauthenticated` (checked by {@see SessionsPass}). */
    public const ALLOW_UNAUTHENTICATED_PARAMETER = 'nowo_user_kit.sessions.security.allow_unauthenticated';

    /**
     * Listener priorities: before the firewall (8), after it, after the credentials check, after the session id
     * migration and the remember-me cookie, before the session is invalidated on logout.
     */
    public const PRIORITY_EARLY = 9;

    public const PRIORITY_LATE = 6;

    public const PRIORITY_CHECK_PASSPORT = -128;

    public const PRIORITY_LOGIN_SUCCESS = -256;

    public const PRIORITY_LOGOUT = 64;

    /**
     * FormKit profile of the pages (CSRF-only actions; labels in the bundle domain).
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => self::FORM_KIT_PROFILE,
            'translation_domain' => NowoUserKitBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
    }

    /**
     * Prepends the Doctrine mapping (sessions enabled) and the FormKit profile (pages enabled) from the raw config.
     */
    public static function prepend(ContainerBuilder $container): void
    {
        $sessions = self::rawSessions($container);
        if (($sessions['enabled'] ?? false) !== true) {
            return;
        }

        if ($container->hasExtension('doctrine')) {
            $mapping = ['mappings' => ['NowoUserKitSessions' => [
                'type'      => 'attribute',
                'dir'       => __DIR__ . '/../Sessions/Entity',
                'prefix'    => 'Nowo\UserKitBundle\Sessions\Entity',
                'alias'     => 'NowoUserKitSessions',
                'is_bundle' => false,
            ]]];
            $manager = $sessions['doctrine']['entity_manager'] ?? null;
            $container->prependExtensionConfig('doctrine', ['orm' => is_string($manager) && $manager !== ''
                ? ['entity_managers' => [$manager => $mapping]]
                : $mapping]);
        }

        if (($sessions['web_ui']['enabled'] ?? false) === true && $container->hasExtension('nowo_form_kit')) {
            foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
                if (is_array($config['profiles'] ?? null) && array_key_exists(self::FORM_KIT_PROFILE, $config['profiles'])) {
                    return;
                }
            }
            $container->prependExtensionConfig('nowo_form_kit', ['profiles' => [self::FORM_KIT_PROFILE => self::formKitProfile()]]);
        }
    }

    /**
     * Registers the services of the feature.
     *
     * @param array<string, mixed> $sessions processed `sessions` node
     * @param array<string, array<string, mixed>> $profiles processed profiles
     */
    public static function load(ContainerBuilder $container, array $sessions, array $profiles, bool $personalData): void
    {
        self::assertLiteralBooleans($sessions);
        /** @var array{enabled: bool, hash_secret: string, idle_lifetime: int, touch_interval: int, remember_me_cookies: list<string>, flash: bool, revocation: array{store: string, cache_pool: string|null}, client: array{user_agent: string, ip: string, ipv4_prefix: int, ipv6_prefix: int, device_id_resolver: string|null}, limit: array{max_sessions: int|null, policy: string, resolver: string|null}, password_change: array{revoke_sessions: bool, field: string}, doctrine: array{table_prefix: string, entity_manager: string|null}, web_ui: array{enabled: bool, path_prefix: string, admin: bool, admin_path_prefix: string, layout_template: string|null, templates: array<string, string|null>}, security: array{access_roles: list<string>, manage_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}} $sessions */
        $webUi   = $sessions['web_ui'];
        $enabled = $sessions['enabled'];
        $container->setParameter('nowo_user_kit.sessions.enabled', $enabled);

        // Always registered (routing.loader): without the feature the loader mounts nothing.
        $container->setDefinition(SessionsRouteLoader::class, (new Definition(SessionsRouteLoader::class, [
            $enabled && $webUi['enabled'],
            $webUi['path_prefix'],
            $webUi['admin_path_prefix'],
            $webUi['admin'],
        ]))->addTag('routing.loader'));

        if (!$enabled) {
            return;
        }

        $doctrine = new Reference('doctrine');
        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$sessions['doctrine']['table_prefix']]))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));
        $container->setDefinition(UserSessionStore::class, (new Definition(UserSessionStore::class, [$doctrine, $sessions['doctrine']['entity_manager']]))->setPublic(true));
        $container->setDefinition(SessionUserResolver::class, new Definition(SessionUserResolver::class, [$doctrine]));

        $ttl       = $sessions['idle_lifetime'] + $sessions['touch_interval'];
        $cachePool = $sessions['revocation']['store'] === 'cache' ? (string) $sessions['revocation']['cache_pool'] : null;
        $container->setParameter(self::CACHE_POOL_PARAMETER, $cachePool);
        $container->setDefinition(RevocationStoreInterface::class, $cachePool !== null
            ? new Definition(CacheRevocationStore::class, [new Reference($cachePool), $ttl])
            : new Definition(DatabaseRevocationStore::class, [new Reference(UserSessionStore::class)]));

        $dispatcher = new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $container->setDefinition(UserSessionManager::class, (new Definition(UserSessionManager::class, [
            new Reference(UserSessionStore::class),
            new Reference(RevocationStoreInterface::class),
            new Reference(SessionUserResolver::class),
            new Reference(ClockInterface::class),
            $sessions['hash_secret'],
            $sessions['idle_lifetime'],
            $sessions['touch_interval'],
            $dispatcher,
        ]))->setPublic(true));

        $client = $sessions['client'];
        $container->setDefinition(SessionClientFactory::class, new Definition(SessionClientFactory::class, [
            $client['ip'],
            $client['ipv4_prefix'],
            $client['ipv6_prefix'],
            $client['user_agent'],
            $sessions['hash_secret'],
            is_string($client['device_id_resolver']) && $client['device_id_resolver'] !== '' ? new Reference($client['device_id_resolver']) : null,
        ]));

        $limit = $sessions['limit'];
        if (is_string($limit['resolver']) && $limit['resolver'] !== '') {
            $container->setAlias(SessionLimitResolverInterface::class, $limit['resolver']);
        } else {
            $container->setDefinition(ConfiguredSessionLimitResolver::class, new Definition(ConfiguredSessionLimitResolver::class, [$limit['max_sessions'], $limit['policy']]));
            $container->setAlias(SessionLimitResolverInterface::class, ConfiguredSessionLimitResolver::class);
        }

        $listener = new Definition(SessionRegistryListener::class, [
            new Reference(UserSessionManager::class),
            new Reference(UserSessionStore::class),
            new Reference(RevocationStoreInterface::class),
            new Reference(SessionClientFactory::class),
            new Reference(SessionLimitResolverInterface::class),
            new Reference('security.token_storage'),
            new Reference('security.firewall.map', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $dispatcher,
            self::extraCookies($sessions['remember_me_cookies']),
            $sessions['flash'],
            [],
        ]);
        $listener
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequestEarly', 'priority' => self::PRIORITY_EARLY])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => self::PRIORITY_LATE])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse'])
            ->addTag('kernel.event_listener', ['event' => CheckPassportEvent::class, 'method' => 'onCheckPassport', 'priority' => self::PRIORITY_CHECK_PASSPORT])
            ->addTag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'priority' => self::PRIORITY_LOGIN_SUCCESS])
            ->addTag('kernel.event_listener', ['event' => LogoutEvent::class, 'method' => 'onLogout', 'priority' => self::PRIORITY_LOGOUT]);
        $container->setDefinition(SessionRegistryListener::class, $listener);

        $container->setDefinition(RegistrySessionInvalidator::class, new Definition(RegistrySessionInvalidator::class, [new Reference(UserSessionManager::class)]));

        if ($sessions['password_change']['revoke_sessions']) {
            self::registerPasswordChangeListener($container, $sessions['password_change']['field'], $profiles);
        }

        $container->setDefinition(PurgeSessionsCommand::class, (new Definition(PurgeSessionsCommand::class, [
            new Reference(UserSessionStore::class),
            new Reference(UserSessionManager::class),
            $dispatcher,
        ]))->addTag('console.command', ['command' => PurgeSessionsCommand::NAME]));

        if ($personalData) {
            $container->setDefinition(SessionsExportProvider::class, (new Definition(SessionsExportProvider::class, [new Reference(UserSessionManager::class), new Reference(UserSessionStore::class)]))
                ->addTag(NowoUserKitExtension::TAG_EXPORT_PROVIDER, ['priority' => -140]));
            $container->setDefinition(SessionsEraser::class, (new Definition(SessionsEraser::class, [new Reference(UserSessionManager::class), new Reference(UserSessionStore::class)]))
                ->addTag(NowoUserKitExtension::TAG_ERASER, ['priority' => -130]));
        }

        if ($webUi['enabled']) {
            self::registerWebUi($container, $webUi, $sessions['security']);
        }
    }

    /**
     * @param array{enabled: bool, path_prefix: string, admin: bool, admin_path_prefix: string, layout_template: string|null, templates: array<string, string|null>} $webUi
     * @param array{access_roles: list<string>, manage_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool} $security
     */
    private static function registerWebUi(ContainerBuilder $container, array $webUi, array $security): void
    {
        $container->setParameter(self::ALLOW_UNAUTHENTICATED_PARAMETER, $security['allow_unauthenticated']);
        $customId = $security['access_checker'];
        if (is_string($customId) && $customId !== '') {
            $container->setAlias(SessionsAccessCheckerInterface::class, $customId);
        } elseif ($security['allow_unauthenticated']) {
            $container->setDefinition(AllowAllSessionsAccessChecker::class, new Definition(AllowAllSessionsAccessChecker::class));
            $container->setAlias(SessionsAccessCheckerInterface::class, AllowAllSessionsAccessChecker::class);
        } else {
            $container->setDefinition(RoleSessionsAccessChecker::class, new Definition(RoleSessionsAccessChecker::class, [
                new Reference('security.authorization_checker'),
                SessionsConfiguration::roles($security['access_roles']),
                SessionsConfiguration::roles($security['manage_roles']),
            ]));
            $container->setAlias(SessionsAccessCheckerInterface::class, RoleSessionsAccessChecker::class);
        }
        $container->setDefinition(AccessGuard::class, new Definition(AccessGuard::class, [
            new Reference(SessionsAccessCheckerInterface::class),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));

        $container->setDefinition(SessionsPages::class, new Definition(SessionsPages::class, [
            new Reference('router'),
            new Reference(CsrfOnlyFormFactory::class),
            $webUi['layout_template'],
            array_filter($webUi['templates'], static fn (?string $t): bool => is_string($t) && $t !== ''),
        ]));

        $container->setDefinition(SessionsTwigExtension::class, (new Definition(SessionsTwigExtension::class, [
            new Reference(UserSessionManager::class),
            new Reference(ProfileRegistry::class),
            new Reference('router'),
            new Reference(AccessGuard::class),
            $webUi['admin'],
        ]))->addTag('twig.extension'));

        // REQ-SF-004 rule 7: not autoconfigured (no `routing.controller` tag from #[Route]); the routes come only from
        // the `nowo_user_kit_sessions` loader. Autowired only for AbstractController::setContainer().
        $container->setDefinition(SessionsController::class, (new Definition(SessionsController::class, [
            '$sessions'   => new Reference(UserSessionManager::class),
            '$guard'      => new Reference(AccessGuard::class),
            '$pages'      => new Reference(SessionsPages::class),
            '$csrfForms'  => new Reference(CsrfOnlyFormFactory::class),
            '$profiles'   => new Reference(ProfileRegistry::class),
            '$doctrine'   => new Reference('doctrine'),
            '$translator' => new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber'));
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private static function registerPasswordChangeListener(ContainerBuilder $container, string $field, array $profiles): void
    {
        $definition = (new Definition(PasswordChangeListener::class, [
            new Reference(UserSessionManager::class),
            $field,
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->addTag('doctrine.event_listener', ['event' => 'postFlush'])
            ->addTag('kernel.reset', ['method' => 'reset']);
        foreach ($profiles as $profile) {
            $definition->addTag('doctrine.orm.entity_listener', [
                'event'  => 'preUpdate',
                'entity' => $profile['user_class'],
                'lazy'   => true,
                'method' => 'preUpdate',
            ]);
        }
        $container->setDefinition(PasswordChangeListener::class, $definition);
    }

    /**
     * `sessions.remember_me_cookies` as cookie specs (path `/`, host-only); SessionsPass adds the firewall ones.
     *
     * @param list<string> $names
     *
     * @return list<array{name: string, path: string, domain: string|null, secure: bool|string|null, httponly: bool, samesite: string|null}>
     */
    public static function extraCookies(array $names): array
    {
        $cookies = [];
        foreach ($names as $name) {
            $cookies[] = ['name' => $name, 'path' => '/', 'domain' => null, 'secure' => 'auto', 'httponly' => true, 'samesite' => 'lax'];
        }

        return $cookies;
    }

    /**
     * Raw `sessions` node of the host configuration (later files win).
     *
     * @return array<string, mixed>
     */
    private static function rawSessions(ContainerBuilder $container): array
    {
        $merged = [];
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            $sessions = is_array($config['sessions'] ?? null) ? $config['sessions'] : [];
            $merged   = array_replace_recursive($merged, $sessions);
        }

        return $merged;
    }

    /**
     * Feature switches must be literal booleans (an env placeholder would read as true at compile time).
     *
     * @param array<string, mixed> $sessions
     */
    private static function assertLiteralBooleans(array $sessions): void
    {
        /** @var array<string, array<string, mixed>> $sessions */
        $switches = [
            'enabled'                        => $sessions['enabled'] ?? null,
            'web_ui.enabled'                 => $sessions['web_ui']['enabled'] ?? null,
            'web_ui.admin'                   => $sessions['web_ui']['admin'] ?? null,
            'security.allow_unauthenticated' => $sessions['security']['allow_unauthenticated'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_user_kit.sessions.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for this switch.', $path));
            }
        }
    }
}
