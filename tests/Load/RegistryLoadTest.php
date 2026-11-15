<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Load\Support\LoadTestCase;

/**
 * 1.19.0 (Beacon spec 239), two sessions on MySQL 8.4 / PostgreSQL 16: the session registry's autocommit writes are
 * retried after a lock wait (up to 1.18 a raw lock wait timeout, a 500 on whatever page the user opened), and its
 * insert inside a transaction runs in a savepoint, so a lost race no longer aborts a PostgreSQL transaction.
 */
final class RegistryLoadTest extends LoadTestCase
{
    public function testATouchThatWaitedTooLongForTheRowIsRetried(): void
    {
        $this->boot(['sessions' => ['enabled' => true]]);
        $owner = $this->owner();
        $store = $this->get(UserSessionStore::class);
        self::assertTrue($store->insert($owner, 'hash-ada', new SessionClient(), new DateTimeImmutable('2026-11-01 10:00:00')));
        $peer = $this->otherSession();
        // A long transaction holds the row a little longer than the test's lock wait (1 s), then commits.
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute("SELECT id FROM user_kit_session WHERE session_hash = 'hash-ada' FOR UPDATE"));
        $peer->send($this->sleepStatement(1.5));
        $peer->send('COMMIT');
        $this->shortLockWait();

        self::assertTrue($store->touch('hash-ada', $owner, new DateTimeImmutable('2026-11-01 11:00:00')), 'the second attempt got the row');

        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM user_kit_session WHERE last_seen_at = '2026-11-01 11:00:00'"));
        $this->assertHealthy();
    }

    public function testAnInsertThatLosesTheRaceInsideATransactionLeavesItUsable(): void
    {
        $this->boot(['sessions' => ['enabled' => true]]);
        $owner = $this->owner();
        $peer  = $this->otherSession();
        // A parallel first request of the same session registers it and commits a moment later.
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute("INSERT INTO user_kit_session (id, public_id, browser, os, user_type, user_id, session_hash, created_at, last_seen_at) VALUES (900, 'peer', 'other', 'other', " . $this->connection()->quote($owner->type) . ', ' . $this->connection()->quote($owner->id) . ", 'hash-ada', '2026-11-01 10:00:00', '2026-11-01 10:00:00')"));
        $peer->send($this->sleepStatement(1.0));
        $peer->send('COMMIT');

        $connection = $this->connection();
        $connection->beginTransaction();
        $inserted = $this->get(UserSessionStore::class)->insert($owner, 'hash-ada', new SessionClient(), new DateTimeImmutable());
        // Up to 1.18 PostgreSQL had aborted the transaction: 25P02 here.
        $count = (int) $connection->fetchOne("SELECT COUNT(*) FROM user_kit_session WHERE session_hash = 'hash-ada'");
        $connection->commit();
        self::assertFalse($inserted, 'the peer won');
        self::assertSame(1, $count);
        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame('peer', $this->connection()->fetchOne('SELECT public_id FROM user_kit_session'));
        $this->assertHealthy();
    }

    private function owner(): SessionUser
    {
        $owner = $this->get(UserSessionManager::class)->owner($this->member('ada@example.com'));
        self::assertInstanceOf(SessionUser::class, $owner);

        return $owner;
    }
}
