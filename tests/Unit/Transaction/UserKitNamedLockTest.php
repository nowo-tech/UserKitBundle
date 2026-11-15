<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\UserKitBundle\Transaction\UserKitNamedLock;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function random_bytes;
use function str_starts_with;
use function strlen;

final class UserKitNamedLockTest extends TestCase
{
    public function testWithoutServerLocksTheWorkJustRuns(): void
    {
        $connection = $this->connection(new SQLitePlatform());
        $connection->expects($this->never())->method('fetchOne');

        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, UserKitNamedLock::hold($connection, 'k', static fn (): string => $expected));
    }

    public function testMysqlTakesAndReleasesTheLock(): void
    {
        $connection = $this->connection(new MySQL80Platform());
        $sql        = [];
        $connection->method('fetchOne')->willReturnCallback(static function (string $query) use (&$sql): int {
            $sql[] = $query;

            return 1;
        });

        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, UserKitNamedLock::hold($connection, 'k', static fn (): string => $expected));
        self::assertSame(['SELECT GET_LOCK(?, ?)', 'SELECT RELEASE_LOCK(?)'], $sql);
    }

    public function testMysqlGivesUpWhenTheLockIsNotObtained(): void
    {
        $connection = $this->connection(new MySQL80Platform());
        $connection->method('fetchOne')->willReturn(0);

        $this->expectException(UserKitStorageFailure::class);
        UserKitNamedLock::hold($connection, 'k', static fn (): string => 'never');
    }

    public function testAFailingWorkReleasesTheLockAndIsRethrown(): void
    {
        $connection = $this->connection(new MySQL80Platform());
        $connection->expects($this->exactly(2))->method('fetchOne')->willReturn(1);

        $this->expectExceptionMessage('refused');
        UserKitNamedLock::hold($connection, 'k', static fn (): never => throw new RuntimeException('refused'));
    }

    public function testAFailedReleaseClosesTheConnection(): void
    {
        $connection = $this->connection(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnCallback(static fn (string $query): int => str_starts_with($query, 'SELECT GET_LOCK') ? 1 : throw new RuntimeException('gone'));
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('close');

        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, UserKitNamedLock::hold($connection, 'k', static fn (): string => $expected));
    }

    public function testAFailedReleaseInsideTheCallersTransactionIsAFailure(): void
    {
        $connection = $this->connection(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnCallback(static fn (string $query): int => str_starts_with($query, 'SELECT GET_LOCK') ? 1 : throw new RuntimeException('gone'));
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('close');

        $this->expectException(UserKitStorageFailure::class);
        UserKitNamedLock::hold($connection, 'k', static fn (): string => 'done');
    }

    public function testPostgresqlPollsTheAdvisoryLock(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform());
        $answers    = [false, 't', true];
        $sql        = [];
        $connection->method('fetchOne')->willReturnCallback(static function (string $query) use (&$answers, &$sql): mixed {
            $sql[] = $query;

            return array_shift($answers);
        });

        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, UserKitNamedLock::hold($connection, 'k', static fn (): string => $expected));
        self::assertSame(['SELECT pg_try_advisory_lock(?)', 'SELECT pg_try_advisory_lock(?)', 'SELECT pg_advisory_unlock(?)'], $sql);
    }

    public function testPostgresqlGivesUpAfterTheTimeout(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(false);

        $this->expectException(UserKitStorageFailure::class);
        UserKitNamedLock::hold($connection, 'k', static fn (): string => 'never', 0);
    }

    public function testNamesAndKeysAreStable(): void
    {
        self::assertSame(UserKitNamedLock::name('a'), UserKitNamedLock::name('a'));
        self::assertNotSame(UserKitNamedLock::name('a'), UserKitNamedLock::name('b'));
        self::assertLessThanOrEqual(64, strlen(UserKitNamedLock::name('a')));
        self::assertSame(UserKitNamedLock::advisoryKey('a'), UserKitNamedLock::advisoryKey('a'));
        self::assertNotSame(UserKitNamedLock::advisoryKey('a'), UserKitNamedLock::advisoryKey('b'));
    }

    /**
     * @return Connection&MockObject
     */
    private function connection(AbstractPlatform $platform): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }
}
