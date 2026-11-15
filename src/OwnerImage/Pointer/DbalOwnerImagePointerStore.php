<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Pointer;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitRows;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;

use function array_chunk;
use function array_filter;
use function array_unique;
use function array_values;
use function in_array;
use function is_string;
use function preg_match;
use function strlen;

/**
 * Default pointer store (1.18): table `user_kit_owner_image` ({@see OwnerImagePointer}), read and written with DBAL
 * statements in an explicit transaction — no entity manager is flushed (architecture bar rule 6), nothing is kept
 * between calls (worker safe). Every row read is re-checked with `===` on kind and subject id (house rule LQ: a
 * folding collation cannot hand `ABC`'s image to `abc`); writes go by primary key. The connection is the one of the
 * entity manager that maps {@see OwnerImagePointer} (or `owner_images.doctrine.entity_manager`).
 */
final readonly class DbalOwnerImagePointerStore implements OwnerImagePointerStoreInterface
{
    /** Table name. */
    public const TABLE = 'user_kit_owner_image';

    /** Subject ids per `IN (…)` list. */
    public const CHUNK = 500;

    /** Longest file id. */
    public const FILE_ID_MAX = 64;

    private UserKitTransactionRunner $runner;

    /**
     * @param string|null $entityManagerName `owner_images.doctrine.entity_manager` (null: the manager of the entity)
     * @param UserKitTransactionRunner|null $runner 1.19.0: retries a write that deadlocked or waited too long for a lock
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManagerName = null,
        ?UserKitTransactionRunner $runner = null,
    ) {
        $this->runner = $runner ?? new UserKitTransactionRunner($doctrine);
    }

    /**
     * One query per {@see self::CHUNK} subject ids; malformed ids are skipped.
     */
    public function find(string $kind, array $subjectIds): array
    {
        $ids = array_values(array_unique(array_filter($subjectIds, OwnerImageKinds::isSubjectId(...))));
        if ($ids === [] || preg_match(OwnerImageKind::NAME_PATTERN, $kind) !== 1) {
            return [];
        }
        $connection = $this->connection();
        $found      = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($connection->fetchAllAssociative(
                'SELECT kind, subject_id, file_id FROM ' . self::TABLE . ' WHERE kind = ? AND subject_id IN (?)',
                [$kind, $chunk],
                [Types::STRING, ArrayParameterType::STRING],
            ) as $row) {
                if ($row['kind'] === $kind && in_array($row['subject_id'], $chunk, true) && is_string($row['file_id'])) {
                    $found[(string) $row['subject_id']] = $row['file_id'];
                }
            }
        }

        return $found;
    }

    /**
     * Inserts, updates or deletes the subject's row (by primary key, in one transaction, the row locked).
     *
     * @throws InvalidArgumentException on a malformed kind, subject id or file id
     */
    public function set(string $kind, string $subjectId, ?string $fileId): void
    {
        if (preg_match(OwnerImageKind::NAME_PATTERN, $kind) !== 1 || !OwnerImageKinds::isSubjectId($subjectId) || ($fileId !== null && ($fileId === '' || strlen($fileId) > self::FILE_ID_MAX))) {
            throw new InvalidArgumentException('Malformed owner image kind, subject id or file id.');
        }
        $manager    = $this->entityManager();
        $connection = $manager->getConnection();
        $metadata   = $manager->getClassMetadata(OwnerImagePointer::class);
        $forUpdate  = $this->forUpdate($connection->getDatabasePlatform());
        // 1.19.0 (Beacon spec 239, audit QM-M3): one unit of work (a savepoint inside the caller's transaction), the
        // subject's row read with a row lock, the first row inserted in its own savepoint with the id of the entity's
        // sequence where the ORM uses one (PostgreSQL with DBAL 3: a NOT NULL violation in 1.18.0). A lost first-row
        // race reads the winner's row and writes over it (1.18.0: a unique violation, which also aborted a PostgreSQL
        // transaction of the caller). A deadlock or lock wait is retried in autocommit, else UserKitStorageFailure (503).
        // @igor-ignore - The closures run synchronously inside the runner; nothing is kept on the service.
        $this->runner->statement($connection, static fn () => DoctrineTransactionRunner::forConnection($connection, null)->run(static function () use ($connection, $metadata, $forUpdate, $kind, $subjectId, $fileId): void {
            $rowId = self::rowId($connection, $kind, $subjectId, $forUpdate);
            if ($fileId === null) {
                if ($rowId !== null) {
                    $connection->delete(self::TABLE, ['id' => $rowId]);
                }

                return;
            }
            $now = new DateTimeImmutable();
            if ($rowId === null) {
                if (UserKitRows::insert($connection, self::TABLE, UserKitRows::sequenceId($connection, $metadata) + ['kind' => $kind, 'subject_id' => $subjectId, 'file_id' => $fileId, 'updated_at' => $now], ['updated_at' => Types::DATETIME_IMMUTABLE])) {
                    return;
                }
                $rowId = self::rowId($connection, $kind, $subjectId, $forUpdate);
                if ($rowId === null) {
                    // The unique key folds this subject into a look-alike's row (table not on the binary collation).
                    throw new UserKitConflict();
                }
            }
            $connection->update(self::TABLE, ['file_id' => $fileId, 'updated_at' => $now], ['id' => $rowId], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        }));
    }

    /**
     * The id of the subject's row (exact `===` match), read with `$forUpdate`.
     */
    private static function rowId(Connection $connection, string $kind, string $subjectId, string $forUpdate): ?int
    {
        $rowId = null;
        foreach ($connection->fetchAllAssociative('SELECT id, kind, subject_id FROM ' . self::TABLE . ' WHERE kind = ? AND subject_id = ?' . $forUpdate, [$kind, $subjectId]) as $row) {
            if ($row['kind'] === $kind && $row['subject_id'] === $subjectId) {
                $rowId = (int) $row['id'];
            }
        }

        return $rowId;
    }

    /**
     * Row lock of the read (none on SQLite: one writer).
     */
    private function forUpdate(AbstractPlatform $platform): string
    {
        return $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->entityManagerName !== null ? $this->doctrine->getManager($this->entityManagerName) : $this->doctrine->getManagerForClass(OwnerImagePointer::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine entity manager maps ' . OwnerImagePointer::class . ': enable owner_images (it prepends the mapping) or set owner_images.pointer_store.');
        }

        return $manager;
    }
}
