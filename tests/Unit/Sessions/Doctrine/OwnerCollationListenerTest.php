<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Sessions\Doctrine\OwnerCollationListener;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * House rule LQ: the owner columns get a binary NO PAD collation on MySQL / MariaDB, nothing elsewhere.
 */
final class OwnerCollationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'MariaDB' => [new MariaDBPlatform(), 'utf8mb4_nopad_bin'];
        yield 'MySQL 8.0' => [new MySQL80Platform(), 'utf8mb4_0900_bin'];
        yield 'MySQL 5.7' => [new MySQLPlatform(), 'utf8mb4_bin'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), null];
    }

    #[DataProvider('platforms')]
    public function testCollationPerPlatform(AbstractPlatform $platform, ?string $collation): void
    {
        self::assertSame($collation, OwnerCollationListener::collationFor($platform));
    }

    /**
     * @return iterable<string, array{class-string, bool, string|null}>
     */
    public static function cases(): iterable
    {
        yield 'session table, editor' => [UserSession::class, true, 'default'];
        yield 'session table, in place' => [UserSession::class, false, null];
        yield 'epoch table, editor, named manager' => [UserSessionEpoch::class, true, 'sessions'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('cases')]
    public function testTheOwnerColumnsAreCollated(string $class, bool $editor, ?string $managerName): void
    {
        $table = $this->table();
        $args  = $this->args($class, $table);
        if (!$editor) {
            // Older DBAL: the column is changed in place.
            self::assertNull(OwnerCollationListener::collate($table, 'user_id', 'utf8mb4_0900_bin', false));
            self::assertSame('utf8mb4_0900_bin', $table->getColumn('user_id')->getPlatformOption('collation'));

            return;
        }
        (new OwnerCollationListener($this->registry(new MySQL80Platform(), $managerName), $managerName))->postGenerateSchemaTable($args);
        $result = $args->getClassTable();
        foreach (['user_type', 'user_id'] as $column) {
            self::assertSame('utf8mb4_0900_bin', $result->getColumn($column)->getPlatformOption('collation'));
            self::assertSame('utf8mb4', $result->getColumn($column)->getPlatformOption('charset'));
        }
        self::assertFalse($result->getColumn('session_hash')->hasPlatformOption('collation'));
    }

    public function testOtherEntitiesAndExactPlatformsAreUntouched(): void
    {
        foreach ([[stdClass::class, new MySQL80Platform()], [UserSession::class, new PostgreSQLPlatform()]] as [$class, $platform]) {
            $table = $this->table();
            $args  = $this->args($class, $table);
            (new OwnerCollationListener($this->registry($platform, null)))->postGenerateSchemaTable($args);
            self::assertSame($table, $args->getClassTable());
            self::assertFalse($table->getColumn('user_id')->hasPlatformOption('collation'));
        }
    }

    public function testNoOrmManagerIsANoOp(): void
    {
        $table    = $this->table();
        $args     = $this->args(UserSession::class, $table);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        (new OwnerCollationListener($registry))->postGenerateSchemaTable($args);
        self::assertFalse($args->getClassTable()->getColumn('user_id')->hasPlatformOption('collation'));
    }

    private function table(): Table
    {
        $table = new Table('user_kit_session');
        foreach (['user_type', 'user_id', 'session_hash'] as $column) {
            $table->addColumn($column, Types::STRING, ['length' => 191]);
        }

        return $table;
    }

    /**
     * @param class-string $class
     */
    private function args(string $class, Table $table): GenerateSchemaTableEventArgs
    {
        return new GenerateSchemaTableEventArgs(new ClassMetadata($class), new Schema(), $table);
    }

    private function registry(AbstractPlatform $platform, ?string $managerName): ManagerRegistry
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($managerName === null ? $manager : null);
        $registry->method('getManager')->willReturn($manager);

        return $registry;
    }
}
