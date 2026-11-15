<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\UserKitBundle\EventSubscriber\UnitOfWorkBoundarySubscriber;
use Nowo\UserKitBundle\Transaction\ClosedManagerRecovery;
use Nowo\UserKitBundle\Transaction\FlushObserver;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final class SubscribersTest extends TestCase
{
    public function testAUserKitStorageFailureIsA503WithRetryAfter(): void
    {
        $failure = new UserKitStorageFailure(2);
        $mapped  = $this->mapped($failure);

        self::assertInstanceOf(ServiceUnavailableHttpException::class, $mapped);
        self::assertSame(503, $mapped->getStatusCode());
        self::assertSame(['Retry-After' => 2], $mapped->getHeaders());
        self::assertSame(UserKitStorageFailure::DEFAULT_MESSAGE, $mapped->getMessage());
        self::assertSame($failure, $mapped->getPrevious());
        self::assertSame([KernelEvents::EXCEPTION => ['onException', -64]], StorageFailureSubscriber::getSubscribedEvents());
    }

    public function testAUserKitConflictIsA409(): void
    {
        $mapped = $this->mapped(new UserKitConflict());

        self::assertInstanceOf(ConflictHttpException::class, $mapped);
        self::assertSame(UserKitConflict::DEFAULT_MESSAGE, $mapped->getMessage());
    }

    public function testOtherFailuresAreLeftAlone(): void
    {
        $failure = new RuntimeException('application');

        self::assertSame($failure, $this->mapped($failure));
    }

    public function testTheBoundariesReplaceClosedManagersOutsideSubRequests(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');
        $boundary = new UnitOfWorkBoundarySubscriber(new ClosedManagerRecovery($doctrine));
        $kernel   = $this->createMock(HttpKernelInterface::class);

        $boundary->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $boundary->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        $boundary->onBoundary();
        self::assertArrayHasKey(KernelEvents::REQUEST, UnitOfWorkBoundarySubscriber::getSubscribedEvents());
        self::assertArrayHasKey('console.terminate', UnitOfWorkBoundarySubscriber::getSubscribedEvents());
    }

    public function testTheFlushObserverResets(): void
    {
        $observer = new FlushObserver();
        $observer->postFlush();
        self::assertTrue($observer->flushed);
        $observer->reset();
        self::assertFalse($observer->flushed);
    }

    private function mapped(Throwable $failure): Throwable
    {
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $failure);
        (new StorageFailureSubscriber())->onException($event);

        return $event->getThrowable();
    }
}
