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
    }
}
