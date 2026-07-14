<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Presence;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Presence\UserPresenceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class UserPresenceResolverTest extends TestCase
{
    public function testOnlineWhenWithinThreshold(): void
    {
        $resolver = new UserPresenceResolver(60, 'lastActivityAt', PropertyAccess::createPropertyAccessor());
        $user     = new PresenceUser(new DateTimeImmutable('-30 seconds'));

        $this->assertTrue($resolver->isOnline($user));
    }

    public function testOfflineWhenThresholdExceeded(): void
    {
        $resolver = new UserPresenceResolver(60, 'lastActivityAt', PropertyAccess::createPropertyAccessor());
        $user     = new PresenceUser(new DateTimeImmutable('-2 minutes'));

        $this->assertFalse($resolver->isOnline($user));
    }

    public function testOfflineWithoutActivity(): void
    {
        $resolver = new UserPresenceResolver(60, 'lastActivityAt', PropertyAccess::createPropertyAccessor());

        $this->assertFalse($resolver->isOnline(new PresenceUser(null)));
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
