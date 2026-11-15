<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function getenv;
use function str_repeat;

/**
 * House rule LQ (1.18): the default pointer store matches subject ids byte for byte. On MySQL / MariaDB the generated
 * table has a binary collation (`abc` and `ABC` are two subjects); on a table left with a folding collation, `ABC`
 * neither reads nor overwrites `abc`'s image (the second write fails on the unique key: fail closed). Runs on SQLite,
 * or on `USER_KIT_TEST_DB_URL` (MySQL, MariaDB and PostgreSQL in CI).
 */
final class PointerLookalikeTest extends OwnerImagesKernelTestCase
{
    /**
     * @return iterable<string, array{string|null}>
     */
    public static function collations(): iterable
    {
        yield 'schema tool' => [null];
        yield 'utf8mb4_unicode_ci (a folding host table)' => ['utf8mb4_unicode_ci'];
    }

    #[DataProvider('collations')]
    public function testALookalikeSubjectNeverReachesAnotherOnesImage(?string $collation): void
    {
        $this->boot(variant: (string) $collation);
        $connection = $this->em()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        match (getenv('USER_KIT_TEST_DB_PLATFORM')) {
            'mysql'      => self::assertInstanceOf(AbstractMySQLPlatform::class, $platform),
            'postgresql' => self::assertInstanceOf(PostgreSQLPlatform::class, $platform),
            default      => self::assertInstanceOf(SQLitePlatform::class, $platform),
        };
        $folding = $collation !== null && $platform instanceof AbstractMySQLPlatform;
        if ($folding) {
            $connection->executeStatement('ALTER TABLE ' . DbalOwnerImagePointerStore::TABLE . ' MODIFY subject_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE ' . $collation . ' NOT NULL');
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            $expected = $platform instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
            self::assertSame($expected, $connection->fetchOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'subject_id'", [DbalOwnerImagePointerStore::TABLE]));
        }
        $store = $this->service(DbalOwnerImagePointerStore::class);

        $store->set('avatar', 'abc', 'file-abc');
        $failed = false;
        try {
            $store->set('avatar', 'ABC', 'file-upper');
        } catch (Throwable) {
            $failed = true;
        }
        self::assertSame($folding, $failed, 'a folding unique key refuses the look-alike (fail closed)');
        self::assertSame(['abc' => 'file-abc'], $store->find('avatar', ['abc']));
        self::assertSame($folding ? [] : ['ABC' => 'file-upper'], $store->find('avatar', ['ABC']));
        self::assertSame([], $store->find('avatar', ['Abc']));

        $store->set('avatar', 'Abc', null);
        self::assertSame(['abc' => 'file-abc'], $store->find('avatar', ['abc']), 'clearing a look-alike leaves abc');
        $store->set('avatar', 'abc', 'file-abc-2');
        self::assertSame(['abc' => 'file-abc-2'], $store->find('avatar', ['abc', 'abc']));
        self::assertSame([], $store->find('avatar', ['a b', '']));
        self::assertSame([], $store->find('Avatar!', ['abc']));
        $store->set('avatar', 'abc', null);
        self::assertSame([], $store->find('avatar', ['abc']));
    }

    public function testMalformedWritesAreRefused(): void
    {
        $this->boot();
        $store = $this->service(DbalOwnerImagePointerStore::class);
        foreach ([['Bad!', 'abc', 'f'], ['avatar', 'a b', 'f'], ['avatar', 'abc', ''], ['avatar', 'abc', str_repeat('f', 65)]] as [$kind, $subject, $file]) {
            try {
                $store->set($kind, $subject, $file);
                self::fail('accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
