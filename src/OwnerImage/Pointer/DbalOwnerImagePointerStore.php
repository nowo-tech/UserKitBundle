<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Pointer;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;

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

    /**
     * @param string|null $entityManagerName `owner_images.doctrine.entity_manager` (null: the manager of the entity)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManagerName = null,
    ) {
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
     * Inserts, updates or deletes the subject's row (by primary key, in one transaction).
     *
     * @throws InvalidArgumentException on a malformed kind, subject id or file id
     */
    public function set(string $kind, string $subjectId, ?string $fileId): void
    {
        if (preg_match(OwnerImageKind::NAME_PATTERN, $kind) !== 1 || !OwnerImageKinds::isSubjectId($subjectId) || ($fileId !== null && ($fileId === '' || strlen($fileId) > self::FILE_ID_MAX))) {
            throw new InvalidArgumentException('Malformed owner image kind, subject id or file id.');
        }
        // @igor-ignore - The closure runs synchronously inside transactional(); nothing is kept on the service.
        $this->connection()->transactional(static function (Connection $connection) use ($kind, $subjectId, $fileId): void {
            $rowId = null;
            foreach ($connection->fetchAllAssociative('SELECT id, kind, subject_id FROM ' . self::TABLE . ' WHERE kind = ? AND subject_id = ?', [$kind, $subjectId]) as $row) {
                if ($row['kind'] === $kind && $row['subject_id'] === $subjectId) {
                    $rowId = (int) $row['id'];
                }
            }
            if ($fileId === null) {
                if ($rowId !== null) {
                    $connection->delete(self::TABLE, ['id' => $rowId]);
                }

                return;
            }
            $now = new DateTimeImmutable();
            if ($rowId !== null) {
                $connection->update(self::TABLE, ['file_id' => $fileId, 'updated_at' => $now], ['id' => $rowId], ['updated_at' => Types::DATETIME_IMMUTABLE]);

                return;
            }
            $connection->insert(self::TABLE, ['kind' => $kind, 'subject_id' => $subjectId, 'file_id' => $fileId, 'updated_at' => $now], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        });
    }

    private function connection(): Connection
    {
        $manager = $this->entityManagerName !== null ? $this->doctrine->getManager($this->entityManagerName) : $this->doctrine->getManagerForClass(OwnerImagePointer::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine entity manager maps ' . OwnerImagePointer::class . ': enable owner_images (it prepends the mapping) or set owner_images.pointer_store.');
        }

        return $manager->getConnection();
    }
}
