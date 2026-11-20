<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;

use function method_exists;

/**
 * Exact-match collation for the owner columns (`user_type`, `user_id`) of `user_kit_session` and
 * `user_kit_session_epoch` on MySQL / MariaDB (house rule LQ, look-alike owners).
 *
 * The default MySQL / MariaDB collations fold case and accents (`Alice` = `alice` = `álice`) and the PAD SPACE ones
 * also ignore trailing spaces (`alice ` = `alice`), so an owner lookup in SQL alone reached the sessions and the epoch
 * of a look-alike owner. The store re-checks every row with `===` and adds a byte-exact predicate to its statements;
 * the binary NO PAD collation keeps the database itself, and the unique keys, exact: MariaDB `utf8mb4_nopad_bin`,
 * MySQL 8.0+ `utf8mb4_0900_bin`, MySQL 5.7 `utf8mb4_bin` (PAD SPACE: the re-check covers it). PostgreSQL and SQLite
 * compare exactly and are untouched (they reject MySQL collation names, so it cannot live in the mapping). Applied to
 * the generated schema (schema tool, `doctrine:migrations:diff`); existing tables need the `ALTER` of UPGRADING.
 *
 * 1.22.1 (audit XI): applied on `postGenerateSchema`, once the schema tool has added every foreign key, by table name.
 * Earlier releases replaced the table on `postGenerateSchemaTable` (`setClassTable()`, DBAL 4.5+ with ORM 3.7+), and
 * ORM 3.7–3.8 then added the table's foreign keys to the object it had replaced, so any foreign key starting on it (and
 * its index) was lost on MySQL / MariaDB (none exist today: latent). Now:
 *
 * - DBAL 4.5+ with ORM 3.7+: the table is rebuilt with its table editor (foreign keys and indexes included) and swapped
 *   into a new schema given back with `GenerateSchemaEventArgs::setSchema()`;
 * - anything older (ORM 2.x, ORM 3.0–3.6, DBAL 3.x–4.4): the column's platform options are set in place.
 *
 * Stateless (REQ-RUNTIME-002): the platform is the one of the schema tool's entity manager, read on each event; a
 * manager that does not map the entities is left alone.
 */
final readonly class OwnerCollationListener
{
    /** Owner columns of both tables. */
    public const COLUMNS = ['user_type', 'user_id'];

    /** Entities whose tables carry an owner. */
    public const ENTITIES = [UserSession::class, UserSessionEpoch::class];

    /**
     * @param ManagerRegistry|null $doctrine unused since 1.22.1 (the schema tool's entity manager decides); kept
     *                                       for BC
     * @param string|null $entityManagerName unused since 1.22.1; kept for BC
     */
    public function __construct(// @phpstan-ignore constructor.unusedParameter (both arguments kept for BC)
        ?ManagerRegistry $doctrine = null,
        ?string $entityManagerName = null,
    ) {
        unset($doctrine, $entityManagerName);
    }

    /**
     * The exact-match collation on that platform, null when its default is already exact.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform       => 'utf8mb4_0900_bin',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_bin',
            default                                    => null,
        };
    }

    /**
     * Gives the owner columns their exact-match collation in the generated schema, keeping every foreign key and index.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $manager = $args->getEntityManager();
        $tables  = self::mappedTables($manager, $args->getSchema(), self::ENTITIES);
        if ($tables === []) {
            return;
        }
        $collation = self::collationFor($manager->getConnection()->getDatabasePlatform());
        if ($collation !== null) {
            self::collateSchema($args, $tables, self::COLUMNS, $collation);
        }
    }

    /**
     * No longer subscribed (1.22.1, audit XI): replacing the table there lost its foreign keys. Kept for BC; does nothing.
     *
     * @deprecated since 1.22.1, the collation is applied on postGenerateSchema ({@see self::postGenerateSchema()})
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        unset($args);
    }

    /**
     * The tables of `$schema` that hold the entities of `$classes` `$manager` maps (other classes are skipped).
     *
     * @param list<class-string> $classes
     *
     * @return list<string>
     *
     * @internal
     */
    public static function mappedTables(EntityManagerInterface $manager, Schema $schema, array $classes): array
    {
        $tables = [];
        foreach ($classes as $class) {
            if ($manager->getMetadataFactory()->isTransient($class)) {
                continue;
            }
            $table = $manager->getClassMetadata($class)->getTableName();
            if ($schema->hasTable($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Gives `$columns` of `$tables` `$collation` in the schema of `$args`, keeping every foreign key and index: rebuilt
     * with the table editor and swapped into a new schema when {@see editorSupported()}, changed in place otherwise.
     *
     * @param list<string> $tables
     * @param list<non-empty-string> $columns
     * @param non-empty-string $collation
     *
     * @internal
     */
    public static function collateSchema(GenerateSchemaEventArgs $args, array $tables, array $columns, string $collation): void
    {
        $schema = $args->getSchema();
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType, function.alreadyNarrowedType (API availability depends on the installed DBAL / ORM)
        $editor = self::editorSupported(method_exists(Table::class, 'edit'), method_exists(Schema::class, 'edit'), method_exists(GenerateSchemaEventArgs::class, 'setSchema'));
        $edited = [];
        foreach ($tables as $name) {
            $original = $table = $schema->getTable($name);
            foreach ($columns as $column) {
                if ($table->hasColumn($column)) {
                    $table = self::collate($table, $column, $collation, $editor) ?? $table;
                }
            }
            if ($table !== $original) {
                $edited[] = [$original, $table];
            }
        }
        if ($edited === []) {
            return;
        }
        $schemaEditor = $schema->edit();
        foreach ($edited as [$original, $table]) {
            $schemaEditor->dropTable($original->getObjectName())->addTable($table);
        }
        $args->setSchema($schemaEditor->create());
    }

    /**
     * Whether the table-editor path is usable: it rebuilds the table, which needs `Table::edit()` (DBAL 4.3+), and swaps
     * it into a new schema, which needs `Schema::edit()` (DBAL 4.5+) and `GenerateSchemaEventArgs::setSchema()` (ORM
     * 3.7+; it throws unless `Schema::edit()` exists). Anything less uses the in-place path.
     *
     * @param bool $setSchema since 1.22.1 `GenerateSchemaEventArgs::setSchema()` (before: `setClassTable()`, same ORM 3.7)
     */
    public static function editorSupported(bool $tableEdit, bool $schemaEdit, bool $setSchema): bool
    {
        return $tableEdit && $schemaEdit && $setSchema;
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (returns the new table; see editorSupported()) or
     * in place (older DBAL; returns null).
     *
     * @param non-empty-string $column
     * @param non-empty-string $collation
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }
}
