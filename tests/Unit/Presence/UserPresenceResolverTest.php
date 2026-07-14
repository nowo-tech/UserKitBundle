<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Presence;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Presence\UserPresenceResolver;
use Nowo\UserKitBundle\Profile\UnknownProfileException;
use Nowo\UserKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class UserPresenceResolverTest extends TestCase
{
    public function testOnlineWhenWithinThreshold(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::single(PresenceUser::class, [
                'last_activity' => ['online_threshold' => 60],
            ]),
            PropertyAccess::createPropertyAccessor(),
        );
        $user = new PresenceUser(new DateTimeImmutable('-30 seconds'));

        $this->assertTrue($resolver->isOnline($user));
    }

    public function testOfflineWhenThresholdExceeded(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::single(PresenceUser::class, [
                'last_activity' => ['online_threshold' => 60],
            ]),
            PropertyAccess::createPropertyAccessor(),
        );
        $user = new PresenceUser(new DateTimeImmutable('-2 minutes'));

        $this->assertFalse($resolver->isOnline($user));
    }

    public function testOfflineWithoutActivity(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::single(PresenceUser::class),
            PropertyAccess::createPropertyAccessor(),
        );

        $this->assertFalse($resolver->isOnline(new PresenceUser(null)));
    }

    public function testExplicitProfileName(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::fromProfiles([
                'app_user' => [
                    'user_class'     => PresenceUser::class,
                    'account_status' => ['enabled' => true, 'field' => 'enabled', 'invalidate_sessions_on_disable' => false],
                    'last_activity'  => ['enabled' => true, 'field' => 'lastActivityAt', 'online_threshold' => 30, 'update_throttle' => 0],
                ],
                'admin' => [
                    'user_class'     => OtherPresenceUser::class,
                    'account_status' => ['enabled' => true, 'field' => 'enabled', 'invalidate_sessions_on_disable' => false],
                    'last_activity'  => ['enabled' => true, 'field' => 'lastActivityAt', 'online_threshold' => 120, 'update_throttle' => 0],
                ],
            ]),
            PropertyAccess::createPropertyAccessor(),
        );

        $user = new PresenceUser(new DateTimeImmutable('-45 seconds'));
        $this->assertFalse($resolver->isOnline($user));
        $this->assertTrue($resolver->isOnline($user, 'admin'));
    }

    public function testUnknownProfileThrows(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::single(PresenceUser::class),
            PropertyAccess::createPropertyAccessor(),
        );

        $this->expectException(UnknownProfileException::class);
        $resolver->isOnline(new PresenceUser(new DateTimeImmutable()), 'missing');
    }

    public function testOfflineForUnmappedUserClass(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::single(PresenceUser::class),
            PropertyAccess::createPropertyAccessor(),
        );

        $this->assertFalse($resolver->isOnline(new UnmappedPresenceUser(new DateTimeImmutable())));
    }
}

class UnmappedPresenceUser
{
    public function __construct(public DateTimeInterface $lastActivityAt)
    {
    }
}

class PresenceUser implements LastActivityInterface
{
    public function __construct(private ?DateTimeInterface $lastActivityAt)
    {
    }

    public function getLastActivityAt(): ?DateTimeInterface
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(DateTimeInterface $lastActivityAt): void
    {
        $this->lastActivityAt = $lastActivityAt;
    }
}

class OtherPresenceUser
{
}
