<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\Event\AccountErasedEvent;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\RecordingLogger;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\ThrowingForClassRegistry;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * 1.22.0: {@see AccountErasedEvent} fires once per committed erasure, after the commit (never on a rollback, once for
 * the committed attempt of a replayed unit), and a listener that throws neither undoes nor masks the erasure. Runs on
 * SQLite, or on MySQL / PostgreSQL in the `real-databases` CI job.
 */
final class AccountErasedEventTest extends TransactionalSessionsTestCase
{
    /** @var list<array{event: AccountErasedEvent, inTransaction: bool}> */
    private array $erased = [];

    public function testAnErasureInItsOwnTransactionFiresOnceAfterTheCommit(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');

        $ran = $this->eraser()->erase($this->fresh($alice), null, ['tenant' => 'acme', 'reason' => 'user_request']);

        self::assertCount(1, $this->erased());
        $event = $this->erased()[0]['event'];
        self::assertFalse($this->erased()[0]['inTransaction'], 'Dispatched after the commit, outside any transaction.');
        self::assertSame(Member::class, $event->userType);
        self::assertSame((string) $alice->getId(), $event->userId);
        self::assertSame('alice@example.com', $event->originalIdentifier);
        self::assertSame($ran, $event->erasers);
        self::assertContains('sessions', $event->erasers);
        self::assertTrue($event->selfService);
        self::assertSame(['tenant' => 'acme', 'reason' => 'user_request'], $event->context);
        self::assertInstanceOf(Member::class, $event->user);
        self::assertNull($event->actorType, 'Self-service: no actor.');
        self::assertNull($event->actorId);
        self::assertSame($event->erasedAt, $event->occurredAt, '1.23.0: occurredAt is the erasure instant.');
        self::assertSame(0, $this->allRows(), 'The erasure is committed when the listener runs.');

        $this->queue()->release();
        $this->queue()->finish();
        $this->queue()->reset();
        self::assertCount(1, $this->erased(), 'Exactly once.');
    }

    /**
     * 1.23.0 (DDD-host checklist B4): the actor travels as ids, captured before the erasers ran.
     */
    public function testTheActorTravelsAsIds(): void
    {
        $alice = $this->bootErasure();
        $admin = $this->member('admin@example.com', ['ROLE_ADMIN']);

        $this->eraser()->erase($this->fresh($alice), $admin);

        self::assertCount(1, $this->erased());
        $event = $this->erased()[0]['event'];
        self::assertFalse($event->selfService);
        self::assertSame(Member::class, $event->actorType);
        self::assertSame((string) $admin->getId(), $event->actorId);
        self::assertSame((string) $alice->getId(), $event->userId);
        self::assertEquals($this->clock()->now(), $event->occurredAt);
    }

    /**
     * Audit YQ-L2 (1.23.1): an actor whose reference cannot be read does not refuse the erasure; the event carries no
     * actor ids and only the exception class is logged.
     */
    public function testAnActorWhoseReferenceThrowsIsErasedWithoutActorIds(): void
    {
        $alice    = $this->bootErasure();
        $doctrine = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $logger  = new RecordingLogger();
        $remover = new class implements PersonalDataEraserInterface {
            public ?EntityManagerInterface $em = null;

            public function getKey(): string
            {
                return 'remove';
            }

            public function supports(object $user): bool
            {
                return $user instanceof Member;
            }

            public function erase(ErasureRequest $request): void
            {
                $this->em?->remove($request->user);
            }
        };
        $remover->em = $this->em();
        $eraser      = new PersonalDataEraser([$remover], [], new ThrowingForClassRegistry($doctrine, stdClass::class), $this->queue(), null, $this->dispatcher(), $logger, $this->clock());
        $aliceId     = $alice->getId();

        self::assertSame(['remove'], $eraser->erase($this->fresh($alice), new stdClass()));

        self::assertCount(1, $this->erased(), 'The event is dispatched.');
        $event = $this->erased()[0]['event'];
        self::assertFalse($event->selfService);
        self::assertNull($event->actorType);
        self::assertNull($event->actorId);
        self::assertSame((string) $aliceId, $event->userId);
        $this->em()->clear();
        self::assertNull($this->em()->find(Member::class, $aliceId), 'The erasure is committed.');
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['exception' => RuntimeException::class], $logger->records[0]['context'], 'Only the exception class is logged.');
        self::assertStringNotContainsString('alice@example.com', $logger->records[0]['message']);
    }

    /**
     * Audit YR-U-L1 (1.23.1): a database failure while reading the actor's reference is rethrown, never swallowed;
     * nothing is erased and no event fires.
     */
    public function testADatabaseFailureOfTheActorReferenceIsRethrown(): void
    {
        $alice    = $this->bootErasure();
        $doctrine = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $logger = new RecordingLogger();
        $eraser = new PersonalDataEraser([], [], new ThrowingForClassRegistry($doctrine, stdClass::class, true), $this->queue(), null, $this->dispatcher(), $logger, $this->clock());

        try {
            $eraser->erase($this->fresh($alice), new stdClass());
            self::fail('The database failure was swallowed.');
        } catch (TableNotFoundException) {
        }

        self::assertSame([], $this->erased(), 'No event.');
        self::assertSame([], $logger->records, 'Not logged as a mere missing reference.');
        self::assertSame($alice->getId(), $this->fresh($alice)->getId(), 'Nothing erased.');
    }

    public function testInsideAnApplicationTransactionItWaitsForTheOutermostCommit(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');

        $this->connection()->beginTransaction();
        $this->eraser()->erase($this->fresh($alice));
        $this->queue()->release();
        self::assertCount(0, $this->erased(), 'Held while the application transaction is open.');
        $this->connection()->commit();
        self::assertCount(0, $this->erased(), 'Not from inside the driver commit.');
        $this->queue()->release();

        self::assertCount(1, $this->erased());
        self::assertFalse($this->erased()[0]['inTransaction']);
        $this->queue()->finish();
        $this->queue()->reset();
        self::assertCount(1, $this->erased(), 'Exactly once.');
    }

    public function testARolledBackErasureFiresNothing(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');

        $this->connection()->beginTransaction();
        $this->eraser()->erase($this->fresh($alice));
        $this->connection()->rollBack();
        $this->queue()->release();
        $this->queue()->finish();
        $this->queue()->reset();

        self::assertSame([], $this->erased());
        self::assertSame(1, $this->allRows(), 'The erasure was rolled back.');
    }

    public function testAnErasureUndoneByASavepointRollbackFiresNothing(): void
    {
        // Audit VT-M1: the erasure ran in a nested unit of the application (a savepoint) that was rolled back, and the
        // application committed the rest.
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');
        $this->connection()->setNestTransactionsWithSavepoints(true); // Off by default on DBAL 3.

        $this->connection()->beginTransaction();
        $this->connection()->beginTransaction();
        $this->eraser()->erase($this->fresh($alice));
        $this->connection()->rollBack();
        $this->connection()->commit();
        $this->queue()->release();
        $this->queue()->finish();

        self::assertSame(1, $this->allRows(), 'The savepoint rollback undid the erasure.');
        self::assertCount(0, $this->erased());
    }

    public function testANestedUnitRetriedInsideAnOpenTransactionFiresOnce(): void
    {
        // Audit VT-M1: the application retries a nested unit (a savepoint) inside its open transaction.
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');
        $this->connection()->setNestTransactionsWithSavepoints(true);

        $this->connection()->beginTransaction();
        foreach ([1, 2] as $attempt) {
            $this->connection()->beginTransaction();
            $this->eraser()->erase($this->fresh($alice), null, ['attempt' => $attempt]);
            if ($attempt === 1) {
                $this->connection()->rollBack();

                continue;
            }
            $this->connection()->commit();
        }
        $this->queue()->release();
        self::assertCount(0, $this->erased(), 'Held until the outermost commit.');
        $this->connection()->commit();
        $this->queue()->release();
        $this->queue()->finish();

        self::assertCount(1, $this->erased());
        self::assertSame(['attempt' => 2], $this->erased()[0]['event']->context);
        self::assertSame(0, $this->allRows());
    }

    public function testAThrowingCommittedEffectDoesNotCostTheEventOfACommittedErasure(): void
    {
        // Audit VT-M2: an effect of an earlier committed transaction fails when the erasure releases the queue.
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');
        $this->connection()->beginTransaction();
        $this->queue()->hold($this->connection(), static function (): never {
            throw new RuntimeException('An earlier committed effect failed.');
        });
        $this->connection()->commit();

        try {
            $this->eraser()->erase($this->fresh($alice));
            self::fail('The failure of the earlier effect goes on, as before.');
        } catch (RuntimeException $exception) {
            self::assertSame('An earlier committed effect failed.', $exception->getMessage());
        }

        self::assertSame(0, $this->allRows(), 'The erasure is committed.');
        self::assertCount(1, $this->erased(), 'Its event is not lost.');
    }

    public function testAReplayedUnitFiresOnceForTheAttemptThatCommitted(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');
        $attempts = 0;

        // The application replays its unit of work (transaction-doctrine's runner) after a transient failure: the first
        // attempt fails after the erasure and is rolled back, the second commits.
        $runner = DoctrineTransactionRunner::forConnection($this->connection(), null);
        do {
            try {
                $runner->run(function () use ($alice, &$attempts): void {
                    ++$attempts;
                    $this->eraser()->erase($this->fresh($alice), null, ['attempt' => $attempts]);
                    if ($attempts === 1) {
                        throw new TransientTestFailure('Deadlock found when trying to get lock');
                    }
                });
                $done = true;
            } catch (TransientStorageFailure) {
                $done = false;
                $this->queue()->release();
            }
        } while (!$done);
        $this->queue()->release();
        $this->queue()->finish();

        self::assertSame(2, $attempts);
        self::assertCount(1, $this->erased());
        self::assertSame(['attempt' => 2], $this->erased()[0]['event']->context);
        self::assertSame(0, $this->allRows());
    }

    public function testAListenerThatThrowsNeitherUndoesNorMasksTheErasure(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');
        $this->dispatcher()->addListener(AccountErasedEvent::class, static function (): never {
            throw new RuntimeException('Another bundle failed.');
        }, 10);

        $ran = $this->eraser()->erase($this->fresh($alice));

        self::assertContains('sessions', $ran);
        self::assertCount(1, $this->erased(), 'The next listener still runs.');
        self::assertSame(0, $this->allRows(), 'The erasure stays committed.');
        self::assertFalse($this->connection()->isTransactionActive());
    }

    public function testAListenerThatThrowsAfterTheApplicationCommitDoesNotBreakTheRelease(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');
        $this->dispatcher()->addListener(AccountErasedEvent::class, static function (): never {
            throw new RuntimeException('Another bundle failed.');
        }, 10);

        $this->connection()->beginTransaction();
        $this->eraser()->erase($this->fresh($alice));
        $this->connection()->commit();
        $this->queue()->release();

        self::assertCount(1, $this->erased());
        self::assertSame(0, $this->allRows());
    }

    public function testAListenerCanWriteInATransactionOfItsOwn(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');
        $table = $this->em()->getClassMetadata(Member::class)->getTableName();
        $this->dispatcher()->addListener(AccountErasedEvent::class, function (AccountErasedEvent $event) use ($table): void {
            // As a bundle that refuses foreign transactions would: its own, on PostgreSQL a failed SAVEPOINT otherwise.
            self::assertFalse($this->connection()->isTransactionActive());
            $this->connection()->transactional(static fn (Connection $connection): int|string => $connection->executeStatement('UPDATE ' . $table . " SET email = 'erased-" . $event->userId . "@example.invalid'"));
        });

        $this->connection()->beginTransaction();
        $this->eraser()->erase($this->fresh($alice));
        $this->connection()->commit();
        $this->queue()->release();

        self::assertSame('erased-' . $alice->getId() . '@example.invalid', $this->connection()->fetchOne('SELECT email FROM ' . $table));
    }

    public function testWorkerResetDropsAnUncommittedErasureAndNothingLeaksIntoTheNextRequest(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');

        $this->connection()->beginTransaction();
        $this->eraser()->erase($this->fresh($alice));
        $this->queue()->reset();
        $this->connection()->rollBack();
        self::assertSame([], $this->erased());

        // The next request of the worker commits a transaction of its own: nothing of the previous one comes back.
        $this->connection()->beginTransaction();
        $this->connection()->commit();
        $this->queue()->release();
        $this->queue()->finish();

        self::assertSame([], $this->erased());
    }

    public function testWorkerResetDispatchesACommittedErasureNotYetReleased(): void
    {
        $alice = $this->bootErasure();
        $this->signIn('alice@example.com');

        $this->connection()->beginTransaction();
        $this->eraser()->erase($this->fresh($alice));
        $this->connection()->commit();
        $this->queue()->reset();
        $this->queue()->reset();

        self::assertCount(1, $this->erased());
    }

    private function bootErasure(): Member
    {
        $this->setUpDatabase('database', userKit: ['personal_data' => ['enabled' => true]]);
        $alice = $this->member('alice@example.com');
        $this->dispatcher()->addListener(AccountErasedEvent::class, function (AccountErasedEvent $event): void {
            $this->erased[] = ['event' => $event, 'inTransaction' => $this->connection()->isTransactionActive()];
        });

        return $alice;
    }

    /**
     * What the recording listener saw so far.
     *
     * @return list<array{event: AccountErasedEvent, inTransaction: bool}>
     */
    private function erased(): array
    {
        return $this->erased;
    }

    private function eraser(): PersonalDataEraser
    {
        $eraser = $this->container()->get('test.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);

        return $eraser;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function queue(): CommitQueue
    {
        $queue = $this->container()->get(CommitQueue::class);
        self::assertInstanceOf(CommitQueue::class, $queue);

        return $queue;
    }
}

/**
 * A transient storage failure as the transaction-doctrine runner classifies it (retryable).
 */
final class TransientTestFailure extends RuntimeException implements RetryableException
{
}
