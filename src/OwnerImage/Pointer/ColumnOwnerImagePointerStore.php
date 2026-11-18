<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Pointer;

use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;

use function array_chunk;
use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function spl_object_id;
use function sprintf;
use function strlen;

/**
 * 1.20.0 (`owner_images.pointer_store: column`): keeps each kind's pointer in a column of the owner's own row —
 * `owner_images.kinds.<kind>.pointer: {entity, field, subject_field}` (subject_field: the field that holds the subject
 * id, default the entity's identifier). Lists then need no pointer table, and an entity already loaded answers without a
 * query.
 *
 * Identity-map aware, never flushing the application's entity manager (architecture bar rule 6):
 * - reads take the subjects whose entity is managed and initialised from the identity map (what the request sees), the
 *   rest in one DBAL query per {@see self::CHUNK} subject ids;
 * - a write is one DBAL `UPDATE` of the subject's own row (no lifecycle callbacks, entity listeners or `#[Version]`
 *   increment: the ORM does not see it), by its identifier and subject, in one transaction (a
 *   savepoint inside the caller's) with the row locked; the previous file id is read under that lock
 *   ({@see LockingOwnerImagePointerStoreInterface}). Once the write returned, a managed entity of that subject gets the
 *   new value, also as its original value, so the next flush writes nothing for it.
 *
 * Tenant safety: rows are matched byte for byte on the subject id after reading them back (house rule LQ: a folding
 * collation cannot hand `ABC`'s image to `abc`); a subject id that matches no row, or more than one, writes nothing.
 * Never another owner's row: the `UPDATE` names the identifier the locked read returned and the subject id.
 *
 * Worker and replay safe: nothing is kept between calls; the metadata, the entity manager and the managed entities are
 * looked up again at each call and after each attempt (a write unit may clear its entity manager between attempts).
 */
final readonly class ColumnOwnerImagePointerStore implements LockingOwnerImagePointerStoreInterface
{
    /** Subject ids per `IN (…)` list. */
    public const CHUNK = 500;

    /** Longest file id. */
    public const FILE_ID_MAX = DbalOwnerImagePointerStore::FILE_ID_MAX;

    private UserKitTransactionRunner $runner;

    /**
     * @param array<string, array{entity: class-string, field: string, subject_field: string|null}> $pointers kind => pointer
     *                                                                                                        column
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private array $pointers,
        ?UserKitTransactionRunner $runner = null,
    ) {
        $this->runner = $runner ?? new UserKitTransactionRunner($doctrine);
    }

    public function find(string $kind, array $subjectIds): array
    {
        $ids = array_values(array_unique(array_filter($subjectIds, OwnerImageKinds::isSubjectId(...))));
        if ($ids === [] || !isset($this->pointers[$kind])) {
            return [];
        }
        $column  = ColumnPointer::resolve($this->doctrine, $kind, $this->pointers[$kind]);
        $pending = [];
        foreach ($ids as $id) {
            $pending['s' . $id] = $id;
        }
        $found = [];
        // A managed entity answers only when the subject field holds one row per subject id (the identifier or a
        // unique column): otherwise the database counts the rows, and two rows of one subject give no pointer.
        foreach ($column->uniqueSubject ? $column->loaded() : [] as $entity) {
            $subject = ColumnPointer::text($column->metadata->getFieldValue($entity, $column->subjectField));
            if ($subject !== null && isset($pending['s' . $subject])) {
                unset($pending['s' . $subject]);
                $file = ColumnPointer::text($column->metadata->getFieldValue($entity, $column->field));
                if ($file !== null) {
                    $found[$subject] = $file;
                }
            }
        }

        $values = [];
        foreach ($pending as $id) {
            $value = $column->subjectValue($id);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        // Every row of those subjects, also without an image: two rows of one subject (no unique key on the subject
        // column) give no pointer rather than a guess.
        $sql   = sprintf('SELECT %s AS %s, %s AS %s FROM %s WHERE %1$s IN (?)', $column->subjectColumn, ColumnPointer::SUBJECT, $column->fileColumn, ColumnPointer::FILE, $column->table);
        $rows  = [];
        $files = [];
        $types = $column->subjectListTypes();
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            foreach ($column->connection()->fetchAllAssociative($sql, [$chunk], $types) as $row) {
                $subject = $column->read($column->subjectField, $row[ColumnPointer::SUBJECT] ?? null);
                if ($subject === null || !isset($pending['s' . $subject])) {
                    continue;
                }
                $rows['s' . $subject]  = ($rows['s' . $subject] ?? 0) + 1;
                $files['s' . $subject] = $column->read($column->field, $row[ColumnPointer::FILE] ?? null);
            }
        }
        foreach ($files as $key => $file) {
            if ($file !== null && $rows[$key] === 1) {
                $found[$pending[$key]] = $file;
            }
        }

        return $found;
    }

    public function set(string $kind, string $subjectId, ?string $fileId): void
    {
        $this->replace($kind, $subjectId, $fileId);
    }

    /**
     * Writes the subject's column and returns the file id it held, read under the row lock.
     *
     * @throws InvalidArgumentException on an unmapped kind, a malformed subject id or file id, or (with a file id) a
     *                                  subject that has no row
     * @throws UserKitConflict when several rows hold that subject id
     */
    public function replace(string $kind, string $subjectId, ?string $fileId): ?string
    {
        if (!isset($this->pointers[$kind]) || !OwnerImageKinds::isSubjectId($subjectId) || ($fileId !== null && ($fileId === '' || strlen($fileId) > self::FILE_ID_MAX))) {
            throw new InvalidArgumentException('Unknown owner image kind, or malformed subject id or file id.');
        }
        $column     = ColumnPointer::resolve($this->doctrine, $kind, $this->pointers[$kind]);
        $connection = $column->connection();
        $subject    = $column->subjectValue($subjectId);
        $column->fileValue($fileId); // the column's type must take the file id before anything is locked
        $criteria = [];
        $selected = [];
        foreach ($column->identifierColumns as $i => $name) {
            $criteria[] = $name . ' = ?';
            $selected[] = $name . ' AS ' . ColumnPointer::IDENTIFIER . $i;
        }
        $select = sprintf('SELECT %s, %s AS %s, %s AS %s FROM %s WHERE %2$s = ?', implode(', ', $selected), $column->subjectColumn, ColumnPointer::SUBJECT, $column->fileColumn, ColumnPointer::FILE, $column->table) . $column->forUpdate();
        $update = sprintf('UPDATE %s SET %s = ? WHERE %s AND %s = ?', $column->table, $column->fileColumn, implode(' AND ', $criteria), $column->subjectColumn);

        // @igor-ignore - The closures run synchronously inside the runner; nothing is kept on the service.
        [$previous, $updated] = $this->runner->statement($connection, static fn (): array => DoctrineTransactionRunner::forConnection($connection, null)->run(static function () use ($connection, $column, $select, $update, $subject, $subjectId, $fileId): array {
            $rows = $subject === null ? [] : $column->exact($connection->fetchAllAssociative($select, [$subjectId], [$column->type($column->subjectField)]), $subjectId);
            if (count($rows) > 1) {
                throw new UserKitConflict();
            }
            if ($rows === []) {
                if ($fileId === null) {
                    return [null, false];
                }

                throw new InvalidArgumentException('No owner row has that subject id.');
            }
            $row      = $rows[0];
            $previous = $column->read($column->field, $row[ColumnPointer::FILE] ?? null);
            if ($previous !== $fileId) {
                $params = [$fileId];
                $types  = [$column->type($column->field)];
                foreach ($column->identifierFields as $i => $name) {
                    $params[] = $column->php($name, $row[ColumnPointer::IDENTIFIER . $i] ?? null);
                    $types[]  = $column->type($name);
                }
                $params[] = $subjectId;
                $types[]  = $column->type($column->subjectField);
                $connection->executeStatement($update, $params, $types);

                return [$previous, true];
            }

            return [$previous, false];
        }));
        if ($updated) {
            // Only after a real write: a managed entity's pending value of the pointer is never overwritten for nothing.
            $this->sync($column, $subjectId, $fileId);
        }

        return $previous;
    }

    /**
     * The managed entities of the subject get the written value. Outside a transaction it is also their original value
     * (it is committed: no `UPDATE` at the application's next flush). Inside the application's transaction the original
     * stays the previous value, so the unit of work still sees the field as changed: after a commit the next flush writes
     * the same value again, after a rollback it writes the entity's value back (reload or clear the entity to drop it).
     * Runs after the write returned, so a failed attempt never changes an entity.
     */
    private function sync(ColumnPointer $column, string $subjectId, ?string $fileId): void
    {
        $unitOfWork = $column->manager->getUnitOfWork();
        $value      = $column->filePhpValue($fileId);
        $committed  = !$column->connection()->isTransactionActive();
        foreach ($column->loaded($subjectId) as $entity) {
            $column->metadata->setFieldValue($entity, $column->field, $value);
            if ($committed) {
                $unitOfWork->setOriginalEntityProperty(spl_object_id($entity), $column->field, $value);
            }
        }
    }
}
