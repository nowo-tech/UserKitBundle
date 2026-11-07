<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HP-L2: what a rollback cannot undo (the mark in a cache store, {@see UserSessionsRevokedEvent})
 * waits for the outermost commit and goes with a rollback. Without a transaction nothing changes.
 */
final class SessionCommitBoundaryTest extends TransactionalSessionsTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'database store' => ['database'];
        yield 'cache store' => ['cache'];
    }

    #[DataProvider('stores')]
    public function testARolledBackPasswordChangeSignsNobodyOutAndTellsNobody(string $store): void
    {
        $this->setUpDatabase($store);
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $alice = $this->fresh($alice);
        $alice->setPassword('changed');
        $this->em()->flush();
        self::assertSame([], $this->log()->revoked, 'Held while the transaction is open.');
        $this->connection()->rollBack();
        $this->manager()->releaseHeldRevocations();

        self::assertSame([], $this->log()->revoked);
        self::assertCount(1, $this->rowsOf($alice));
        $phone->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($phone), 'HP-L2 T2: with the cache store this browser was signed out for real.');
    }

    #[DataProvider('stores')]
    public function testACommittedPasswordChangeSignsTheOthersOutAfterTheCommit(string $store): void
    {
        $this->setUpDatabase($store);
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $alice = $this->fresh($alice);
        $alice->setPassword('changed');
        $this->em()->flush();
        self::assertCount(0, $this->rowsOf($alice), 'The rows are written inside the transaction.');
        self::assertSame([], $this->log()->revoked);
        $this->connection()->commit();
        self::assertSame([], $this->log()->revoked, 'Not from inside the driver commit: DBAL still counts the transaction as open.');
        $this->manager()->releaseHeldRevocations();

        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
        self::assertCount(1, $this->log()->revoked[0]->publicIds);
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testAListenerCanOpenATransactionWhenTheEffectsRun(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $table             = $this->em()->getClassMetadata(Member::class)->getTableName();
        $this->log()->then = function () use ($table): void {
            // On PostgreSQL this is a failed SAVEPOINT if it runs while DBAL still counts the transaction as open.
            $this->connection()->transactional(static fn ($connection) => $connection->executeStatement('UPDATE ' . $table . " SET email = 'audited@example.com'"));
        };

        $this->connection()->beginTransaction();
        $alice = $this->fresh($alice);
        $alice->setPassword('changed');
        $this->em()->flush();
        $this->connection()->commit();
        $this->manager()->releaseHeldRevocations();

        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame('audited@example.com', $this->connection()->fetchOne('SELECT email FROM ' . $table));
    }

    public function testAnEffectThatThrowsDoesNotCostTheOthersTheirRevocation(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $bob   = $this->member('bob@example.com');
        $phone = $this->signIn('bob@example.com');
        $this->signIn('alice@example.com');
        $this->log()->then = function (): never {
            $this->log()->then = null;

            throw new RuntimeException('A listener failed.');
        };

        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::PasswordChanged);
        $this->manager()->revokeAll($this->fresh($bob), null, RevocationReason::PasswordChanged);
        $this->connection()->commit();

        try {
            $this->manager()->releaseHeldRevocations();
            self::fail('The exception goes on.');
        } catch (RuntimeException $exception) {
            self::assertSame('A listener failed.', $exception->getMessage());
        }
        self::assertCount(2, $this->log()->revoked);
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testATransactionThatEndedUnseenLeavesNothingForTheNextOne(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::PasswordChanged);
        $this->connection()->close();

        $this->connection()->beginTransaction();
        $this->connection()->commit();
        $this->manager()->releaseHeldRevocations();

        self::assertSame([], $this->log()->revoked);
    }

    public function testAfterAClosedConnectionARevocationIsNotLeftWaiting(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $this->connection()->close();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::PasswordChanged);

        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons(), 'No transaction is open any more: at once.');
    }

    public function testResetRunsWhatCommittedAndDropsWhatDidNot(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $bob   = $this->member('bob@example.com');
        $phone = $this->signIn('alice@example.com');
        $other = $this->signIn('bob@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::PasswordChanged);
        $this->connection()->commit();
        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($bob), null, RevocationReason::PasswordChanged);
        $this->queue()->reset();

        self::assertCount(1, $this->log()->revoked, 'HU-L2: the committed one runs instead of being dropped, the open one does not.');
        self::assertSame((string) $alice->getId(), $this->log()->revoked[0]->owner->id);
        $this->connection()->rollBack();
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        $other->request('GET', '/page');
        self::assertStringStartsWith('bob@example.com', $this->body($other));
    }

    public function testACommitInALowPriorityTerminateListenerIsStillReleased(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::TERMINATE, function () use ($alice): void {
            $this->connection()->beginTransaction();
            $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::PasswordChanged);
            $this->connection()->commit();
        }, -100);

        // The browser terminates the kernel at the end of the request.
        $phone->request('GET', '/page');

        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons(), 'HU-L2: the release runs after that listener.');
    }

    /**
     * HU-M1 (Q9): a batch looping one transaction per user, where one user's failure undid the effects for the previous.
     */
    public function testACommittedPasswordChangeIsNotReleasedIntoALaterOpenTransaction(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $bob   = $this->member('bob@example.com');
        $this->signIn('alice@example.com');
        $this->log()->clear();
        $table             = $this->em()->getClassMetadata(Member::class)->getTableName();
        $level             = null;
        $this->log()->then = function () use (&$level, $table): void {
            $level = $this->connection()->getTransactionNestingLevel();
            $this->connection()->executeStatement('UPDATE ' . $table . " SET email = 'audited@example.com' WHERE email = 'bob@example.com'");
        };

        $this->connection()->beginTransaction();
        $alice = $this->fresh($alice);
        $alice->setPassword('changed');
        $this->em()->flush();
        $this->connection()->commit();

        // A second unit of work, which then fails: its flush and an explicit release must not run the first one's effects.
        $this->connection()->beginTransaction();
        $bob = $this->fresh($bob);
        $bob->setEnabled(false);
        $this->em()->flush();
        $this->manager()->releaseHeldRevocations();
        self::assertSame([], $this->log()->revoked);
        $this->connection()->rollBack();
        $this->manager()->releaseHeldRevocations();

        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
        self::assertSame(0, $level, 'Outside any transaction.');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $table . " WHERE email = 'audited@example.com'"), 'The listener\'s write survives.');
    }

    public function testAClosedConnectionDoesNotHoldEffectsBackForever(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::PasswordChanged);
        $this->connection()->commit();
        $this->connection()->beginTransaction();
        $this->connection()->close(); // Sends the driver no rollback.
        $this->manager()->releaseHeldRevocations();

        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
    }

    private function queue(): CommitQueue
    {
        $queue = $this->container()->get(CommitQueue::class);
        self::assertInstanceOf(CommitQueue::class, $queue);

        return $queue;
    }

    public function testWithoutATransactionTheEventFiresAtOnce(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();

        $alice = $this->fresh($alice);
        $alice->setPassword('changed');
        $this->em()->flush();

        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testANestedCommitIsNotTheOutermostOne(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::PasswordChanged);
        $this->connection()->commit();
        $this->manager()->releaseHeldRevocations();
        self::assertSame([], $this->log()->revoked, 'Only a savepoint was released.');

        $this->connection()->commit();
        $this->manager()->releaseHeldRevocations();
        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
    }

    public function testARevocationUndoneByARolledBackSavepointIsDroppedAtTheOuterCommit(): void
    {
        $this->setUpDatabase('cache');
        $this->connection()->setNestTransactionsWithSavepoints(true); // Off by default on DBAL 3.
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::PasswordChanged);
        $this->connection()->rollBack();
        $this->connection()->commit();
        $this->manager()->releaseHeldRevocations();

        self::assertSame([], $this->log()->revoked);
        $phone->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($phone));
    }

    public function testARevocationKeptAtTheOuterCommitWithOneSessionUndoneOnlyCountsTheOther(): void
    {
        $this->setUpDatabase('cache');
        $this->connection()->setNestTransactionsWithSavepoints(true); // Off by default on DBAL 3.
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->signIn('alice@example.com');
        $this->log()->clear();
        $rows = $this->rowsOf($alice);
        self::assertCount(2, $rows);

        $owner = $this->manager()->owner($alice) ?? self::fail();

        $this->connection()->beginTransaction();
        $this->connection()->beginTransaction();
        $this->manager()->revokeSessions($owner, $alice, [$rows[0]->getPublicId()], RevocationReason::PasswordChanged);
        $this->connection()->rollBack();
        $this->manager()->revokeSessions($owner, $alice, [$rows[1]->getPublicId()], RevocationReason::PasswordChanged);
        $this->connection()->commit();
        $this->manager()->releaseHeldRevocations();

        self::assertCount(1, $this->log()->revoked);
        self::assertSame([$rows[1]->getPublicId()], $this->log()->revoked[0]->publicIds);
        self::assertCount(1, $this->rowsOf($alice));
        unset($laptop, $phone);
    }

    public function testACommitThatFailsCountsAsARollback(): void
    {
        $this->setUpDatabase('cache');
        if (!$this->connection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Only PostgreSQL has deferred constraints failing at COMMIT.');
        }
        $connection = $this->connection();
        $connection->executeStatement('DROP TABLE IF EXISTS uk_child');
        $connection->executeStatement('DROP TABLE IF EXISTS uk_parent');
        $connection->executeStatement('CREATE TABLE uk_parent (id INT PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE uk_child (parent_id INT REFERENCES uk_parent (id) DEFERRABLE INITIALLY DEFERRED)');
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO uk_child (parent_id) VALUES (1)');
        $alice = $this->fresh($alice);
        $alice->setPassword('changed');
        $this->em()->flush();

        try {
            $connection->commit();
            self::fail('The deferred constraint should fail at COMMIT.');
        } catch (DbalException) {
            // Expected.
        }
        $this->manager()->releaseHeldRevocations();

        self::assertSame([], $this->log()->revoked);
        $phone->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($phone));
        $connection->executeStatement('DROP TABLE uk_child');
        $connection->executeStatement('DROP TABLE uk_parent');
    }

    public function testAnotherReasonKeepsItsListenersInTheTransactionAndOnlyTheMarkWaits(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::AccountChanged);
        self::assertSame([RevocationReason::AccountChanged->value], $this->revokedReasons(), 'At once, as before: its listeners write in this transaction.');
        $this->connection()->rollBack();
        $this->manager()->releaseHeldRevocations();

        $phone->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($phone), 'The mark went with the rollback.');
    }

    public function testAnotherReasonCommittedMarksTheCacheOnce(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        $this->manager()->revokeAll($this->fresh($alice), null, RevocationReason::AccountChanged);
        $this->connection()->commit();
        $this->manager()->releaseHeldRevocations();

        self::assertCount(1, $this->log()->revoked, 'The event is not told twice.');
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testAMarkMadeInsideAFlushIsInThePoolWhenTheFlushReturns(): void
    {
        $this->setUpDatabase('cache', userKit: ['account_status' => ['invalidate_sessions_on_disable' => true]]);
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');

        $alice = $this->fresh($alice);
        $alice->setEnabled(false);
        $this->em()->flush();

        // Without a release on postFlush the next request's reset would lose the mark.
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testAnErasureCommitsItsMarkBeforeItReturns(): void
    {
        $this->setUpDatabase('cache', userKit: ['personal_data' => ['enabled' => true]]);
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->log()->clear();
        $eraser = $this->container()->get('test.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);

        $eraser->erase($this->fresh($alice));

        self::assertCount(1, $this->log()->revoked);
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testACommitInARequestIsReleasedByItsResponse(): void
    {
        $this->setUpDatabase('cache');
        $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->signIn('alice@example.com');
        $this->log()->clear();

        $laptop->request('GET', '/page/tx-password?end=commit');

        self::assertSame('done', $this->body($laptop));
        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testARollbackInARequestLeavesNothingForItsResponse(): void
    {
        $this->setUpDatabase('cache');
        $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->signIn('alice@example.com');
        $this->log()->clear();

        $laptop->request('GET', '/page/tx-password?end=rollback');

        self::assertSame([], $this->log()->revoked);
        $phone->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($phone));
    }
}
