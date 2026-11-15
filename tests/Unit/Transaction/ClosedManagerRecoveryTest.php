<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\UserKitBundle\Transaction\ClosedManagerRecovery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ClosedManagerRecoveryTest extends TestCase
{
    public function testOnlyRememberedManagersStillClosedAreReplacedOnce(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $reopened = $this->createMock(EntityManagerInterface::class);
        $reopened->method('isOpen')->willReturn(true);
        $plain    = $this->createMock(ObjectManager::class);
        $stranger = $this->createMock(EntityManagerInterface::class);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagers')->willReturn(['closed' => $closed, 'reopened' => $reopened, 'plain' => $plain]);
        $doctrine->method('getManager')->willReturnMap([['closed', $closed], ['reopened', $reopened], ['plain', $plain]]);
        $doctrine->expects($this->once())->method('resetManager')->with('closed');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $recovery = new ClosedManagerRecovery($doctrine, $logger);

        $recovery->remember($closed);
        $recovery->remember($reopened);
        $recovery->remember($stranger);
        $recovery->reset();
        $recovery->reset();
    }

    public function testAManagerNameThatIsNoLongerAnOrmManagerIsSkipped(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagers')->willReturn(['default' => $closed]);
        $doctrine->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $doctrine->expects($this->never())->method('resetManager');
        $recovery = new ClosedManagerRecovery($doctrine);

        $recovery->remember($closed);
        $recovery->reset();
    }
}
