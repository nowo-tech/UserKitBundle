<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Load\Support\LoadTestCase;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;

use function preg_match;
use function str_starts_with;

/**
 * 1.19.0 (Beacon spec 239), two sessions on MySQL 8.4 / PostgreSQL 16: the personal data erasure is one unit of work of
 * UserKit's runner (up to 1.18 `wrapInTransaction()` with the flush nested in it). A deadlock inside the nested flush
 * (MySQL already dropped the savepoint: 1305) and a lock wait both answer UserKitStorageFailure (409 + CONFLICT_001 since 1.21.0), leave the
 * worker's connection at level 0 and erase nothing; the next erasure works. A parallel first bump of the remember-me
 * epoch no longer fails the erasure.
 */
final class ErasureLoadTest extends LoadTestCase
{
    public function testADeadlockInTheErasuresNestedFlushIsTransientAndTheNextErasureWorks(): void
    {
        $this->boot(['personal_data' => ['enabled' => true], 'sessions' => ['enabled' => true]]);
        $member = $this->member('ada@example.com');
        $id     = (int) $member->getId();
        $owner  = $this->owner($member);
        $this->get(UserSessionStore::class)->insert($owner, 'hash-ada', new SessionClient(), new DateTimeImmutable());
        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        $this->ballast();
        // The peer writes the member row first, then (after a pause) wants the session row the erasure revoked.
        self::assertSame('OK', $peer->execute("UPDATE user_kit_test_member SET password = 'peer' WHERE id = " . $id));
        $peer->send($this->sleepStatement(1.5));
        $peer->send("UPDATE user_kit_session SET browser = 'peer' WHERE session_hash = 'hash-ada'");

        // The server picks the victim (MySQL: the lighter transaction, this one; PostgreSQL: the session whose deadlock
        // check runs first): exactly one side loses.
        $failed = false;
        try {
            self::assertSame(['member', 'sessions'], $this->eraser()->erase($member));
        } catch (UserKitStorageFailure $failure) {
            $failed = true;
            self::assertTrue($this->causedBy($failure, DeadlockException::class), 'a deadlock, never the 1305 of the lost savepoint');
            self::assertSame(UserKitStorageFailure::DEFAULT_MESSAGE, $failure->getMessage(), 'no SQL in the message');
            self::assertSame(ConflictCode::RetrySafe, $failure->conflictCode(), 'nothing committed: CONFLICT_001');
        }
        self::assertSame('OK', $peer->await(), 'the sleep');
        $peerAnswer = $peer->await();
        self::assertSame($failed, str_starts_with($peerAnswer, 'OK'), 'exactly one side lost the deadlock: ' . $peerAnswer);
        self::assertTrue($failed || preg_match('/^ERR (1213|\d+ 40P01)/', $peerAnswer) === 1, $peerAnswer);
        self::assertSame('OK', $peer->execute('ROLLBACK'));
        $this->assertHealthy();
        self::assertSame($failed ? 0 : 1, $this->rows("SELECT COUNT(*) FROM user_kit_test_member WHERE password = 'erased'"), 'all or nothing');
        self::assertSame($failed ? 1 : 0, $this->rows('SELECT COUNT(*) FROM user_kit_session'), 'all or nothing');

        if ($failed) {
            // The next request of this worker erases on the same connection.
            self::assertSame(['member', 'sessions'], $this->eraser()->erase($this->reloaded($id)));
            self::assertSame(1, $this->rows("SELECT COUNT(*) FROM user_kit_test_member WHERE password = 'erased'"));
            self::assertSame(0, $this->rows('SELECT COUNT(*) FROM user_kit_session'));
        }
        $this->assertHealthy();
    }

    public function testALockWaitOnTheMemberRowIsTransientAndErasesNothing(): void
    {
        $this->boot(['personal_data' => ['enabled' => true], 'sessions' => ['enabled' => true]]);
        $member = $this->member('ada@example.com');
        $id     = (int) $member->getId();
        $this->get(UserSessionStore::class)->insert($this->owner($member), 'hash-ada', new SessionClient(), new DateTimeImmutable());
        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('SELECT id FROM user_kit_test_member WHERE id = ' . $id . ' FOR UPDATE'));
        $this->shortLockWait();

        try {
            $this->eraser()->erase($member);
            self::fail('the member row is locked by the peer');
        } catch (UserKitStorageFailure $failure) {
            self::assertSame(UserKitStorageFailure::DEFAULT_MESSAGE, $failure->getMessage());
            self::assertSame(1, $failure->retryAfterSeconds());
            self::assertSame(ConflictCode::RetrySafe, $failure->conflictCode(), 'nothing committed: CONFLICT_001');
        }
        $this->assertHealthy();
        self::assertSame('OK', $peer->execute('ROLLBACK'));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM user_kit_test_member WHERE password = 'erased'"));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM user_kit_session WHERE revoked_at IS NOT NULL'), 'the revocation was rolled back too');
        self::assertSame(['member', 'sessions'], $this->eraser()->erase($this->reloaded($id)));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM user_kit_test_member WHERE password = 'erased'"));
    }

    public function testAParallelFirstEpochBumpDoesNotFailTheErasure(): void
    {
        $this->boot(['personal_data' => ['enabled' => true], 'sessions' => ['enabled' => true, 'remember_me_epoch' => ['enabled' => true]]]);
        $member = $this->member('ada@example.com');
        $owner  = $this->owner($member);
        $peer   = $this->otherSession();
        // A parallel "sign out everywhere" of the same user stores the first epoch row and commits a moment later: the
        // erasure's own first insert waits for it, then loses the unique key race.
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('INSERT INTO user_kit_session_epoch (id, user_type, user_id, epoch_at) VALUES (900, ' . $this->connection()->quote($owner->type) . ', ' . $this->connection()->quote($owner->id) . ", '2020-01-01 00:00:00')"));
        $peer->send($this->sleepStatement(1.0));
        $peer->send('COMMIT');

        // Up to 1.18: PostgreSQL aborted the erasure's transaction (25P02 on the next statement); MySQL re-read the row
        // in the transaction's snapshot, missed it and refused the bump as a look-alike collision.
        self::assertSame(['member', 'sessions'], $this->eraser()->erase($member));

        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM user_kit_test_member WHERE password = 'erased'"));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM user_kit_session_epoch'));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM user_kit_session_epoch WHERE epoch_at < '2026-01-01 00:00:00'"), 'moved forward by the erasure');
        $this->assertHealthy();
    }

    private function eraser(): PersonalDataEraser
    {
        $eraser = $this->container()->get('load.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);

        return $eraser;
    }

    private function owner(Member $member): SessionUser
    {
        $owner = $this->get(UserSessionManager::class)->owner($member);
        self::assertInstanceOf(SessionUser::class, $owner);

        return $owner;
    }

    private function reloaded(int $id): Member
    {
        $this->em()->clear();

        return $this->em()->find(Member::class, $id) ?? self::fail('The member is gone.');
    }
}
