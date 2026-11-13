<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use DateTimeImmutable;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Tests\Support\QueryCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function array_map;
use function preg_replace;
use function sprintf;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * `sessions.doctrine.exact_owner_collation` (1.17): the host states that the owner columns of `user_kit_session`
 * already carry the binary NO PAD collation of [UPGRADING](docs/UPGRADING.md), so the session list skips the candidate
 * scan it filters in PHP and reads the rows in one query instead of two.
 *
 * Two things are proven here:
 *
 * - the saving is real and stays real (the registry queries of one "My sessions" request are counted with the option
 *   off and on);
 * - the option is fail-closed. The PHP `===` re-check runs in **both** paths, so on a column that still folds — the
 *   host turned the option on without running the `ALTER` — the look-alike rows SQL hands back are dropped and the
 *   owner's list is empty: its user is signed out, never matched to someone else's session.
 */
final class SessionExactOwnerCollationTest extends TransactionalSessionsTestCase
{
    private const TYPE = 'App\\Security\\LdapUser';

    /**
     * The registry queries of one signed-in "My sessions" request, with the option off and on. The saving is exactly
     * one SELECT; asserting the absolute counts too keeps a later change from adding a round trip back.
     */
    public function testTheSessionListCostsOneRegistryQueryLessWithTheOption(): void
    {
        $off = $this->registryQueriesOfTheSessionsPage(false);
        $on  = $this->registryQueriesOfTheSessionsPage(true);

        // Exactly one SELECT per signed-in request on the session list: the candidate scan of `findActive()`.
        self::assertSame(3, $off, 'Without the option: revocation state, candidate scan, entities.');
        self::assertSame(2, $on, 'With the option: revocation state, entities (the candidate scan is gone).');
    }

    /**
     * Fail-closed on a folding column: with the option on and the `ALTER` not run, `Alice` matches `alice` in SQL and
     * the `===` re-check drops the row, in both paths.
     *
     * @param bool $exact `sessions.doctrine.exact_owner_collation`
     */
    #[DataProvider('options')]
    public function testAFoldingColumnFindsNoSessionInsteadOfALookalikesOne(bool $exact): void
    {
        $this->setUpRegistry($exact);
        $this->foldTheOwnerColumns();
        $store = $this->store();
        $now   = new DateTimeImmutable('2026-11-13 10:00:00');
        $since = $now->modify('-1 hour');
        self::assertTrue($store->insert(new SessionUser(self::TYPE, 'alice'), 'h-alice', new SessionClient('Firefox', 'Linux'), $now));

        self::assertSame(1, $this->rowsMatchedInSql('Alice'), 'The column really folds: SQL alone matches the look-alike.');
        foreach (['Alice', 'ALICE', 'álice'] as $lookalike) {
            $owner = new SessionUser(self::TYPE, $lookalike);
            self::assertSame([], $store->findActive($owner, $since), sprintf('"%s" lists no session.', $lookalike));
        }
        self::assertSame(
            ['h-alice'],
            array_map(static fn (UserSession $row): string => $row->getSessionHash(), $store->findActive(new SessionUser(self::TYPE, 'alice'), $since)),
            'The exact owner still finds her own session.',
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function options(): iterable
    {
        yield 'exact_owner_collation off' => [false];
        yield 'exact_owner_collation on' => [true];
    }

    public function testTheOptionIsOffByDefaultAndReadableFromTheStore(): void
    {
        $this->setUpRegistry(null);
        self::assertFalse($this->store()->hasExactOwnerCollation(), 'Default: nobody regresses.');
        $this->kernel?->shutdown();
        $this->setUpRegistry(true);
        self::assertTrue($this->store()->hasExactOwnerCollation());
    }

    private function registryQueriesOfTheSessionsPage(bool $exact): int
    {
        $this->kernel?->shutdown();
        $this->boot(
            ['doctrine' => ['exact_owner_collation' => $exact]],
            services: static function (ContainerConfigurator $c): void {
                $c->services()->set(QueryCounter::class)->public();
                $c->services()->set('test.dbal_query_counter', Middleware::class)->args([service(QueryCounter::class)])->tag('doctrine.middleware');
            },
        );
        $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $this->signIn('alice@example.com', 'Mozilla/5.0 (Linux; Android 15) Chrome/130.0 Mobile Safari/537.36');

        $counter = $this->container()->get(QueryCounter::class);
        self::assertInstanceOf(QueryCounter::class, $counter);
        $table = $this->store()->table();
        $counter->reset();
        $laptop->request('GET', '/account/sessions');
        self::assertSame(200, $laptop->getResponse()->getStatusCode(), $this->body($laptop));
        self::assertStringContainsString('Chrome on Android', $this->body($laptop), 'Both sessions are listed in either path.');

        return $counter->on($table);
    }

    private function setUpRegistry(?bool $exact): void
    {
        $this->setUpDatabase('database', $exact === null ? [] : ['doctrine' => ['exact_owner_collation' => $exact]]);
    }

    /**
     * Puts the owner columns back on a collation that folds case and accents, as a table created before the `ALTER` of
     * UPGRADING has: `COLLATE NOCASE` on SQLite, `utf8mb4_general_ci` on MySQL / MariaDB.
     */
    private function foldTheOwnerColumns(): void
    {
        $connection = $this->connection();
        $platform   = $connection->getDatabasePlatform();
        $table      = $this->store()->table();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %1$s MODIFY user_type VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL, MODIFY user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL',
                $table,
            ));

            return;
        }
        if (!$platform instanceof SQLitePlatform) {
            self::markTestSkipped('A folding owner column is built on SQLite (COLLATE NOCASE) and MySQL / MariaDB only; PostgreSQL compares exactly.');
        }
        // SQLite cannot change a column collation in place: the (empty) table is recreated from its own DDL with
        // `COLLATE NOCASE` on the owner columns, and its indexes are put back.
        $ddl = $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);
        self::assertIsString($ddl);
        $indexes = array_map(strval(...), $connection->fetchFirstColumn('SELECT sql FROM sqlite_master WHERE type = ? AND tbl_name = ? AND sql IS NOT NULL', ['index', $table]));
        $folding = preg_replace('/\b(user_type|user_id) (VARCHAR\(191\) NOT NULL)/', '$1 $2 COLLATE NOCASE', $ddl);
        self::assertIsString($folding);
        self::assertNotSame($ddl, $folding, 'The owner columns of the generated DDL were found: ' . $ddl);
        $connection->executeStatement('DROP TABLE ' . $table);
        $connection->executeStatement($folding);
        foreach ($indexes as $index) {
            $connection->executeStatement($index);
        }
    }

    /**
     * Rows an owner lookup matches in SQL alone (no PHP re-check): 1 on a folding column.
     */
    private function rowsMatchedInSql(string $userId): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->store()->table() . ' WHERE user_type = :type AND user_id = :id',
            ['type' => self::TYPE, 'id' => $userId],
        );
    }
}
