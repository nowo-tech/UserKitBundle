<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Profile;

use Nowo\UserKitBundle\Profile\UnknownProfileException;
use Nowo\UserKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

final class ProfileRegistryTest extends TestCase
{
    public function testResolveUsesExactClassMap(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'app_user' => [
                'user_class'     => ExactMatchUser::class,
                'account_status' => ['enabled' => true, 'field' => 'enabled', 'invalidate_sessions_on_disable' => false],
                'last_activity'  => ['enabled' => true, 'field' => 'lastActivityAt', 'online_threshold' => 300, 'update_throttle' => 0],
            ],
        ], 'app_user');

        $profile = $registry->resolveForObject(new ExactMatchUser());
        $this->assertNotNull($profile);
        $this->assertSame('app_user', $profile->name);
    }

    public function testResolveCachesParentClassMatch(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'app_user' => [
                'user_class'     => BaseProfileUser::class,
                'account_status' => ['enabled' => true, 'field' => 'enabled', 'invalidate_sessions_on_disable' => false],
                'last_activity'  => ['enabled' => true, 'field' => 'lastActivityAt', 'online_threshold' => 300, 'update_throttle' => 0],
            ],
        ]);

        $child    = new ChildProfileUser();
        $resolved = $registry->resolveForObject($child);
        $this->assertNotNull($resolved);
        $this->assertSame('app_user', $resolved->name);
        $this->assertSame('app_user', $registry->resolveForObject($child)?->name);
    }

    public function testUnknownProfileThrows(): void
    {
        $registry = ProfileRegistryFactory::single(ExactMatchUser::class);

        $this->expectException(UnknownProfileException::class);
        $registry->getByName('missing');
    }

    public function testResolveReturnsNullForUnknownClass(): void
    {
        $registry = ProfileRegistryFactory::single(ExactMatchUser::class);

        $this->assertNull($registry->resolveForObject(new OtherProfileUser()));
    }

    public function testFeatureFlags(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'enabled_profile' => [
                'user_class'     => ExactMatchUser::class,
                'account_status' => ['enabled' => true, 'field' => 'enabled', 'invalidate_sessions_on_disable' => true],
                'last_activity'  => ['enabled' => true, 'field' => 'lastActivityAt', 'online_threshold' => 300, 'update_throttle' => 0],
            ],
            'disabled_profile' => [
                'user_class'     => OtherProfileUser::class,
                'account_status' => ['enabled' => false, 'field' => 'enabled', 'invalidate_sessions_on_disable' => false],
                'last_activity'  => ['enabled' => false, 'field' => 'lastActivityAt', 'online_threshold' => 300, 'update_throttle' => 0],
            ],
        ], 'enabled_profile');

        $this->assertTrue($registry->hasAccountStatusEnabled());
        $this->assertTrue($registry->hasLastActivityEnabled());
        $this->assertTrue($registry->hasSessionInvalidationEnabled());
        $this->assertSame('enabled_profile', $registry->getDefault()->name);
    }
}

class ExactMatchUser
{
}

class BaseProfileUser
{
}

class ChildProfileUser extends BaseProfileUser
{
}

class OtherProfileUser
{
}
