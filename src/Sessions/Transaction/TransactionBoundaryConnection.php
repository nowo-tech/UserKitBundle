<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Transaction;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

use function preg_match;

/**
 * DBAL calls these three methods for the outermost transaction only (a nested one is a savepoint statement), which is
 * what makes this the commit hook DBAL does not offer. See {@see CommitQueue} for why nothing runs here.
 *
 * 1.22.0 (audit VT-M1): DBAL sends its savepoint statements as plain SQL through {@see self::exec()}; they are reported
 * to the queue, so an effect held inside a savepoint that is rolled back is dropped. A savepoint statement a host sends
 * through `executeQuery()` or a prepared statement is not seen.
 */
final class TransactionBoundaryConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly CommitQueue $queue,
        private readonly string $connectionName,
    ) {
        parent::__construct($connection);
    }

    /**
     * Runs the statement; a savepoint statement is reported to the queue once it succeeded (a rollback to a savepoint
     * also when it failed: what it would have undone must not be applied).
     *
     * The return type stays `int` for DBAL 3 and 4 alike; a count beyond `PHP_INT_MAX` (DBAL 4 may return it as a
     * string) is capped.
     */
    public function exec(string $sql): int
    {
        $savepoint = self::savepointStatement($sql);
        if ($savepoint === null) {
            return (int) parent::exec($sql);
        }
        [$kind, $name] = $savepoint;
        if ($kind === 'rollback') {
            try {
                return (int) parent::exec($sql);
            } finally {
                $this->queue->savepointRolledBack($this->connectionName, $name);
            }
        }
        $result = (int) parent::exec($sql);
        if ($kind === 'create') {
            $this->queue->savepointCreated($this->connectionName, $name);
        } else {
            $this->queue->savepointReleased($this->connectionName, $name);
        }

        return $result;
    }

    /**
     * `create`, `release` or `rollback` with the savepoint name, or null for any other statement.
     *
     * @return array{'create'|'release'|'rollback', string}|null
     */
    public static function savepointStatement(string $sql): ?array
    {
        if (preg_match('/^\s*(?:SAVE|RELEASE|ROLLBACK)/i', $sql) !== 1) {
            return null;
        }
        $patterns = [
            'create'   => '/^\s*(?:SAVEPOINT|SAVE\s+TRAN(?:SACTION)?)\s+([`"\[]?)([^\s`"\];]+)[`"\]]?\s*;?\s*$/i',
            'release'  => '/^\s*RELEASE\s+(?:SAVEPOINT\s+)?([`"\[]?)([^\s`"\];]+)[`"\]]?\s*;?\s*$/i',
            'rollback' => '/^\s*ROLLBACK\s+(?:WORK\s+)?(?:TO\s+(?:SAVEPOINT\s+)?|TRAN(?:SACTION)?\s+)([`"\[]?)([^\s`"\];]+)[`"\]]?\s*;?\s*$/i',
        ];
        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $sql, $match) === 1) {
                return [$kind, $match[2]];
            }
        }

        return null;
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->queue->begun($this->connectionName);
    }

    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $exception) {
            // PostgreSQL, deferred constraint: the transaction is gone. Connection lost: the rows may or may not be
            // there, and if they are the registry touch still ends those sessions (`touch_interval`).
            $this->queue->rolledBack($this->connectionName);

            throw $exception;
        }
        $this->queue->committed($this->connectionName);
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->queue->rolledBack($this->connectionName);
        }
    }
}
