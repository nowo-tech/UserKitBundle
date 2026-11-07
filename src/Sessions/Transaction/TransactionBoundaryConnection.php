<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Transaction;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

/**
 * DBAL calls these three methods for the outermost transaction only (a nested one is a savepoint statement), which is
 * what makes this the commit hook DBAL does not offer. See {@see CommitQueue} for why nothing runs here.
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
