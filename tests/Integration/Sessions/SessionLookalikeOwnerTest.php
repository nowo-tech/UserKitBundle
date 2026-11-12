<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\DoctrineSessionEpochStore;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_keys;
use function array_map;
use function array_values;
use function in_array;
use function md5;
use function sort;
use function sprintf;

/**
 * House rule LQ (look-alike owners): the session registry and the remember-me epoch match their owner exactly.
 *
 * On MySQL / MariaDB the default collations fold case and accents (`Alice` = `alice` = `álice`) and the PAD SPACE ones
 * also ignore trailing spaces (`alice ` = `alice`). Before 1.16.1 every owner lookup was SQL only, so with string user
 * ids (`getUserIdentifier()` of a non-Doctrine user, or a Doctrine string id) the session list, the export, the count,
 * revoke-all, erase and the epoch of `alice` also reached the look-alike owners.
 *
 * `$collation` forces the owner columns back to a host collation (a table created before 1.16.1 and not migrated):
 * the PHP re-checks and the exact SQL predicate must hold there too. Without it the table is the one the schema tool
 * generates (binary NO PAD on MySQL / MariaDB). Runs on SQLite, or on `USER_KIT_TEST_DB_URL` (MySQL / MariaDB in CI).
 */
final class SessionLookalikeOwnerTest extends TransactionalSessionsTestCase
{
    private const TYPE = 'App\\Security\\LdapUser';

    /** The owner under test and its look-alikes (case, accent, trailing space, two trailing spaces). */
    private const OWNERS = ['alice' => 'h-alice', 'Alice' => 'h-upper', 'álice' => 'h-accent', 'alice ' => 'h-pad', 'ALICE  ' => 'h-pad2'];

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function collations(): iterable
    {
        yield 'schema tool' => [null];
        yield 'utf8mb4_0900_ai_ci / uca1400 (host default)' => ['default'];
        yield 'utf8mb4_unicode_ci' => ['utf8mb4_unicode_ci'];
        yield 'utf8mb4_general_ci' => ['utf8mb4_general_ci'];
        yield 'utf8mb4_bin' => ['utf8mb4_bin'];
    }

    #[DataProvider('collations')]
    public function testTheRegistryOfAnOwnerNeverReachesItsLookalikes(?string $collation): void
    {
        $this->setUpRegistry($collation);
        $store = $this->store();
        $now   = new DateTimeImmutable('2026-11-12 10:00:00');
        foreach (self::OWNERS as $id => $hash) {
            if (SessionUser::isUsable($id)) {
                self::assertTrue($store->insert(new SessionUser(self::TYPE, $id), $hash, $this->client(), $now), sprintf('Row of "%s" inserted.', $id));
                continue;
            }
            // Padded ids are refused since 1.16.1: these rows stand for ones written by an earlier release.
            $this->connection()->insert($store->table(), [
                'public_id'  => md5($hash), 'user_type' => self::TYPE, 'user_id' => $id, 'session_hash' => $hash, 'browser' => 'Firefox', 'os' => 'Linux',
                'created_at' => $now, 'last_seen_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'last_seen_at' => Types::DATETIME_IMMUTABLE]);
        }
        $alice = new SessionUser(self::TYPE, 'alice');
        $since = $now->modify('-1 hour');

        self::assertSame(['h-alice'], array_map(static fn (UserSession $row): string => $row->getSessionHash(), $store->findActive($alice, $since)), 'Session list');
        self::assertSame(1, $store->countActive($alice, $since), 'Count');
        self::assertSame(0, $store->countActive($alice, $since, 'h-alice'), 'Count without the current session');
        self::assertSame([], $store->oldestActive($alice, $since, 'h-alice', 10), 'Eviction candidates');
        self::assertCount(1, $store->allFor($alice), 'Personal data export');
        self::assertSame(['h-alice'], array_keys($store->statesOf($alice, array_values(self::OWNERS))), 'States of parked sessions');
        self::assertNull($store->state('h-upper', $alice), 'State of a look-alike\'s session');
        self::assertFalse($store->touch('h-pad', $alice, $now), 'Touch of a look-alike\'s session');
        self::assertFalse($store->deleteLiveRow('h-accent', $alice), 'Delete of a look-alike\'s live row');
        $store->markRevoked('h-upper', $alice, RevocationReason::SignedOut->value, $now);
        $store->deleteRow('h-pad2', $alice);
        $store->rekey('h-pad', 'h-moved', $alice);
        self::assertSame($this->expectedLive(), $this->liveHashes(), 'Hash-bound writes left the look-alikes alone');

        self::assertSame(['h-alice'], array_column($store->revoke($alice, RevocationReason::Everywhere, $now), 'hash'), 'Revoke all');
        self::assertSame($this->expectedLive(['h-alice']), $this->liveHashes(), 'Only alice was signed out');
        self::assertSame(1, $store->deleteFor($alice), 'Erasure');
        self::assertSame(0, $store->deleteFor($alice), 'Nothing left to erase');
        self::assertSame(4, $this->allRows(), 'The look-alikes\' rows survive the erasure');
    }

    #[DataProvider('collations')]
    public function testTheEpochOfAnOwnerIsNeitherReadNorMovedByALookalike(?string $collation): void
    {
        $this->setUpRegistry($collation, true);
        $epochs = $this->container()->get(DoctrineSessionEpochStore::class);
        self::assertInstanceOf(DoctrineSessionEpochStore::class, $epochs);
        $alice = new SessionUser(self::TYPE, 'alice');
        $epochs->bump($alice, new DateTimeImmutable('2026-11-12 10:00:00 UTC'));

        foreach (['Alice', 'álice', 'ALICE'] as $id) {
            self::assertNull($epochs->epoch(new SessionUser(self::TYPE, $id)), sprintf('"%s" does not read alice\'s epoch.', $id));
            $epochs->forget(new SessionUser(self::TYPE, $id));
        }
        self::assertSame((new DateTimeImmutable('2026-11-12 10:00:00 UTC'))->getTimestamp(), $epochs->epoch($alice), 'A look-alike\'s forget left alice\'s epoch');

        $folds = $collation !== null && $this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform && $collation !== 'utf8mb4_bin';
        try {
            $epochs->bump(new SessionUser(self::TYPE, 'Alice'), new DateTimeImmutable('2026-11-12 11:00:00 UTC'));
            self::assertFalse($folds, 'A folding unique key refuses the look-alike\'s epoch row.');
            self::assertSame((new DateTimeImmutable('2026-11-12 11:00:00 UTC'))->getTimestamp(), $epochs->epoch(new SessionUser(self::TYPE, 'Alice')));
        } catch (LogicException $e) {
            self::assertTrue($folds, $e->getMessage());
            self::assertStringContainsString('UPGRADING', $e->getMessage());
        }
        self::assertSame((new DateTimeImmutable('2026-11-12 10:00:00 UTC'))->getTimestamp(), $epochs->epoch($alice), 'A look-alike\'s bump never moves alice\'s epoch');
    }

    public function testTheGeneratedSchemaUsesABinaryNoPadCollationOnMysql(): void
    {
        $this->setUpRegistry(null, true);
        $platform = $this->connection()->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL / MariaDB only: the other platforms compare exactly.');
        }
        foreach ([$this->store()->table(), $this->em()->getClassMetadata(UserSessionEpoch::class)->getTableName()] as $table) {
            $rows = $this->connection()->fetchAllKeyValue(
                'SELECT column_name, collation_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN (\'user_type\', \'user_id\')',
                [$table],
            );
            self::assertCount(2, $rows);
            foreach ($rows as $column => $name) {
                self::assertContains($name, ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin'], sprintf('%s.%s', $table, $column));
            }
        }
    }

    private function setUpRegistry(?string $collation, bool $epoch = false): void
    {
        $this->setUpDatabase('database', $epoch ? ['remember_me_epoch' => ['enabled' => true]] : []);
        $em = $this->em();
        if ($epoch) {
            $classes = [$em->getClassMetadata(UserSessionEpoch::class)];
            $tool    = new SchemaTool($em);
            $tool->dropSchema($classes);
            $tool->createSchema($classes);
        }
        if ($collation === null) {
            return;
        }
        if (!$this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Host collations exist on MySQL / MariaDB only.');
        }
        $tables = [$this->store()->table()];
        if ($epoch) {
            $tables[] = $em->getClassMetadata(UserSessionEpoch::class)->getTableName();
        }
        $collate = $collation === 'default' ? '' : ' COLLATE ' . $collation;
        foreach ($tables as $table) {
            $this->connection()->executeStatement(sprintf(
                'ALTER TABLE %s MODIFY user_type VARCHAR(191) CHARACTER SET utf8mb4%2$s NOT NULL, MODIFY user_id VARCHAR(191) CHARACTER SET utf8mb4%2$s NOT NULL',
                $table,
                $collate,
            ));
        }
    }

    private function client(): SessionClient
    {
        return new SessionClient('Firefox', 'Linux', '192.0.2.0');
    }

    /**
     * @param list<string> $revoked hashes expected revoked
     *
     * @return list<string>
     */
    private function expectedLive(array $revoked = []): array
    {
        $live = [];
        foreach (self::OWNERS as $hash) {
            if (!in_array($hash, $revoked, true)) {
                $live[] = $hash;
            }
        }
        sort($live);

        return $live;
    }

    /**
     * @return list<string>
     */
    private function liveHashes(): array
    {
        $hashes = array_map(strval(...), $this->connection()->fetchFirstColumn('SELECT session_hash FROM ' . $this->store()->table() . ' WHERE revoked_at IS NULL'));
        sort($hashes);

        return $hashes;
    }
}
