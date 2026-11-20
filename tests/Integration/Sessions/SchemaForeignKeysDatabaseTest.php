<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\Pointer\PointerCollationListener;
use Nowo\UserKitBundle\Sessions\Doctrine\OwnerCollationListener;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Tests\Support\SchemaShape;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function getenv;
use function is_string;
use function method_exists;
use function str_starts_with;

/**
 * Audit XI (1.22.1) on the database of `USER_KIT_TEST_DB_URL` (CI job `real-databases`: MySQL 8.4, MariaDB 11.4,
 * PostgreSQL), else in-memory SQLite: the session, epoch and owner image tables created with the collation listeners
 * have every foreign key and index of the schema generated without them, and on MySQL / MariaDB the owner columns have
 * the exact collation.
 */
final class SchemaForeignKeysDatabaseTest extends TestCase
{
    private ?EntityManagerInterface $em = null;

    protected function tearDown(): void
    {
        $this->em?->getConnection()->close();
        $this->em = null;
    }

    public function testTheCreatedTablesKeepEveryForeignKeyAndIndex(): void
    {
        $url    = getenv('USER_KIT_TEST_DB_URL');
        $params = is_string($url) && $url !== ''
            ? (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url)
            : ['driver' => 'pdo_sqlite', 'memory' => true];
        $this->em = $em = SchemaShape::entityManager($params, [new OwnerCollationListener(), new PointerCollationListener()]);
        $classes  = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);

        /** @var array<non-empty-string, array{fks: list<string>, indexes: list<string>}> $expected */
        $expected      = SchemaShape::of(SchemaShape::generate(SchemaShape::entityManager($params)));
        $connection    = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();
        foreach ($expected as $name => $shape) {
            // @phpstan-ignore function.alreadyNarrowedType (introspectTableByUnquotedName() exists from DBAL 4.3; DBAL 3 has introspectTable())
            $table  = method_exists($schemaManager, 'introspectTableByUnquotedName') ? $schemaManager->introspectTableByUnquotedName($name) : $schemaManager->introspectTable($name);
            $actual = SchemaShape::of(new Schema([$table]))[$name];
            self::assertSame($shape['fks'], $actual['fks'], $name);
            self::assertSame($this->secondary($shape['indexes']), $this->secondary($actual['indexes']), $name);
        }

        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        $collation = OwnerCollationListener::collationFor($connection->getDatabasePlatform());
        foreach ([UserSession::class => OwnerCollationListener::COLUMNS, UserSessionEpoch::class => OwnerCollationListener::COLUMNS, OwnerImagePointer::class => PointerCollationListener::COLUMNS] as $class => $columns) {
            foreach ($columns as $column) {
                self::assertSame($collation, $connection->fetchOne(
                    'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$em->getClassMetadata($class)->getTableName(), $column],
                ), $class . ' ' . $column);
            }
        }
    }

    /**
     * @param list<string> $indexes
     *
     * @return list<string>
     */
    private function secondary(array $indexes): array
    {
        return array_values(array_filter($indexes, static fn (string $index): bool => !str_starts_with($index, 'primary ')));
    }
}
