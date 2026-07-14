<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\UserKitBundle\EventListener\AccountDisabledListener;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use Nowo\UserKitBundle\Twig\UserPresenceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function sprintf;

final class NowoUserKitExtension extends Extension
{
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
        }

        if (!$config['twig'] || !class_exists(\Twig\Extension\AbstractExtension::class)) {
            $container->removeDefinition(UserPresenceExtension::class);
        }
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function resolveMissingUserClasses(array &$profiles, string $defaultProfileName, ContainerBuilder $container): void
    {
        if ('' !== ($profiles[$defaultProfileName]['user_class'] ?? '')) {
            return;
        }

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
