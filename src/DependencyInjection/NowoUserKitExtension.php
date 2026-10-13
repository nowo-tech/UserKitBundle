<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\UserKitBundle\EventListener\AccountDisabledListener;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use Nowo\UserKitBundle\Twig\UiPreferencesExtension;
use Nowo\UserKitBundle\Twig\UserPresenceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Twig\Extension\AbstractExtension;

use function is_array;
use function is_string;
use function sprintf;

final class NowoUserKitExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Build-time parameter (dot prefix: removed from the compiled container) holding the AuthKit
     * user class read from the raw `nowo_auth_kit` configuration in {@see prepend()}.
     *
     * prepend() runs for every extension before any load(), so this does not depend on whether
     * AuthKitBundle is registered before or after this bundle.
     */
    private const AUTH_KIT_USER_CLASS_PARAMETER = '.nowo_user_kit.auth_kit_user_class';

    /**
     * Captures the AuthKit user class from its raw configuration (independent of bundle order).
     */
    public function prepend(ContainerBuilder $container): void
    {
        $userClass = $this->findAuthKitUserClass($container);
        if ($userClass !== null) {
            $container->setParameter(self::AUTH_KIT_USER_CLASS_PARAMETER, $userClass);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $defaultProfileName = $config['default_profile'];
        if (!isset($config['profiles'][$defaultProfileName])) {
            throw new InvalidArgumentException(sprintf('The "nowo_user_kit.default_profile" value "%s" does not match any configured profile.', $defaultProfileName));
        }

        $profiles = $config['profiles'];
        $this->resolveMissingUserClasses($profiles, $defaultProfileName, $container);
        $this->assertUniqueUserClasses($profiles);

        $defaultProfile = $profiles[$defaultProfileName];

        $container->setParameter('nowo_user_kit.default_profile', $defaultProfileName);
        $container->setParameter('nowo_user_kit.profiles', $profiles);
        $container->setParameter('nowo_user_kit.user_class', $defaultProfile['user_class']);
        $container->setParameter('nowo_user_kit.account_status', $defaultProfile['account_status']);
        $container->setParameter('nowo_user_kit.account_status.field', $defaultProfile['account_status']['field']);
        $container->setParameter('nowo_user_kit.last_activity', $defaultProfile['last_activity']);
        $container->setParameter('nowo_user_kit.last_activity.field', $defaultProfile['last_activity']['field']);
        $container->setParameter('nowo_user_kit.last_activity.online_threshold', $defaultProfile['last_activity']['online_threshold']);
        $container->setParameter('nowo_user_kit.last_activity.update_throttle', $defaultProfile['last_activity']['update_throttle']);
        $container->setParameter('nowo_user_kit.twig', $config['twig']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $registry = new ProfileRegistry($profiles, $defaultProfileName);

        if (!$registry->hasAccountStatusEnabled()) {
            $container->removeDefinition(AccountStatusUserChecker::class);
        }

        if (!$registry->hasLastActivityEnabled()) {
            $container->removeDefinition(LastActivitySubscriber::class);
        }

        if (!$registry->hasSessionInvalidationEnabled()) {
            $container->removeDefinition(AccountDisabledListener::class);
        } else {
            $this->attachAccountDisabledListener($profiles, $container);
        }

        if (!$config['twig'] || !class_exists(AbstractExtension::class)) {
            $container->removeDefinition(UserPresenceExtension::class);
            $container->removeDefinition(UiPreferencesExtension::class);
        }
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Attaches the Doctrine entity listener to the user class of every profile that enables
     * `invalidate_sessions_on_disable` (an entity listener without `entity` is never attached).
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    private function attachAccountDisabledListener(array $profiles, ContainerBuilder $container): void
    {
        $definition = $container->getDefinition(AccountDisabledListener::class);

        foreach ($profiles as $profile) {
            if (true !== ($profile['account_status']['invalidate_sessions_on_disable'] ?? false)) {
                continue;
            }

            $definition->addTag('doctrine.orm.entity_listener', [
                'event'  => 'postUpdate',
                'entity' => $profile['user_class'],
                'lazy'   => true,
                'method' => 'postUpdate',
            ]);
        }
    }

    /**
     * Reads the default-profile user class from the raw (unprocessed) AuthKit configuration.
     * Later configuration files win, mirroring how Symfony merges them.
     */
    private function findAuthKitUserClass(ContainerBuilder $container): ?string
    {
        foreach (array_reverse($container->getExtensionConfig('nowo_auth_kit')) as $authConfig) {
            $candidate = $authConfig['user_class'] ?? null;
            if (!is_string($candidate) && is_array($authConfig['profiles'] ?? null) && $authConfig['profiles'] !== []) {
                $profileName = $authConfig['default_profile'] ?? array_key_first($authConfig['profiles']);
                $profile     = $authConfig['profiles'][$profileName] ?? null;
                $candidate   = is_array($profile) ? ($profile['user_class'] ?? null) : null;
            }

            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function resolveMissingUserClasses(array &$profiles, string $defaultProfileName, ContainerBuilder $container): void
    {
        if ('' !== ($profiles[$defaultProfileName]['user_class'] ?? '')) {
            return;
        }

        if ($container->hasParameter(self::AUTH_KIT_USER_CLASS_PARAMETER)) {
            $profiles[$defaultProfileName]['user_class'] = $container->getParameter(self::AUTH_KIT_USER_CLASS_PARAMETER);

            return;
        }

        // Legacy fallback: parameter set by AuthKit when it happened to be loaded first (or by the app).
        if ($container->hasParameter('nowo_auth_kit.user_class')) {
            $profiles[$defaultProfileName]['user_class'] = $container->getParameter('nowo_auth_kit.user_class');

            return;
        }

        throw new InvalidArgumentException(sprintf('The "nowo_user_kit.profiles.%s.user_class" configuration value is required.', $defaultProfileName));
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function assertUniqueUserClasses(array $profiles): void
    {
        $seen = [];

        foreach ($profiles as $name => $profile) {
            if ('' === ($profile['user_class'] ?? '')) {
                throw new InvalidArgumentException(sprintf('The "nowo_user_kit.profiles.%s.user_class" configuration value is required.', $name));
            }

            $userClass = $profile['user_class'];
            if (isset($seen[$userClass])) {
                throw new InvalidArgumentException(sprintf('Duplicate user_class "%s" in profiles "%s" and "%s".', $userClass, $seen[$userClass], $name));
            }

            $seen[$userClass] = $name;
        }
    }
}
