<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\UserKitBundle\EventSubscriber\UnitOfWorkBoundarySubscriber;
use Nowo\UserKitBundle\Transaction\ClosedManagerRecovery;
use Nowo\UserKitBundle\Transaction\FlushObserver;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function json_decode;

final class SubscribersTest extends TestCase
{
    public function testAUserKitStorageFailureIsA409WithRetryAfterAndConflict001(): void
    {
        $failure  = new UserKitStorageFailure(2);
        $response = $this->answered($failure, $this->json());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'), 'the policy decides, as everywhere in the application');
        self::assertSame(['error' => 'concurrency_conflict', 'code' => 'CONFLICT_001', 'message' => 'Your change was not saved because the same data was being changed at the same time. Nothing was lost: try again.'], json_decode((string) $response->getContent(), true));
        self::assertSame([KernelEvents::EXCEPTION => ['onException', -64]], StorageFailureSubscriber::getSubscribedEvents());
    }

    public function testAnHtmlRequestGetsTheTryAgainPageWithTheCode(): void
    {
        $response = $this->answered(new UserKitStorageFailure(1), new Request());

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('data-conflict-code="CONFLICT_001"', (string) $response->getContent());
    }

    public function testAFailureAfterACommitIsConflict002WithoutRetryAfter(): void
    {
        $failure  = UserKitStorageFailure::afterCommit(new UserKitStorageFailure(1, UserKitStorageFailure::DEFAULT_MESSAGE, $this->deadlock()));
        $response = $this->answered($failure, $this->json());

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'), 'a blind retry could repeat what was committed');
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('CONFLICT_002', $body['code']);
        self::assertSame('concurrency_conflict_partial', $body['error']);
    }

    public function testTheStatusIsConfigurableTo503(): void
    {
        $response = $this->answered(new UserKitStorageFailure(1), $this->json(), new StorageFailureSubscriber(new ConflictResponsePolicy(503)));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
    }

    public function testAGivenResponderShapesTheAnswer(): void
    {
        $policy   = new ConflictResponsePolicy(409, 4);
        $response = $this->answered(new UserKitStorageFailure(1), $this->json(), new StorageFailureSubscriber($policy, new ConcurrencyConflictResponder($policy, errorCode: 'busy')));

        self::assertSame('4', $response->headers->get('Retry-After'));
        self::assertStringContainsString('"error":"busy"', (string) $response->getContent());
    }

    public function testTheErrorPageModeKeepsTheApplicationsRendering(): void
    {
        $subscriber = new StorageFailureSubscriber(new ConflictResponsePolicy(503), null, true);
        $failure    = new UserKitStorageFailure(2);
        $mapped     = $this->mapped($failure, $subscriber);

        self::assertInstanceOf(HttpException::class, $mapped);
        self::assertSame(503, $mapped->getStatusCode());
        self::assertSame(['Retry-After' => '1'], $mapped->getHeaders());
        self::assertSame(UserKitStorageFailure::DEFAULT_MESSAGE, $mapped->getMessage());
        self::assertSame($failure, $mapped->getPrevious());

        $committed = $this->mapped(UserKitStorageFailure::afterCommit($failure), new StorageFailureSubscriber(null, null, true));
        self::assertInstanceOf(HttpException::class, $committed);
        self::assertSame(409, $committed->getStatusCode());
        self::assertSame([], $committed->getHeaders());
    }

    public function testAUserKitConflictIsA409(): void
    {
        $mapped = $this->mapped(new UserKitConflict());

        // A domain conflict, not a lock conflict: the application's error page, no CONFLICT_* code.
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

    private function mapped(Throwable $failure, ?StorageFailureSubscriber $subscriber = null): Throwable
    {
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $failure);
        ($subscriber ?? new StorageFailureSubscriber())->onException($event);
        self::assertNull($event->getResponse());

        return $event->getThrowable();
    }

    private function answered(Throwable $failure, Request $request, ?StorageFailureSubscriber $subscriber = null): Response
    {
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        ($subscriber ?? new StorageFailureSubscriber())->onException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        return $response;
    }

    private function json(): Request
    {
        return Request::create('/account/sessions', 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('deadlock', null, 1213) extends AbstractException {
        }, null);
    }
}
