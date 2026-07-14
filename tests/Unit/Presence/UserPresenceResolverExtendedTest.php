<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Presence;

use DateTimeImmutable;
use Nowo\UserKitBundle\Presence\UserPresenceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class UserPresenceResolverExtendedTest extends TestCase
{
    public function testUsesPropertyAccessorWhenInterfaceMissing(): void
    {
        $resolver = new UserPresenceResolver(120, 'lastActivityAt', PropertyAccess::createPropertyAccessor());
        $user     = new PropertyPresenceUser(new DateTimeImmutable('-30 seconds'));

        $this->assertTrue($resolver->isOnline($user));
    }

    public function testReturnsOfflineForInvalidStoredValue(): void
    {
        $resolver = new UserPresenceResolver(120, 'lastActivityAt', PropertyAccess::createPropertyAccessor());
        $user     = new PropertyPresenceUser('invalid');

        $this->assertFalse($resolver->isOnline($user));
    }
}

class PropertyPresenceUser
{
    public function __construct(public mixed $lastActivityAt)
    {
    }
}
