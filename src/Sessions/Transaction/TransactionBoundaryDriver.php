<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Transaction;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * See {@see TransactionBoundaryMiddleware}.
 */
final class TransactionBoundaryDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly CommitQueue $queue,
        private readonly string $connectionName,
    ) {
        parent::__construct($driver);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new TransactionBoundaryConnection(parent::connect($params), $this->queue, $this->connectionName);
    }
}
