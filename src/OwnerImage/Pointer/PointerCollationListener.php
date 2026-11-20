<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Pointer;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\Sessions\Doctrine\OwnerCollationListener;

/**
 * Exact (binary NO PAD) collation for `user_kit_owner_image.kind` and `subject_id` on MySQL / MariaDB (house rule LQ,
 * 1.18), the same way {@see OwnerCollationListener} does it for the session tables: the generated schema
 * (`doctrine:schema:update`, `doctrine:migrations:diff`) gets it; PostgreSQL and SQLite already compare exactly.
 * 1.22.1 (audit XI): applied on `postGenerateSchema` once every foreign key exists, keeping them (see
 * {@see OwnerCollationListener}). Stateless.
 */
final readonly class PointerCollationListener
{
    /** Exactly compared columns. */
    public const COLUMNS = ['kind', 'subject_id'];

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

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $manager = $args->getEntityManager();
        $tables  = OwnerCollationListener::mappedTables($manager, $args->getSchema(), [OwnerImagePointer::class]);
        if ($tables === []) {
            return;
        }
        $collation = OwnerCollationListener::collationFor($manager->getConnection()->getDatabasePlatform());
        if ($collation !== null) {
            OwnerCollationListener::collateSchema($args, $tables, self::COLUMNS, $collation);
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
}
