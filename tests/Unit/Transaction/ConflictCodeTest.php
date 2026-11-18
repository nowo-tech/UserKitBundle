<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function json_decode;

/**
 * 1.21.0 (REQ-RUNTIME-003 rule 6): every kind of lock conflict UserKit meets (deadlock, lock wait timeout,
 * serialization failure: MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40P01 / 55P03 / 40001) answers 409 +
 * Retry-After + CONFLICT_001 when nothing was committed, and CONFLICT_002 without Retry-After when part was. Unique
 * violations are never conflicts.
 */
final class ConflictCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, TransientFailureKind}>
     */
    public static function conflicts(): iterable
    {
        yield 'MySQL deadlock 1213' => [new DeadlockException(self::driver('deadlock', 'HY000', 1213), null), TransientFailureKind::Deadlock];
        yield 'PostgreSQL deadlock 40P01' => [new DeadlockException(self::driver('deadlock', '40P01', 7), null), TransientFailureKind::Deadlock];
        yield 'MySQL lock wait 1205' => [new LockWaitTimeoutException(self::driver('lock wait', 'HY000', 1205), null), TransientFailureKind::LockWaitTimeout];
        yield 'PostgreSQL lock_timeout 55P03' => [new LockWaitTimeoutException(self::driver('lock', '55P03', 7), null), TransientFailureKind::LockWaitTimeout];
        yield 'PostgreSQL serialization 40001' => [new DriverException(self::driver('serialization', '40001', 7), null), TransientFailureKind::SerializationFailure];
        yield 'MariaDB snapshot conflict 1020' => [new DriverException(self::driver('Record has changed since last read', 'HY000', 1020), null), TransientFailureKind::SerializationFailure];
    }

    #[DataProvider('conflicts')]
    public function testEveryLockConflictIsConflict001With409AndRetryAfter(Throwable $storage, TransientFailureKind $kind): void
    {
        $failure = $this->statementFailure($storage);

        self::assertSame(ConflictCode::RetrySafe, $failure->conflictCode());
        self::assertFalse($failure->committed());
        $response = $this->answer($failure);
        self::assertSame(409, $response['status']);
        self::assertSame('1', $response['retryAfter']);
        self::assertSame('CONFLICT_001', $response['code']);

        $committed = UserKitStorageFailure::afterCommit($failure);
        self::assertSame(ConflictCode::Committed, $committed->conflictCode());
        self::assertTrue($committed->committed());
        $conflict = $committed->getPrevious();
        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertSame($kind, $conflict->kind);
        self::assertSame($storage, $conflict->getPrevious(), 'the storage error stays in the chain');
        $response = $this->answer($committed);
        self::assertSame(409, $response['status']);
        self::assertNull($response['retryAfter']);
        self::assertSame('CONFLICT_002', $response['code']);
    }

    public function testAfterCommitIsIdempotentAndKeepsAFailureWithoutStorageError(): void
    {
        $lock      = new UserKitStorageFailure(1);
        $committed = UserKitStorageFailure::afterCommit($lock);

        self::assertSame($committed, UserKitStorageFailure::afterCommit($committed));
        $conflict = $committed->getPrevious();
        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertSame(TransientFailureKind::Retryable, $conflict->kind, 'a lock UserKit waited for (no database error)');
        self::assertSame($lock, $conflict->getPrevious());
        self::assertNull($committed->retryAfterSeconds());
    }

    public function testAUniqueViolationIsNeverAConflict(): void
    {
        $unique = new UniqueConstraintViolationException(self::driver('duplicate', '23000', 1062), null);

        self::assertSame($unique, $this->caught($unique));
    }

    private function statementFailure(Throwable $storage): UserKitStorageFailure
    {
        $failure = $this->caught($storage);
        self::assertInstanceOf(UserKitStorageFailure::class, $failure);
        self::assertSame($storage, $failure->getPrevious());

        return $failure;
    }

    private function caught(Throwable $storage): Throwable
    {
        $statement = static function () use ($storage): never {
            throw $storage;
        };
        $failure = null;
        try {
            $this->runner()->statement($this->connection(), $statement);
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        return $failure;
    }

    /**
     * @return array{status: int, retryAfter: string|null, code: mixed}
     */
    private function answer(UserKitStorageFailure $failure): array
    {
        $request = Request::create('/api/me', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $event   = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new StorageFailureSubscriber(null, null, true))->onException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($body['code'] ?? null, $response->headers->get('X-Conflict-Code'), 'the header carries the same code');

        return ['status' => $response->getStatusCode(), 'retryAfter' => $response->headers->get('Retry-After'), 'code' => $body['code'] ?? null];
    }

    private function runner(): UserKitTransactionRunner
    {
        return new UserKitTransactionRunner($this->createMock(ManagerRegistry::class), null, 1, 1, 0);
    }

    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        return $connection;
    }

    private static function driver(string $message, string $sqlState, int $code): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }
}
