<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Audit IY regression (IY-L1): the touch fallback must not read a transaction's old snapshot. On MySQL/MariaDB
 * `REPEATABLE READ`, inside a host transaction that already read, a plain `SELECT` would see a session revoked since
 * as live. Runs on MySQL and PostgreSQL in the `real-databases` CI job (SQLite has one writer: not reachable).
 */
final class SessionTouchSnapshotTest extends TransactionalSessionsTestCase
{
    public function testARevocationCommittedAfterTheTransactionSnapshotStillFailsTheTouch(): void
    {
        [$hash, $owner] = $this->session();
        $connection     = $this->connection();
        $platform       = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
            // SQLite (class name differs between DBAL 3 and 4) has one writer: a second connection cannot commit here.
            self::markTestSkipped('Needs a second writer: MySQL/MariaDB or PostgreSQL.');
        }

        $connection->beginTransaction();
        try {
            // The first consistent read fixes the snapshot (MySQL REPEATABLE READ).
            $connection->fetchOne('SELECT COUNT(*) FROM ' . $this->store()->table());
            $other = DriverManager::getConnection($connection->getParams());
            $other->executeStatement(
                'UPDATE ' . $this->store()->table() . ' SET revoked_at = :now, revoked_reason = :reason WHERE session_hash = :hash',
                ['now' => new DateTimeImmutable(), 'reason' => 'admin', 'hash' => $hash],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
            $other->close();

            $touched = $this->store()->touch($hash, $owner, $this->clock()->now()->modify('+1 hour'));
        } finally {
            $connection->rollBack();
        }

        self::assertFalse($touched, 'Fail-open: the fallback read the old snapshot and reported the revoked row live.');
    }

    public function testARevocationInAutocommitInTheSameSecondFailsTheTouch(): void
    {
        [$hash, $owner] = $this->session();
        $now            = $this->clock()->now()->modify('+1 hour');

        self::assertTrue($this->store()->touch($hash, $owner, $now));
        $this->store()->markRevoked($hash, $owner, 'admin', $now);
        self::assertFalse($this->store()->touch($hash, $owner, $now));
    }

    public function testTheLockingFallbackStillFindsALiveRowInsideATransaction(): void
    {
        [$hash, $owner] = $this->session();
        $now            = $this->clock()->now()->modify('+1 hour');
        $connection     = $this->connection();

        self::assertTrue($this->store()->touch($hash, $owner, $now));
        $connection->beginTransaction();
        try {
            $connection->fetchOne('SELECT COUNT(*) FROM ' . $this->store()->table());
            // Same second: 0 affected rows on MySQL, so the locking fallback answers.
            $touched = $this->store()->touch($hash, $owner, $now);
        } finally {
            $connection->rollBack();
        }

        self::assertTrue($touched);
    }

    /**
     * @return array{string, SessionUser}
     */
    private function session(): array
    {
        $this->setUpDatabase();
        $alice   = $this->member('alice@example.com');
        $browser = $this->signIn('alice@example.com');
        $hash    = $this->manager()->currentHash($browser->getRequest()->getSession());
        $owner   = $this->manager()->owner($alice);
        self::assertIsString($hash);
        self::assertInstanceOf(SessionUser::class, $owner);

        return [$hash, $owner];
    }
}
