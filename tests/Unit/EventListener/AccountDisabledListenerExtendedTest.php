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

final class AccountDisabledListenerExtendedTest extends TestCase
{
    public function testIgnoresWhenEnabledFieldDidNotChange(): void
    {
        $user        = new DisableUser(true);
        $invalidator = $this->createMock(SessionInvalidatorInterface::class);
        $invalidator->expects($this->never())->method('invalidateSessionsForUser');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['email' => ['a', 'b']]);

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

    public function testIgnoresWhenAccountStaysEnabled(): void
    {
        $user        = new DisableUser(true);
        $invalidator = $this->createMock(SessionInvalidatorInterface::class);
        $invalidator->expects($this->never())->method('invalidateSessionsForUser');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['enabled' => [true, true]]);

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

    public function testIgnoresWhenEnabledFieldChangeIsNotArray(): void
    {
        $user        = new DisableUser(true);
        $invalidator = $this->createMock(SessionInvalidatorInterface::class);
        $invalidator->expects($this->never())->method('invalidateSessionsForUser');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['enabled' => 'unexpected']);

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
}
