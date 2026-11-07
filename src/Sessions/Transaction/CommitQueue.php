<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Transaction;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_push;
use function array_shift;

/**
 * Effects that must only happen if the open transaction commits (HP-L2): a marker in a cache pool and the event of a
 * password change are not undone by a rollback, so they wait here for the outermost commit and are dropped on rollback.
 *
 * DBAL has no commit hook on the {@see Connection} (the transaction events of DBAL 3 are deprecated and gone in 4), so
 * {@see TransactionBoundaryMiddleware} watches the driver connection, which DBAL only calls for the outermost
 * transaction. That call comes **before** DBAL lowers its nesting level, so nothing can run there: a listener opening a
 * transaction would get a savepoint on a connection that has none. Committed effects therefore wait for the next
 * boundary, {@see self::release()}: Doctrine's `postFlush` (so a flush with no outer transaction ends as it always did),
 * `kernel.response`, `kernel.terminate`, `console.terminate`, the end of a Messenger message, or a host calling {@see UserSessionManager::releaseHeldRevocations()}.
 *
 * A connection this queue cannot see (no middleware, not in the registry) is reported as not held, and the caller goes
 * on at once as before: a revocation is never left waiting for a signal that cannot come.
 *
 * Holds the current transaction's effects only (`kernel.reset`, worker mode).
 */
final class CommitQueue implements ResetInterface
{
    /** @var array<string, true> connection name => its outermost transaction was seen starting */
    private array $open = [];

    /** @var array<string, list<Closure(): void>> connection name => effects of its open transaction */
    private array $held = [];

    /** @var list<Closure(): void> effects whose transaction committed */
    private array $committed = [];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Holds `$effect` until the outermost commit of `$connection`.
     *
     * @param Closure(): void $effect
     *
     * @return bool false when no watched transaction is open: nothing is held, the caller runs the effect itself
     */
    public function hold(Connection $connection, Closure $effect): bool
    {
        if (!$connection->isTransactionActive()) {
            return false;
        }
        $name = $this->nameOf($connection);
        if ($name === null || !isset($this->open[$name])) {
            return false;
        }
        $this->held[$name][] = $effect;

        return true;
    }

    /**
     * The driver started an outermost transaction. Whatever is still held belongs to one that ended unseen (the
     * connection was closed or lost) and did not commit.
     */
    public function begun(string $connectionName): void
    {
        $this->open[$connectionName] = true;
        unset($this->held[$connectionName]);
    }

    /**
     * The driver committed the outermost transaction: its effects wait for the next {@see self::release()}.
     */
    public function committed(string $connectionName): void
    {
        array_push($this->committed, ...($this->held[$connectionName] ?? []));
        unset($this->open[$connectionName], $this->held[$connectionName]);
    }

    /**
     * The driver rolled the outermost transaction back, or its commit failed: its effects are dropped.
     */
    public function rolledBack(string $connectionName): void
    {
        unset($this->open[$connectionName], $this->held[$connectionName]);
    }

    /**
     * Runs the effects of committed transactions, unless a transaction is open: they then wait for a later boundary.
     */
    public function release(): void
    {
        if (!$this->transactionOpen()) {
            $this->run();
        }
    }

    /**
     * The end of the request, command or message: nothing later would run them, so a transaction still open does not hold
     * them back (with `autoCommit: false` one always is).
     */
    public function finish(): void
    {
        $this->run();
    }

    /**
     * Doctrine's `postFlush`: a flush with no outer transaction has committed by now, so what it held is applied before
     * `flush()` returns, as it always was.
     */
    public function postFlush(): void
    {
        $this->release();
    }

    /**
     * Between requests (worker mode). What an open transaction held is dropped: that transaction did not commit. Committed
     * effects are not: they run now, since a `password_changed` event has no other chance. Nothing may throw from here.
     */
    public function reset(): void
    {
        $this->open = [];
        $this->held = [];

        try {
            $this->run();
        } catch (Throwable $exception) {
            $this->logger?->error('NowoUserKitBundle: a revocation effect of a committed transaction failed between requests.', ['exception' => $exception]);
        }
    }

    /**
     * Oldest first. One that throws does not stop the others (another user's revocation must not be lost); the first
     * exception goes on.
     */
    private function run(): void
    {
        $failure = null;
        while ($this->committed !== []) {
            $effect = array_shift($this->committed);

            try {
                $effect();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }
        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    /**
     * Asks DBAL, and forgets what it says has ended: a connection closed or lost sends the driver no rollback.
     */
    private function transactionOpen(): bool
    {
        foreach ($this->open as $name => $open) {
            $connection = $this->doctrine->getConnection($name);
            if ($connection instanceof Connection && $connection->isTransactionActive()) {
                return true;
            }
            unset($this->open[$name]);
        }

        return false;
    }

    private function nameOf(Connection $connection): ?string
    {
        foreach ($this->doctrine->getConnectionNames() as $name => $service) {
            if ($this->doctrine->getConnection($name) === $connection) {
                return $name;
            }
        }

        return null;
    }
}
