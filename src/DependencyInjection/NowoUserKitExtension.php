<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\UserKitBundle\EventListener\AccountDisabledListener;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use Nowo\UserKitBundle\Twig\UserPresenceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class NowoUserKitExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        if ('' === ($config['user_class'] ?? '')) {
            if ($container->hasParameter('nowo_auth_kit.user_class')) {
                $config['user_class'] = $container->getParameter('nowo_auth_kit.user_class');
            } else {
                throw new InvalidArgumentException('The "nowo_user_kit.user_class" configuration value is required.');
            }
        }

        $container->setParameter('nowo_user_kit.user_class', $config['user_class']);
        $container->setParameter('nowo_user_kit.account_status', $config['account_status']);
        $container->setParameter('nowo_user_kit.account_status.field', $config['account_status']['field']);
        $container->setParameter('nowo_user_kit.last_activity', $config['last_activity']);
        $container->setParameter('nowo_user_kit.last_activity.field', $config['last_activity']['field']);
        $container->setParameter('nowo_user_kit.last_activity.online_threshold', $config['last_activity']['online_threshold']);
        $container->setParameter('nowo_user_kit.last_activity.update_throttle', $config['last_activity']['update_throttle']);
        $container->setParameter('nowo_user_kit.twig', $config['twig']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if (!$config['account_status']['enabled']) {
            $container->removeDefinition(AccountStatusUserChecker::class);
        }

        if (!$config['last_activity']['enabled']) {
            $container->removeDefinition(LastActivitySubscriber::class);
        }

        if (!$config['account_status']['invalidate_sessions_on_disable']) {
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
}
