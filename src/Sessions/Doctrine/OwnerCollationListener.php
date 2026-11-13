<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;

use function in_array;
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
 * Stateless (REQ-RUNTIME-002): the platform is read from the registry on each event.
 */
final readonly class OwnerCollationListener
{
    /** Owner columns of both tables. */
    public const COLUMNS = ['user_type', 'user_id'];

    /** Entities whose tables carry an owner. */
    public const ENTITIES = [UserSession::class, UserSessionEpoch::class];

    /**
     * @param string|null $entityManagerName `sessions.doctrine.entity_manager` (null = the manager of the entity)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManagerName = null,
    ) {
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

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $class = $args->getClassMetadata()->getName();
        if (!in_array($class, self::ENTITIES, true)) {
            return;
        }
        $manager   = $this->entityManagerName !== null ? $this->doctrine->getManager($this->entityManagerName) : $this->doctrine->getManagerForClass($class);
        $collation = $manager instanceof EntityManagerInterface ? self::collationFor($manager->getConnection()->getDatabasePlatform()) : null;
        if ($collation === null) {
            return;
        }

        $table = $args->getClassTable();
        foreach (self::COLUMNS as $column) {
            // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
            $edited = self::collate($table, $column, $collation, method_exists(Table::class, 'edit'));
            if ($edited instanceof Table) {
                $table = $edited;
            }
        }
        if ($table !== $args->getClassTable()) {
            $args->setClassTable($table);
        }
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
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
