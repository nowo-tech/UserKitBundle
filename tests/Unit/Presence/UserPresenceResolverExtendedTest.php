<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Presence;

use DateTimeImmutable;
use Nowo\UserKitBundle\Presence\UserPresenceResolver;
use Nowo\UserKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class UserPresenceResolverExtendedTest extends TestCase
{
    public function testUsesPropertyAccessorWhenInterfaceMissing(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::single(PropertyPresenceUser::class, [
                'last_activity' => ['online_threshold' => 120],
            ]),
            PropertyAccess::createPropertyAccessor(),
        );
        $user = new PropertyPresenceUser(new DateTimeImmutable('-30 seconds'));

        $this->assertTrue($resolver->isOnline($user));
    }

    public function testReturnsOfflineForInvalidStoredValue(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::single(PropertyPresenceUser::class, [
                'last_activity' => ['online_threshold' => 120],
            ]),
            PropertyAccess::createPropertyAccessor(),
        );
        $user = new PropertyPresenceUser('invalid');

        $this->assertFalse($resolver->isOnline($user));
    }
}

class PropertyPresenceUser
{
    public function __construct(public mixed $lastActivityAt)
    {
    }
}
