<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\EventListener\AccountDisabledListener;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use Nowo\UserKitBundle\Twig\UserPresenceExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoUserKitExtensionTest extends TestCase
{
    public function testLoadRegistersServices(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'user_class'    => 'App\\Entity\\User',
            'last_activity' => ['enabled' => true],
        ]], $container);

        $this->assertSame('App\\Entity\\User', $container->getParameter('nowo_user_kit.user_class'));
        $this->assertSame('default', $container->getParameter('nowo_user_kit.default_profile'));
        $this->assertTrue($container->hasDefinition(AccountStatusUserChecker::class));
        $this->assertTrue($container->hasDefinition(LastActivitySubscriber::class));
        $this->assertTrue($container->hasDefinition(ProfileRegistry::class));
        $this->assertFalse($container->hasDefinition(AccountDisabledListener::class));
    }

    public function testUsesAuthKitUserClassBridge(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_auth_kit.user_class', 'App\\Entity\\AuthUser');

        (new NowoUserKitExtension())->load([[]], $container);

        $this->assertSame('App\\Entity\\AuthUser', $container->getParameter('nowo_user_kit.user_class'));
    }

    public function testMissingUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NowoUserKitExtension())->load([[]], new ContainerBuilder());
    }

    public function testTwigDisabledWhenRequested(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'user_class' => 'App\\Entity\\User',
            'twig'       => false,
        ]], $container);

        $this->assertFalse($container->hasDefinition(UserPresenceExtension::class));
    }

    public function testProfilesConfiguration(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'default_profile' => 'admin',
            'profiles'        => [
                'app_user' => [
                    'user_class'     => 'App\\Entity\\User',
                    'last_activity'  => ['enabled' => true],
                    'account_status' => ['enabled' => false],
                ],
                'admin' => [
                    'user_class'     => 'App\\Entity\\Admin',
                    'last_activity'  => ['enabled' => false],
                    'account_status' => ['enabled' => true],
                ],
            ],
        ]], $container);

        $this->assertSame('App\\Entity\\Admin', $container->getParameter('nowo_user_kit.user_class'));
        $this->assertTrue($container->hasDefinition(AccountStatusUserChecker::class));
        $this->assertTrue($container->hasDefinition(LastActivitySubscriber::class));
    }

    public function testDuplicateUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate user_class');

        (new NowoUserKitExtension())->load([[
            'profiles' => [
                'one' => ['user_class' => 'App\\Entity\\User'],
                'two' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]], new ContainerBuilder());
    }

    public function testMissingDefaultProfileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NowoUserKitExtension())->load([[
            'default_profile' => 'missing',
            'profiles'        => [
                'default' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]], new ContainerBuilder());
    }

    public function testMissingProfileUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NowoUserKitExtension())->load([[
            'profiles' => [
                'default' => ['user_class' => 'App\\Entity\\User'],
                'admin'   => ['user_class' => ''],
            ],
        ]], new ContainerBuilder());
    }
}
