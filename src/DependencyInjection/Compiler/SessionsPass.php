<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UserKitBundle\DependencyInjection\SessionsLoader;
use Nowo\UserKitBundle\Session\DefaultSessionInvalidator;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Nowo\UserKitBundle\Sessions\Controller\SessionsController;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\RegistrySessionInvalidator;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\PhpArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function is_a;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * Compile-time guards and wiring of `nowo_user_kit.sessions`:
 *
 * - the registry needs SecurityBundle (token storage) and Doctrine;
 * - the `cache` revocation store refuses per-server pools (L3);
 * - the remember-me cookies of every firewall are handed to the registry listener (M2);
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
        $class = $this->classOf($container, $pool);
        foreach (self::PER_SERVER_POOLS as $refused) {
            if ($class !== null && is_a($class, $refused, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_user_kit.sessions.revocation.cache_pool "%s" is a %s: a per-server pool would let a revoked session live on the other servers (and a deploy clears it). Use a pool shared by every web server (Redis, Memcached, a PDO / Doctrine DBAL pool) or revocation.store: database.', $pool, $class));
            }
        }
    }

    /**
     * M2: the remember-me cookie of every firewall (name, path, domain, secure, httponly, SameSite from its
     * `remember_me` configuration) is removed and cleared when a revoked session is signed out.
     */
    private function collectRememberMeCookies(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SessionRegistryListener::class)) {
            return;
        }
        $listener = $container->getDefinition(SessionRegistryListener::class);
        /** @var list<array{name: string, path: string, domain: string|null, secure: bool|string|null, httponly: bool, samesite: string|null}> $cookies */
        $cookies = $listener->getArgument(10);
        $seen    = [];
        foreach ($cookies as $cookie) {
            $seen[$cookie['name'] . '|' . $cookie['path'] . '|' . $cookie['domain']] = true;
        }
        foreach (array_keys($container->findTaggedServiceIds('security.remember_me_handler')) as $id) {
            $definition = $container->getDefinition($id);
            $arguments  = $definition->getArguments();
            $options    = $arguments['index_3'] ?? $arguments[3] ?? null;
            if (!is_array($options)) {
                continue;
            }
            $cookie = [
                'name'     => is_string($options['name'] ?? null) && $options['name'] !== '' ? $options['name'] : 'REMEMBERME',
                'path'     => is_string($options['path'] ?? null) && $options['path'] !== '' ? $options['path'] : '/',
                'domain'   => is_string($options['domain'] ?? null) && $options['domain'] !== '' ? $options['domain'] : null,
                'secure'   => is_bool($options['secure'] ?? null) || is_string($options['secure'] ?? null) ? $options['secure'] : null,
                'httponly' => ($options['httponly'] ?? true) !== false,
                'samesite' => is_string($options['samesite'] ?? null) ? $options['samesite'] : null,
            ];
            $key = $cookie['name'] . '|' . $cookie['path'] . '|' . $cookie['domain'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $cookies[]  = $cookie;
            }
        }
        $listener->replaceArgument(10, $cookies);
    }

    private function classOf(ContainerBuilder $container, string $id): ?string
    {
        for ($i = 0; $i < 32 && $container->hasAlias($id); ++$i) {
            $id = (string) $container->getAlias($id);
        }
        for ($i = 0; $i < 32 && $container->hasDefinition($id); ++$i) {
            $definition = $container->getDefinition($id);
            $class      = $definition->getClass();
            if (is_string($class) && $class !== '' && !str_contains($class, '%')) {
                return $class;
            }
            if (!$definition instanceof ChildDefinition) {
                return null;
            }
            $id = $definition->getParent();
        }

        return null;
    }
}
