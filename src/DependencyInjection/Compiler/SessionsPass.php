<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UserKitBundle\DependencyInjection\SessionsLoader;
use Nowo\UserKitBundle\Session\DefaultSessionInvalidator;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Nowo\UserKitBundle\Sessions\Controller\SessionsController;
use Nowo\UserKitBundle\Sessions\Epoch\EpochRememberMeHandler;
use Nowo\UserKitBundle\Sessions\Epoch\RememberMeEpoch;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\RegistrySessionInvalidator;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\PhpArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Cache\Adapter\TraceableTagAwareAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Http\RememberMe\PersistentRememberMeHandler;
use Symfony\Component\Security\Http\RememberMe\SignatureRememberMeHandler;

use function array_key_exists;
use function array_map;
use function array_unique;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * Compile-time guards and wiring of `nowo_user_kit.sessions`:
 *
 * - the registry needs SecurityBundle (token storage) and Doctrine;
 * - the `cache` revocation store refuses per-server pools (L3);
 * - the remember-me cookies of every firewall are handed to the registry listener (M2);
 * - with `sessions.remember_me_epoch.enabled`, the built-in remember-me handler of every firewall is wrapped by
 *   {@see EpochRememberMeHandler} (custom `remember_me.service` handlers are left alone);
 * - the registry backs {@see SessionInvalidatorInterface} unless the host aliased its own invalidator;
 * - the pages fail closed without form CSRF protection (REQ-SEC-005, pattern P2) or without Twig / FormKit, and refuse
 *   `security.allow_unauthenticated: true` outside `kernel.debug`.
 */
final class SessionsPass implements CompilerPassInterface
{
    /** Pool adapters that live on one server (or one process) only. */
    public const PER_SERVER_POOLS = [
        FilesystemAdapter::class,
        FilesystemTagAwareAdapter::class,
        ArrayAdapter::class,
        ApcuAdapter::class,
        PhpFilesAdapter::class,
        PhpArrayAdapter::class,
        NullAdapter::class,
    ];

    /** Pool decorators unwrapped by the shared-pool check (their first argument is the decorated pool, or the tiers). */
    public const POOL_WRAPPERS = [
        TagAwareAdapter::class,
        ProxyAdapter::class,
        TraceableAdapter::class,
        TraceableTagAwareAdapter::class,
        ChainAdapter::class,
    ];

    /**
     * Applies the guards when the feature is enabled.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(UserSessionManager::class)) {
            return;
        }
        if (!$container->has('security.token_storage') || !$container->has('doctrine')) {
            throw new LogicException('NowoUserKitBundle: nowo_user_kit.sessions.enabled requires SecurityBundle and DoctrineBundle.');
        }
        $this->checkCachePool($container);
        $this->collectRememberMeCookies($container);
        $this->wrapRememberMeHandlers($container);
        if ($container->hasAlias(SessionInvalidatorInterface::class) && (string) $container->getAlias(SessionInvalidatorInterface::class) === DefaultSessionInvalidator::class) {
            $container->setAlias(SessionInvalidatorInterface::class, RegistrySessionInvalidator::class);
        }

        if (!$container->hasDefinition(SessionsController::class)) {
            return;
        }
        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;
        if (!$container->has('security.csrf.token_manager') || !$container->hasDefinition('form.type_extension.csrf') || $formCsrfDisabled) {
            throw new LogicException('NowoUserKitBundle: the session pages (sessions.web_ui.enabled: true) require CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection, or set nowo_user_kit.sessions.web_ui.enabled: false.');
        }
        if (!$container->has('twig') || !$container->has(CsrfOnlyFormFactory::class)) {
            throw new LogicException('NowoUserKitBundle: the session pages (sessions.web_ui.enabled: true) require TwigBundle, NowoUiKitBundle and NowoFormKitBundle to be registered.');
        }
        if ($container->hasParameter(SessionsLoader::ALLOW_UNAUTHENTICATED_PARAMETER)
            && $container->getParameter(SessionsLoader::ALLOW_UNAUTHENTICATED_PARAMETER) === true
            && (!$container->hasParameter('kernel.debug') || $container->getParameter('kernel.debug') !== true)) {
            throw new InvalidConfigurationException('nowo_user_kit.sessions.security.allow_unauthenticated: true is only allowed when kernel.debug is true (dev / demo): anybody could sign other users out. Set it to false and use security.manage_roles.');
        }
    }

    /**
     * L3: the `cache` store needs a pool shared by every web server; per-server adapters are refused.
     */
    private function checkCachePool(ContainerBuilder $container): void
    {
        $pool = $container->hasParameter(SessionsLoader::CACHE_POOL_PARAMETER) ? $container->getParameter(SessionsLoader::CACHE_POOL_PARAMETER) : null;
        if (!is_string($pool)) {
            return;
        }
        // Known wrappers (tag-aware, proxy, traceable, chain) are unwrapped; the pool is refused when every tier is
        // a per-server adapter. An unknown tier (custom class, factory) counts as shared.
        $tiers = $this->tiers($container, new Reference($pool), 0);
        foreach ($tiers as $class) {
            if ($class === null || !$this->perServer($class)) {
                return;
            }
        }

        throw new InvalidConfigurationException(sprintf('nowo_user_kit.sessions.revocation.cache_pool "%s" only stores on one server (%s): a revoked session would live on the other servers (and a deploy clears it). Use a pool shared by every web server (Redis, Memcached, a PDO / Doctrine DBAL pool) or revocation.store: database.', $pool, implode(', ', array_unique(array_map(strval(...), $tiers)))));
    }

    /**
     * M2 / N1: the remember-me cookie of every firewall is removed from the request and cleared when a revoked session
     * is signed out. Built-in handlers: name, path, domain, secure, httponly and SameSite from their options. Custom
     * handlers (`remember_me.service`): the cookie name of the firewall's remember-me authenticator, and the handler's
     * own `clearRememberMeCookie()` (it knows its path and domain). An unresolvable cookie fails the compilation.
     */
    private function collectRememberMeCookies(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SessionRegistryListener::class)) {
            return;
        }
        $listener = $container->getDefinition(SessionRegistryListener::class);
        /** @var list<array{name: string, path: string|null, domain: string|null, secure: bool|string|null, httponly: bool, samesite: string|null}> $cookies */
        $cookies  = $listener->getArgument(10);
        $handlers = [];
        $seen     = [];
        foreach ($cookies as $cookie) {
            $seen[$cookie['name'] . '|' . $cookie['path'] . '|' . $cookie['domain']] = true;
        }
        foreach ($container->findTaggedServiceIds('security.remember_me_handler') as $id => $tags) {
            $arguments = $container->getDefinition($id)->getArguments();
            $options   = $arguments['index_3'] ?? $arguments[3] ?? null;
            if (is_array($options)) {
                $cookie = [
                    'name'     => is_string($options['name'] ?? null) && $options['name'] !== '' ? $options['name'] : 'REMEMBERME',
                    'path'     => is_string($options['path'] ?? null) && $options['path'] !== '' ? $options['path'] : '/',
                    'domain'   => is_string($options['domain'] ?? null) && $options['domain'] !== '' ? $options['domain'] : null,
                    'secure'   => is_bool($options['secure'] ?? null) || is_string($options['secure'] ?? null) ? $options['secure'] : null,
                    'httponly' => ($options['httponly'] ?? true) !== false,
                    'samesite' => is_string($options['samesite'] ?? null) ? $options['samesite'] : null,
                ];
            } else {
                $firewall = $tags[0]['firewall'] ?? null;
                $name     = is_string($firewall) ? $this->authenticatorCookie($container, $firewall) : null;
                if ($name === null) {
                    throw new LogicException(sprintf('NowoUserKitBundle: the remember-me cookie of the handler "%s" cannot be read from the security configuration, so a revoked session could log back in with it. List its name in nowo_user_kit.sessions.remember_me_cookies.', $id));
                }
                // The custom handler clears its own cookie (right path / domain); the name is stripped from the request.
                $handlers[] = new Reference($id);
                $cookie     = ['name' => $name, 'path' => null, 'domain' => null, 'secure' => 'auto', 'httponly' => true, 'samesite' => null];
            }
            $key = $cookie['name'] . '|' . $cookie['path'] . '|' . $cookie['domain'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $cookies[]  = $cookie;
            }
        }
        $listener->replaceArgument(10, $cookies);
        $listener->setArgument(12, $handlers);
    }

    /**
     * Remember-me epoch: wraps the built-in (signature / persistent) remember-me handler of every firewall and hands
     * the handlers and their cookie names to {@see RememberMeEpoch} (re-issue for the kept session).
     */
    private function wrapRememberMeHandlers(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RememberMeEpoch::class)) {
            return;
        }
        $handlers = [];
        $cookies  = [];
        foreach ($container->findTaggedServiceIds('security.remember_me_handler') as $id => $tags) {
            $firewall            = $tags[0]['firewall'] ?? null;
            [$class, $arguments] = $this->resolve($container, $container->getDefinition($id));
            $options             = $arguments[3] ?? null;
            $persistent          = is_string($class) && is_a($class, PersistentRememberMeHandler::class, true);
            if (!is_string($firewall) || !is_array($options) || (!$persistent && (!is_string($class) || !is_a($class, SignatureRememberMeHandler::class, true)))) {
                continue;
            }
            $decorator = 'nowo_user_kit.sessions.epoch_remember_me_handler.' . $firewall;
            $container->setDefinition($decorator, (new Definition(EpochRememberMeHandler::class, [
                new Reference($decorator . '.inner'),
                new Reference(RememberMeEpoch::class),
                new Reference(SessionUserResolver::class),
                is_int($options['lifetime'] ?? null) ? $options['lifetime'] : 31536000,
                $persistent ? ($arguments[0] ?? null) : null,
            ]))->setDecoratedService($id));
            $handlers[$firewall] = new Reference($id);
            $cookies[$firewall]  = is_string($options['name'] ?? null) && $options['name'] !== '' ? $options['name'] : 'REMEMBERME';
        }
        $container->getDefinition(RememberMeEpoch::class)
            ->replaceArgument(6, ServiceLocatorTagPass::register($container, $handlers))
            ->replaceArgument(7, $cookies);
    }

    /**
     * Cookie name the remember-me authenticator of a firewall reads (its argument 2).
     */
    private function authenticatorCookie(ContainerBuilder $container, string $firewall): ?string
    {
        $id = 'security.authenticator.remember_me.' . $firewall;
        if (!$container->hasDefinition($id)) {
            return null;
        }
        $arguments = $container->getDefinition($id)->getArguments();
        $name      = $arguments['index_2'] ?? $arguments[2] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function perServer(string $class): bool
    {
        foreach (self::PER_SERVER_POOLS as $refused) {
            if (is_a($class, $refused, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Leaf adapter classes of a pool (null for a tier whose class cannot be told).
     *
     * @return list<string|null>
     */
    private function tiers(ContainerBuilder $container, mixed $pool, int $depth): array
    {
        $definition = $pool instanceof Definition ? $pool : null;
        if (!$definition instanceof Definition && ($pool instanceof Reference || is_string($pool))) {
            $id = (string) $pool;
            for ($i = 0; $i < 32 && $container->hasAlias($id); ++$i) {
                $id = (string) $container->getAlias($id);
            }
            $definition = $container->hasDefinition($id) ? $container->getDefinition($id) : null;
        }
        if (!$definition instanceof Definition || $depth > 8) {
            return [null];
        }
        [$class, $arguments] = $this->resolve($container, $definition);
        if ($class === null || !in_array($class, self::POOL_WRAPPERS, true)) {
            return [$class];
        }
        $inner = $arguments[0] ?? null;
        $tiers = [];
        foreach (is_array($inner) ? $inner : [$inner] as $tier) {
            $tiers = [...$tiers, ...$this->tiers($container, $tier, $depth + 1)];
        }

        return $tiers;
    }

    /**
     * Class and positional arguments of a definition, through its ChildDefinition parents.
     *
     * @return array{0: string|null, 1: array<int, mixed>}
     */
    private function resolve(ContainerBuilder $container, Definition $definition): array
    {
        $class     = null;
        $arguments = [];
        for ($i = 0; $i < 32; ++$i) {
            $own = $definition->getClass();
            if ($class === null && is_string($own) && $own !== '' && !str_contains($own, '%')) {
                $class = $own;
            }
            foreach ($definition->getArguments() as $key => $value) {
                $index = is_int($key) ? $key : (str_starts_with($key, 'index_') ? (int) substr($key, 6) : null);
                if ($index !== null && !array_key_exists($index, $arguments)) {
                    $arguments[$index] = $value;
                }
            }
            if (!$definition instanceof ChildDefinition || !$container->hasDefinition($definition->getParent())) {
                break;
            }
            $definition = $container->getDefinition($definition->getParent());
        }

        return [$class, $arguments];
    }
}
