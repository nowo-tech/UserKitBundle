<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection;

use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\EventListener\AccountDisabledListener;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoUserKitExtensionFeatureFlagsTest extends TestCase
{
    public function testAccountCheckerCanBeDisabled(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'user_class'     => 'App\\Entity\\User',
            'account_status' => ['enabled' => false],
        ]], $container);

        $this->assertFalse($container->hasDefinition(AccountStatusUserChecker::class));
    }

    public function testSessionInvalidationListenerRegistered(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'user_class'     => 'App\\Entity\\User',
            'account_status' => ['invalidate_sessions_on_disable' => true],
        ]], $container);

        $this->assertTrue($container->hasDefinition(AccountDisabledListener::class));
        $this->assertSame([[
            'event'  => 'postUpdate',
            'entity' => 'App\\Entity\\User',
            'lazy'   => true,
            'method' => 'postUpdate',
        ]], $container->getDefinition(AccountDisabledListener::class)->getTag('doctrine.orm.entity_listener'));
    }

    public function testSessionInvalidationListenerAttachedOnlyToOptedInProfiles(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'profiles' => [
                'customer' => ['user_class' => 'App\\Entity\\Customer', 'account_status' => ['invalidate_sessions_on_disable' => true]],
                'admin'    => ['user_class' => 'App\\Entity\\Admin'],
            ],
        ]], $container);

        $tags = $container->getDefinition(AccountDisabledListener::class)->getTag('doctrine.orm.entity_listener');
        $this->assertSame(['App\\Entity\\Customer'], array_column($tags, 'entity'));
    }

    public function testAccountCheckerHasNoGlobalUserCheckerTag(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([['user_class' => 'App\\Entity\\User']], $container);

        $this->assertSame([], $container->getDefinition(AccountStatusUserChecker::class)->getTags());
    }
}
