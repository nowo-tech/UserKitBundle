<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

use function sprintf;

/**
 * 1.21.0 (REQ-RUNTIME-003 rule 6): a revocation is several autocommit statements (the remember-me epoch bump, the
 * revocation, the deletion of `forgetOwner()`). When one of them is committed and the next one keeps failing on a lock,
 * the failure is `CONFLICT_002` (no Retry-After: part of the request is committed); when nothing was committed, or
 * inside a transaction of the application, it stays `CONFLICT_001`. The lock wait is simulated on SQLite with a trigger
 * that raises "database is locked" (DBAL: LockWaitTimeoutException).
 */
final class SessionConflictCodeTest extends TransactionalSessionsTestCase
{
    public function testAFailedEpochBumpAfterTheRevocationIsConflict002(): void
    {
        $user = $this->registry(true);
        $this->lock('INSERT', UserSessionEpoch::class);

        $failure = $this->failure(fn (): int => $this->manager()->revokeAll($user));

        self::assertSame(ConflictCode::Committed, $failure->conflictCode());
        self::assertSame(0, $this->live(), 'the revocation is committed');
    }

    public function testAFailedRevocationAfterTheEpochBumpIsConflict002(): void
    {
        $user = $this->registry(true);
        $this->lock('UPDATE', null);

        $failure = $this->failure(fn (): int => $this->manager()->revokeAll($user));

        self::assertSame(ConflictCode::Committed, $failure->conflictCode(), 'the epoch bump is committed');
        self::assertSame(2, $this->live());
    }

    public function testAFailedRevocationWithoutAnEpochIsConflict001(): void
    {
        $user = $this->registry(false);
        $this->lock('UPDATE', null);

        $failure = $this->failure(fn (): int => $this->manager()->revokeAll($user));

        self::assertSame(ConflictCode::RetrySafe, $failure->conflictCode(), 'nothing was committed');
        self::assertTrue($this->causedBy($failure, LockWaitTimeoutException::class));
    }

    public function testAFailedDeletionAfterTheRevocationIsConflict002(): void
    {
        $user = $this->registry(false);
        $this->lock('DELETE', null);
        $owner = $this->manager()->owner($user);
        self::assertInstanceOf(SessionUser::class, $owner);

        $failure = $this->failure(fn (): int => $this->manager()->forgetOwner($owner, $user));

        self::assertSame(ConflictCode::Committed, $failure->conflictCode());
        self::assertSame(0, $this->live());
    }

    public function testAFailedDeletionWithNothingRevokedIsConflict001(): void
    {
        $user = $this->registry(false);
        self::assertSame(2, $this->manager()->revokeAll($user));
        $this->lock('DELETE', null);
        $owner = $this->manager()->owner($user);
        self::assertInstanceOf(SessionUser::class, $owner);

        // Every session is already revoked: forgetOwner() revokes nothing, then its deletion fails.
        $failure = $this->failure(fn (): int => $this->manager()->forgetOwner($owner, $user));

        self::assertSame(ConflictCode::RetrySafe, $failure->conflictCode());
    }

    public function testInsideATransactionOfTheApplicationNothingIsCommittedYet(): void
    {
        $user = $this->registry(true);
        $this->lock('INSERT', UserSessionEpoch::class);
        $connection = $this->connection();
        $connection->beginTransaction();
        $failure = $this->failure(fn (): int => $this->manager()->revokeAll($user));
        $connection->rollBack();

        self::assertSame(ConflictCode::RetrySafe, $failure->conflictCode());
        self::assertSame(2, $this->live(), 'rolled back with the application\'s transaction');
    }

    private function registry(bool $epoch): InMemoryUser
    {
        $this->setUpDatabase('database', $epoch ? ['remember_me_epoch' => ['enabled' => true]] : []);
        if (!$this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The lock wait is simulated with a SQLite trigger.');
        }
        if ($epoch) {
            $classes = [$this->em()->getClassMetadata(UserSessionEpoch::class)];
            $tool    = new SchemaTool($this->em());
            $tool->dropSchema($classes);
            $tool->createSchema($classes);
        }
        $user  = new InMemoryUser('alice', null);
        $owner = $this->manager()->owner($user);
        self::assertInstanceOf(SessionUser::class, $owner);
        foreach (['h-alice-1', 'h-alice-2'] as $hash) {
            self::assertTrue($this->store()->insert($owner, $hash, new SessionClient('Firefox', 'Linux', '192.0.2.0'), new DateTimeImmutable()));
        }

        return $user;
    }

    /**
     * @param class-string|null $entity null for the session table
     */
    private function lock(string $operation, ?string $entity): void
    {
        $table = $entity === null ? $this->store()->table() : $this->em()->getClassMetadata($entity)->getTableName();
        $this->connection()->executeStatement(sprintf("CREATE TRIGGER uk_conflict_lock BEFORE %s ON %s BEGIN SELECT RAISE(ABORT, 'database is locked'); END", $operation, $table));
    }

    /**
     * @param callable(): int $call
     */
    private function failure(callable $call): UserKitStorageFailure
    {
        $failure = null;
        try {
            $call();
        } catch (UserKitStorageFailure $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(UserKitStorageFailure::class, $failure, 'the lock wait surfaces as a UserKitStorageFailure');

        return $failure;
    }

    private function live(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->store()->table() . ' WHERE revoked_at IS NULL');
    }

    private function causedBy(Throwable $failure, string $class): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
