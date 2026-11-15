<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures\Note;
use Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures\RunnerDatabase;
use Nowo\UserKitBundle\Transaction\UserKitRows;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UserKitRowsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE t (k VARCHAR(10) NOT NULL UNIQUE)');
    }

    public function testOutsideATransactionALostRaceIsFalse(): void
    {
        self::assertTrue(UserKitRows::insert($this->connection, 't', ['k' => 'a']));
        self::assertFalse(UserKitRows::insert($this->connection, 't', ['k' => 'a']));
    }

    public function testInsideATransactionALostRaceRollsBackToItsSavepointOnly(): void
    {
        $this->connection->beginTransaction();
        self::assertTrue(UserKitRows::insert($this->connection, 't', ['k' => 'a']));
        self::assertFalse(UserKitRows::insert($this->connection, 't', ['k' => 'a']));
        self::assertTrue(UserKitRows::insert($this->connection, 't', ['k' => 'b']));
        $this->connection->commit();

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM t'));
    }

    public function testInsideATransactionAnotherFailureIsRethrownAfterItsSavepoint(): void
    {
        $this->connection->beginTransaction();
        UserKitRows::insert($this->connection, 't', ['k' => 'a']);
        try {
            UserKitRows::insert($this->connection, 't', ['k' => null]);
            self::fail('NOT NULL');
        } catch (NotNullConstraintViolationException) {
        }
        $this->connection->commit();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM t'));
    }

    public function testASequenceIdIsFetchedOnlyForSequenceGeneratedEntities(): void
    {
        $em       = RunnerDatabase::entityManager();
        $identity = $em->getClassMetadata(Note::class);
        self::assertSame([], UserKitRows::sequenceId($em->getConnection(), $identity));

        $sequence = clone $identity;
        $sequence->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_SEQUENCE);
        $sequence->setSequenceGeneratorDefinition(['sequenceName' => 'runner_note_id_seq', 'allocationSize' => '1', 'initialValue' => '1']);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects($this->once())->method('fetchOne')->with("SELECT NEXTVAL('runner_note_id_seq')")->willReturn('7');

        self::assertSame(['id' => 7], UserKitRows::sequenceId($connection, $sequence));
    }

    public function testAFailingSavepointRollbackNeverHidesTheOriginalFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('insert')->willThrowException(new RuntimeException('deadlock'));
        $connection->method('rollbackSavepoint')->willThrowException(new RuntimeException('1305'));

        $this->expectExceptionMessage('deadlock');
        UserKitRows::insert($connection, 't', ['k' => 'a']);
    }
}
