<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection;

use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\Doctrine\UserKitExactKeyColumns;
use Nowo\UserKitBundle\EventListener\AccountDisabledListener;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use PHPUnit\Framework\TestCase;
use stdClass;
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

    /**
     * @param array<string, mixed> $config
     *
     * @return list<bool> [epoch columns active, owner image columns active] as declared to MigrationsKit
     */
    private function declaredActive(array $config): array
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([['user_class' => stdClass::class] + $config], $container);
        $arguments = $container->getDefinition(UserKitExactKeyColumns::class)->getArguments();

        return [$arguments[2], $arguments[3]];
    }

    public function testEpochColumnsDeclaredOnlyWhenBundleEpochTableIsMapped(): void
    {
        $sessions = static fn (?string $store): array => ['sessions' => ['enabled' => true, 'remember_me_epoch' => ['enabled' => true, 'store' => $store]]];

        $this->assertTrue($this->declaredActive($sessions(null))[0]);
        $this->assertTrue($this->declaredActive($sessions(''))[0]);
        $this->assertFalse($this->declaredActive($sessions('app.my_epoch_store'))[0]);
    }

    public function testOwnerImageColumnsFollowThePointerStoreRule(): void
    {
        $images = static fn (?string $store): array => ['owner_images' => ['enabled' => true, 'pointer_store' => $store]];

        $this->assertTrue($this->declaredActive($images(null))[1]);
        $this->assertTrue($this->declaredActive($images(''))[1]);
        $this->assertFalse($this->declaredActive($images('app.my_pointer_store'))[1]);
    }
}
