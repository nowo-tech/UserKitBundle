<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Throwable;

use function hash;
use function hrtime;
use function in_array;
use function substr;
use function unpack;
use function usleep;

/**
 * 1.19.0 (Beacon spec 239): a named lock that serialises one owner's sign-ins against the concurrent-session limit
 * (count, evict, register). Modelled on AuthKit 1.50's lock, with the fixes of its audits QG-M2 and QGD-L2.
 *
 * - MySQL / MariaDB: `GET_LOCK()`; PostgreSQL: `pg_try_advisory_lock()` polled; SQLite and other platforms: no lock
 *   (one writer at a time).
 * - Waits {@see self::TIMEOUT_SECONDS} at most, then {@see UserKitStorageFailure} (503 + Retry-After).
 * - Session locks: taken outside a transaction, released in `finally`. A release that fails (a network error, a
 *   statement timeout, a killed query) may leave the session alive with the lock held: the connection is closed, which
 *   ends the session and drops its locks, so a FrankenPHP worker (kernel reset off) never carries the lock into its next
 *   request. Inside a transaction the caller opened, closing ends that transaction too: the work's result is then not
 *   returned, a {@see UserKitStorageFailure} is thrown instead (the caller must not go on as if its transaction were open).
 * - PgBouncer in transaction or statement pooling mode is not supported for UserKit's connection (the lock and its
 *   release can reach different server sessions); session pooling or a direct connection is. Galera / PXC: one writer
 *   node. ProxySQL keeps the session after `GET_LOCK()`.
 *
 * Stateless (worker safe).
 *
 * @internal
 */
final class UserKitNamedLock
{
    /** Seconds a sign-in waits for the lock before a UserKitStorageFailure. */
    public const TIMEOUT_SECONDS = 5;

    /** Prefix of the lock names (MySQL: 64 characters at most in total). */
    private const PREFIX = 'nowo_user_kit:';

    /** PostgreSQL polling interval (microseconds). */
    private const POLL_MICROSECONDS = 20000;

    /**
     * @template T
     *
     * @param Connection $connection The connection of the registry
     * @param string $key What is locked (the session owner key)
     * @param Closure(): T $work Runs while the lock is held
     * @param int $timeoutSeconds Seconds to wait for the lock
     *
     * @throws UserKitStorageFailure When the lock is not obtained in time, or its release ended the caller's transaction
     *
     * @return T What the work returned
     */
    public static function hold(Connection $connection, string $key, Closure $work, int $timeoutSeconds = self::TIMEOUT_SECONDS): mixed
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $name = self::name($key);
            if ((int) $connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds], [ParameterType::STRING, ParameterType::INTEGER]) !== 1) {
                throw new UserKitStorageFailure(1);
            }
            $release = static fn (): mixed => $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $id = self::advisoryKey($key);
            self::waitForAdvisoryLock($connection, $id, $timeoutSeconds);
            $release = static fn (): mixed => $connection->fetchOne('SELECT pg_advisory_unlock(?)', [$id], [ParameterType::INTEGER]);
        } else {
            return $work();
        }

        try {
            $result = $work();
        } catch (Throwable $failure) {
            self::release($connection, $release);

            throw $failure;
        }
        if (!self::release($connection, $release)) {
            throw new UserKitStorageFailure(1);
        }

        return $result;
    }

    /**
     * Releases the lock; on failure closes the connection (see the class description).
     *
     * @param Closure(): mixed $release
     *
     * @return bool false when closing the connection ended a transaction the caller had open
     */
    private static function release(Connection $connection, Closure $release): bool
    {
        try {
            $release();

            return true;
        } catch (Throwable) {
            $inTransaction = $connection->isTransactionActive();
            $connection->close();

            return !$inTransaction;
        }
    }

    /**
     * The MySQL / MariaDB lock name of a key (64 characters at most).
     */
    public static function name(string $key): string
    {
        return self::PREFIX . substr(hash('sha256', $key), 0, 40);
    }

    /**
     * The signed 64-bit key of a PostgreSQL advisory lock.
     */
    public static function advisoryKey(string $key): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', substr(hash('sha256', self::PREFIX . $key, true), 0, 8));

        return $unpacked[1];
    }

    private static function waitForAdvisoryLock(Connection $connection, int $id, int $timeoutSeconds): void
    {
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        while (!self::isTrue($connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$id], [ParameterType::INTEGER]))) {
            if (hrtime(true) >= $deadline) {
                throw new UserKitStorageFailure(1);
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private static function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
}
