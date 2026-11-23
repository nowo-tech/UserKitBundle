<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Transaction;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Nowo\UserKitBundle\PersonalData\Event\AccountErasedEvent;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_filter;
use function array_keys;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function min;
use function strtolower;

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
 * Savepoints (1.22.0, audit VT-M1): {@see TransactionBoundaryConnection} also reports the savepoint statements DBAL sends
 * through the driver connection (`SAVEPOINT`, `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`, and SQL Server's
 * `SAVE TRANSACTION` / `ROLLBACK TRANSACTION`). Each held effect remembers how many savepoints were open when it was held;
 * rolling back to a savepoint drops the effects held after it, releasing one hands its effects to the enclosing level.
 * A rollback to a savepoint this queue never saw drops every effect of that transaction (it cannot tell which survived).
 *
 * Holds the current transaction's effects only (`kernel.reset`, worker mode).
 *
 * @internal Not a public API (1.22.0): applications and other bundles listen to
 *           {@see AccountErasedEvent} (after an erasure commits) or to the
 *           bundle's other events instead; this class, its methods and its service may change in any release.
 */
final class CommitQueue implements ResetInterface
{
    /** @var array<string, true> connection name => its outermost transaction was seen starting */
    private array $open = [];

    /** @var array<string, list<array{int, Closure(): void}>> connection name => [savepoint depth, effect] of its open transaction */
    private array $held = [];

    /** @var array<string, list<string>> connection name => savepoints open in its transaction, oldest first (lowercase) */
    private array $savepoints = [];

    /** @var list<Closure(): void> effects whose transaction committed */
    // @igor-ignore - reset() runs and empties this list through run() (committed effects are executed, never dropped), which Igor cannot see.
    #[WorkerSafe]
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
        $name = $this->watchedName($connection);
        if ($name === null) {
            return false;
        }
        $this->held[$name][] = [count($this->savepoints[$name] ?? []), $effect];

        return true;
    }

    /**
     * Whether {@see self::hold()} would hold an effect on `$connection` now (1.22.0): a transaction is open on it and its
     * outermost begin was seen.
     */
    public function watches(Connection $connection): bool
    {
        return $this->watchedName($connection) !== null;
    }

    /**
     * The driver started an outermost transaction. Whatever is still held belongs to one that ended unseen (the
     * connection was closed or lost) and did not commit.
     */
    public function begun(string $connectionName): void
    {
        $this->open[$connectionName] = true;
        unset($this->held[$connectionName], $this->savepoints[$connectionName]);
    }

    /**
     * A savepoint was set in the open transaction (1.22.0).
     */
    public function savepointCreated(string $connectionName, string $savepoint): void
    {
        if (isset($this->open[$connectionName])) {
            $this->savepoints[$connectionName][] = strtolower($savepoint);
        }
    }

    /**
     * A savepoint was released (1.22.0): it and the later ones are gone, their effects belong to the enclosing level.
     */
    public function savepointReleased(string $connectionName, string $savepoint): void
    {
        $index = $this->indexOf($connectionName, $savepoint);
        if ($index === null) {
            return;
        }
        $this->savepoints[$connectionName] = array_slice($this->savepoints[$connectionName], 0, $index);
        foreach ($this->held[$connectionName] ?? [] as $i => [$depth, $effect]) {
            $this->held[$connectionName][$i] = [min($depth, $index), $effect];
        }
    }

    /**
     * The transaction was rolled back to a savepoint (1.22.0), or that rollback failed: what was held after the savepoint
     * was set is dropped; the savepoint itself stays. An unknown savepoint drops everything the transaction held.
     */
    public function savepointRolledBack(string $connectionName, string $savepoint): void
    {
        if (!isset($this->open[$connectionName])) {
            return;
        }
        $index = $this->indexOf($connectionName, $savepoint);
        if ($index === null) {
            if (($this->held[$connectionName] ?? []) !== []) {
                $this->logger?->warning('NowoUserKitBundle: a rollback to a savepoint the commit queue did not see dropped the effects held in the transaction.', ['savepoint' => $savepoint]);
            }
            unset($this->held[$connectionName]);

            return;
        }
        $this->savepoints[$connectionName] = array_slice($this->savepoints[$connectionName], 0, $index + 1);
        $this->held[$connectionName]       = array_values(array_filter(
            $this->held[$connectionName] ?? [],
            static fn (array $held): bool => $held[0] <= $index,
        ));
    }

    /**
     * The driver committed the outermost transaction: its effects wait for the next {@see self::release()}.
     */
    public function committed(string $connectionName): void
    {
        foreach ($this->held[$connectionName] ?? [] as [, $effect]) {
            $this->committed[] = $effect;
        }
        unset($this->open[$connectionName], $this->held[$connectionName], $this->savepoints[$connectionName]);
    }

    /**
     * The driver rolled the outermost transaction back, or its commit failed: its effects are dropped.
     */
    public function rolledBack(string $connectionName): void
    {
        unset($this->open[$connectionName], $this->held[$connectionName], $this->savepoints[$connectionName]);
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
        $this->open       = [];
        $this->held       = [];
        $this->savepoints = [];

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

    /**
     * Position of the newest savepoint called `$savepoint`, or null when it is not open.
     */
    private function indexOf(string $connectionName, string $savepoint): ?int
    {
        $found = array_keys($this->savepoints[$connectionName] ?? [], strtolower($savepoint), true);

        return $found === [] ? null : $found[count($found) - 1];
    }

    private function watchedName(Connection $connection): ?string
    {
        if (!$connection->isTransactionActive()) {
            return null;
        }
        $name = $this->nameOf($connection);

        return $name !== null && isset($this->open[$name]) ? $name : null;
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
