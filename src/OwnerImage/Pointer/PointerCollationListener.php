<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Pointer;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\Sessions\Doctrine\OwnerCollationListener;

use function method_exists;

/**
 * Exact (binary NO PAD) collation for `user_kit_owner_image.kind` and `subject_id` on MySQL / MariaDB (house rule LQ,
 * 1.18), the same way {@see OwnerCollationListener} does it for the session tables: the generated schema
 * (`doctrine:schema:update`, `doctrine:migrations:diff`) gets it; PostgreSQL and SQLite already compare exactly.
 * Stateless.
 */
final readonly class PointerCollationListener
{
    /** Exactly compared columns. */
    public const COLUMNS = ['kind', 'subject_id'];

    /**
     * @param string|null $entityManagerName `owner_images.doctrine.entity_manager` (null = the manager of the entity)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManagerName = null,
    ) {
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        if ($args->getClassMetadata()->getName() !== OwnerImagePointer::class) {
            return;
        }
        $manager   = $this->entityManagerName !== null ? $this->doctrine->getManager($this->entityManagerName) : $this->doctrine->getManagerForClass(OwnerImagePointer::class);
        $collation = $manager instanceof EntityManagerInterface ? OwnerCollationListener::collationFor($manager->getConnection()->getDatabasePlatform()) : null;
        if ($collation === null) {
            return;
        }
        $table = $args->getClassTable();
        foreach (self::COLUMNS as $column) {
            // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType, function.alreadyNarrowedType (API availability depends on the installed DBAL / ORM)
            $edited = OwnerCollationListener::collate($table, $column, $collation, OwnerCollationListener::editorSupported(method_exists(Table::class, 'edit'), method_exists(Schema::class, 'edit'), method_exists(GenerateSchemaTableEventArgs::class, 'setClassTable')));
            if ($edited instanceof Table) {
                $table = $edited;
            }
        }
        if ($table !== $args->getClassTable()) {
            $args->setClassTable($table);
        }
    }
}
