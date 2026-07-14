<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\UserKitBundle\EventListener\AccountDisabledListener;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Nowo\UserKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AccountDisabledListenerTest extends TestCase
{
    public function testInvalidatesSessionsWhenAccountDisabled(): void
    {
        $user        = new DisableUser(true);
        $invalidator = $this->createMock(SessionInvalidatorInterface::class);
        $invalidator->expects($this->once())->method('invalidateSessionsForUser')->with($user);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['enabled' => [true, false]]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $listener = new AccountDisabledListener(
            ProfileRegistryFactory::single(DisableUser::class, [
                'account_status' => ['invalidate_sessions_on_disable' => true],
            ]),
            $invalidator,
        );
        $listener->postUpdate($user, new PostUpdateEventArgs($user, $em));
    }

    public function testIgnoresNonUserEntities(): void
    {
        $invalidator = $this->createMock(SessionInvalidatorInterface::class);
        $invalidator->expects($this->never())->method('invalidateSessionsForUser');

        $listener = new AccountDisabledListener(
            ProfileRegistryFactory::single(DisableUser::class, [
                'account_status' => ['invalidate_sessions_on_disable' => true],
            ]),
            $invalidator,
        );
        $listener->postUpdate(new stdClass(), new PostUpdateEventArgs(new stdClass(), $this->createMock(EntityManagerInterface::class)));
    }
}

class DisableUser
{
    public function __construct(public bool $enabled)
    {
    }
}
