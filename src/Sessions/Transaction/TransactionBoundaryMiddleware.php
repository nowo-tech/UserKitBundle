<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Transaction;

use Doctrine\Bundle\DoctrineBundle\Middleware\ConnectionNameAwareInterface;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tells {@see CommitQueue} when the outermost transaction of a connection starts and ends (one instance per connection,
 * named by DoctrineBundle). It changes no statement, result or exception.
 */
final class TransactionBoundaryMiddleware implements Middleware, ConnectionNameAwareInterface, ResetInterface
{
    private string $connectionName = 'default';

    public function __construct(
        private readonly CommitQueue $queue,
    ) {
    }

    public function setConnectionName(string $name): void
    {
        // @igor-ignore - Configuration set once by the container (DoctrineBundle's MiddlewaresPass), not request state.
        $this->connectionName = $name;
    }

    public function wrap(Driver $driver): Driver
    {
        return new TransactionBoundaryDriver($driver, $this->queue, $this->connectionName);
    }

    /**
     * Nothing to clear: the name is configuration, set once by the container.
     */
    public function reset(): void
    {
    }
}
