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
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\Pointer\PointerCollationListener;
use Nowo\UserKitBundle\Sessions\Doctrine\OwnerCollationListener;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Tests\Support\SchemaShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function array_map;
use function dirname;

/**
 * House rule LQ: the owner columns (and the owner image pointer's kind / subject_id) get a binary NO PAD collation on
 * MySQL / MariaDB, nothing elsewhere. Audit XI (1.22.1): applied on postGenerateSchema, keeping every foreign key and
 * index of every table, on every DBAL / ORM (this test also runs on DBAL 4.3 + ORM 3.6 and on DBAL 3.8 in CI).
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
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function connections(): iterable
    {
        yield 'MariaDB 11.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.4.0-MariaDB'], 'utf8mb4_nopad_bin'];
        yield 'MySQL 8.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], 'utf8mb4_0900_bin'];
        yield 'MySQL 5.7' => [['driver' => 'pdo_mysql', 'serverVersion' => '5.7.44'], 'utf8mb4_bin'];
        yield 'SQLite' => [['driver' => 'pdo_sqlite', 'memory' => true], null];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('connections')]
    public function testTheColumnsAreCollatedAndEveryForeignKeyAndIndexIsKept(array $params, ?string $collation): void
    {
        if ($params['driver'] !== 'pdo_sqlite' && !(new ReflectionMethod(Connection::class, 'getServerVersion'))->isPublic()) {
            self::markTestSkipped('doctrine/dbal 3 reads the server while it generates a MySQL / PostgreSQL schema.');
        }
        $with    = SchemaShape::entityManager($params, [new OwnerCollationListener(), new PointerCollationListener()]);
        $without = SchemaShape::entityManager($params);
        $schema  = SchemaShape::generate($with);

        self::assertSame(SchemaShape::of(SchemaShape::generate($without)), SchemaShape::of($schema));
        foreach ([UserSession::class => OwnerCollationListener::COLUMNS, UserSessionEpoch::class => OwnerCollationListener::COLUMNS, OwnerImagePointer::class => PointerCollationListener::COLUMNS] as $class => $columns) {
            $table = $schema->getTable($with->getClassMetadata($class)->getTableName());
            foreach ($columns as $column) {
                self::assertSame($collation, $table->getColumn($column)->getPlatformOptions()['collation'] ?? null, $class . ' ' . $column);
                self::assertSame($collation === null ? null : 'utf8mb4', $table->getColumn($column)->getPlatformOptions()['charset'] ?? null);
            }
        }
        self::assertArrayNotHasKey('collation', $schema->getTable($with->getClassMetadata(UserSession::class)->getTableName())->getColumn('session_hash')->getPlatformOptions());
    }

    public function testAManagerWithoutTheBundleEntitiesIsLeftAlone(): void
    {
        $em     = SchemaShape::entityManager(['driver' => 'pdo_sqlite', 'memory' => true], [new OwnerCollationListener(), new PointerCollationListener()], [dirname(__DIR__, 3) . '/Unit/Transaction/Fixtures']);
        $schema = SchemaShape::generate($em);

        self::assertNotSame([], $schema->getTables());
        self::assertNotContains('user_kit_session', array_map(static fn (Table $table): string => $table->getName(), $schema->getTables()));
    }

    /**
     * The helper keeps a foreign key and an index starting on the collated table (none of the bundle tables has one;
     * a table replaced on postGenerateSchemaTable lost them on ORM 3.7–3.8 with DBAL 4.5+), and skips unknown columns.
     */
    public function testCollatingKeepsTheForeignKeysAndIndexesOfTheTable(): void
    {
        $schema = new Schema();
        $parent = $schema->createTable('parent');
        $parent->addColumn('id', Types::INTEGER);
        $parent->setPrimaryKey(['id']);
        $child = $schema->createTable('child');
        $child->addColumn('id', Types::INTEGER);
        $child->addColumn('parent_id', Types::INTEGER);
        $child->addColumn('user_id', Types::STRING, ['length' => 191]);
        $child->setPrimaryKey(['id']);
        $child->addIndex(['user_id'], 'idx_owner');
        $child->addForeignKeyConstraint('parent', ['parent_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_parent');
        $before = SchemaShape::of($schema);
        $args   = new GenerateSchemaEventArgs(SchemaShape::entityManager(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']), $schema);

        OwnerCollationListener::collateSchema($args, ['child'], ['missing'], 'utf8mb4_bin');
        self::assertSame($schema, $args->getSchema(), 'Nothing to collate: the schema is kept.');

        OwnerCollationListener::collateSchema($args, ['child'], ['user_id', 'missing'], 'utf8mb4_bin');
        self::assertSame($before, SchemaShape::of($args->getSchema()));
        self::assertContains('parent_id->parent(id) CASCADE', SchemaShape::of($args->getSchema())['child']['fks']);
        self::assertSame('utf8mb4_bin', $args->getSchema()->getTable('child')->getColumn('user_id')->getPlatformOptions()['collation'] ?? null);
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool}>
     */
    public static function apiCombinations(): iterable
    {
        yield 'DBAL 4.5, ORM 3.7' => [true, true, true, true];
        yield 'DBAL 4.3/4.4 (no Schema::edit), ORM 3.7' => [true, false, true, false];
        yield 'DBAL 4.5, ORM 3.0-3.6 (no setSchema)' => [true, true, false, false];
        yield 'DBAL 4.3/4.4, ORM 3.0-3.6' => [true, false, false, false];
        yield 'DBAL 3.x / 4.0-4.2, ORM 3.7' => [false, false, true, false];
        yield 'DBAL 3.x / 4.0-4.2, ORM 2.x' => [false, false, false, false];
        yield 'Schema::edit only (impossible, but never the editor)' => [false, true, true, false];
    }

    #[DataProvider('apiCombinations')]
    public function testTheEditorPathNeedsEveryApi(bool $tableEdit, bool $schemaEdit, bool $setSchema, bool $expected): void
    {
        self::assertSame($expected, OwnerCollationListener::editorSupported($tableEdit, $schemaEdit, $setSchema));
    }

    public function testTheInPlacePathKeepsTheSameTable(): void
    {
        $table = $this->table();
        self::assertNull(OwnerCollationListener::collate($table, 'user_type', 'utf8mb4_bin', false));
        self::assertSame('utf8mb4', $table->getColumn('user_type')->getPlatformOption('charset'));
        self::assertSame('utf8mb4_bin', $table->getColumn('user_type')->getPlatformOption('collation'));
    }

    public function testTheFormerTableEventDoesNothing(): void
    {
        $table = $this->table();
        $args  = new GenerateSchemaTableEventArgs(new ClassMetadata(UserSession::class), new Schema(), $table);
        (new OwnerCollationListener())->postGenerateSchemaTable($args);
        (new PointerCollationListener())->postGenerateSchemaTable($args);

        self::assertSame($table, $args->getClassTable());
        self::assertFalse($table->getColumn('user_id')->hasPlatformOption('collation'));
    }

    private function table(): Table
    {
        $table = new Table('user_kit_session');
        foreach (['user_type', 'user_id', 'session_hash'] as $column) {
            $table->addColumn($column, Types::STRING, ['length' => 191]);
        }

        return $table;
    }
}
