<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Model;

use DateTime;
use DateTimeImmutable;
use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\Model\EnabledUserTrait;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Model\LastActivityTrait;
use PHPUnit\Framework\TestCase;

final class ModelTraitTest extends TestCase
{
    public function testEnabledUserTrait(): void
    {
        $user = new EnabledEntity();
        $this->assertTrue($user->isEnabled());
        $user->setEnabled(false);
        $this->assertFalse($user->isEnabled());
    }

    public function testLastActivityTrait(): void
    {
        $user = new ActivityEntity();
        $now  = new DateTimeImmutable();
        $user->setLastActivityAt($now);
        $this->assertSame($now, $user->getLastActivityAt());
        $user->setLastActivityAt(new DateTime());
        $this->assertInstanceOf(DateTimeImmutable::class, $user->getLastActivityAt());
    }
}

class EnabledEntity implements AccountStatusInterface
{
    use EnabledUserTrait;
}

class ActivityEntity implements LastActivityInterface
{
    use LastActivityTrait;
}
