<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * 1.19.0 (Beacon spec 239): an INSERT that may lose a unique key race, safe inside a transaction. Inside one (a host
 * transaction, the eraser's) the INSERT runs in its own savepoint: a lost race rolls back to it, so PostgreSQL does not
 * abort the whole transaction (up to 1.18 the next statement failed with 25P02 "current transaction is aborted") and
 * the caller reads the winner's row. Outside a transaction it is a plain INSERT. Stateless.
 *
 * @internal
 */
final class UserKitRows
{
    /**
     * The id column of a new row when the entity's ids come from a sequence (ORM's `AUTO` on PostgreSQL with DBAL 3, or an
     * explicit `SEQUENCE` strategy): the row is written through DBAL, so the next value is fetched here (up to 1.18 the
     * column was left out and PostgreSQL with DBAL 3 refused every registration with a NOT NULL violation). Empty for an
     * identity column, which the database fills.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, int>
     */
    public static function sequenceId(Connection $connection, ClassMetadata $metadata): array
    {
        if (!$metadata->isIdGeneratorSequence()) {
            return [];
        }

        return [$metadata->getSingleIdentifierColumnName() => (int) $connection->fetchOne($connection->getDatabasePlatform()->getSequenceNextValSQL((string) $metadata->sequenceGeneratorDefinition['sequenceName']))];
    }

    /**
     * @param array<string, mixed> $data column => value
     * @param array<string, mixed> $types column => DBAL type
     *
     * @return bool false when another row already holds the unique key (nothing inserted)
     */
    public static function insert(Connection $connection, string $table, array $data, array $types = []): bool
    {
        if (!$connection->isTransactionActive()) {
            try {
                $connection->insert($table, $data, $types);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        }

        $savepoint = 'NOWO_USER_KIT_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);
        try {
            $connection->insert($table, $data, $types);
        } catch (UniqueConstraintViolationException) {
            $connection->rollbackSavepoint($savepoint);

            return false;
        } catch (Throwable $failure) {
            // A deadlock or lock wait: the server may have dropped the savepoint with the transaction; the caller's
            // runner repairs the connection. Never hide the original failure behind a failing savepoint rollback.
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (Throwable) {
            }

            throw $failure;
        }
        $connection->releaseSavepoint($savepoint);

        return true;
    }
}
