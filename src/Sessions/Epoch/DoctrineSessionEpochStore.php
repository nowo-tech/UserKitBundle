<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Epoch;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\Sessions\Doctrine\OwnerCollationListener;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Transaction\UserKitRows;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;

use function is_string;
use function strtotime;

/**
 * Default epoch store: one row per user in `user_kit_session_epoch`, written through parameterised DBAL statements
 * (the host entity manager is never flushed). A bump only moves the epoch forward; two parallel first bumps are one
 * ignored unique-key violation.
 *
 * House rule LQ (look-alike owners): the owner is matched exactly (byte predicate on MySQL / MariaDB, `===` on the rows
 * read back), so `Alice` or `alice ` never reads, moves or deletes the epoch of `alice`. A table still on a folding
 * collation has a folding unique key: the first bump of a look-alike owner then fails loudly instead of moving another
 * owner's epoch (migrate the columns, see UPGRADING and {@see OwnerCollationListener}).
 *
 * 1.18.0 (Beacon spec 239): the first insert runs in its own savepoint inside a transaction (an erasure, a host
 * transaction), so a parallel first bump that wins no longer aborts a PostgreSQL transaction; the writes are retried in
 * autocommit when they deadlock or wait too long for a lock ({@see UserKitTransactionRunner::statement()}).
 */
final readonly class DoctrineSessionEpochStore implements SessionEpochStoreInterface
{
    private UserKitTransactionRunner $runner;

    /**
     * @param string|null $entityManagerName `sessions.doctrine.entity_manager` (null = the manager of the entity)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManagerName = null,
        ?UserKitTransactionRunner $runner = null,
    ) {
        $this->runner = $runner ?? new UserKitTransactionRunner($doctrine);
    }

    /**
     * Epoch of the user (Unix timestamp), null when none.
     */
    public function epoch(SessionUser $owner): ?int
    {
        $value = $this->row($owner)['epoch_at'] ?? null;
        $time  = is_string($value) ? strtotime($value . ' UTC') : false;

        return $time === false ? null : $time;
    }

    /**
     * Moves the epoch forward to `$at`.
     */
    public function bump(SessionUser $owner, DateTimeImmutable $at): void
    {
        $at     = $at->setTimezone(new DateTimeZone('UTC'));
        $params = ['type' => $owner->type, 'id' => $owner->id, 'at' => $at];
        $types  = ['at' => Types::DATETIME_IMMUTABLE];
        $update = 'UPDATE ' . $this->table() . ' SET epoch_at = :at WHERE ' . $this->ownerSql() . ' AND epoch_at < :at';
        // @igor-ignore - Closure runs synchronously inside the runner; it is not stored on the service.
        $this->runner->statement($this->connection(), function () use ($owner, $at, $params, $types, $update): void {
            if ($this->row($owner) !== null) {
                $this->connection()->executeStatement($update, $params, $types);

                return;
            }
            $id = UserKitRows::sequenceId($this->connection(), $this->entityManager()->getClassMetadata(UserSessionEpoch::class));
            if (UserKitRows::insert($this->connection(), $this->table(), $id + ['user_type' => $owner->type, 'user_id' => $owner->id, 'epoch_at' => $at], ['epoch_at' => Types::DATETIME_IMMUTABLE])) {
                return;
            }
            // A current read: under MySQL's REPEATABLE READ a plain SELECT inside a transaction would reuse its snapshot
            // and miss the row that just won (1.18.0).
            if ($this->row($owner, true) === null) {
                // The unique key folds this owner into a look-alike's row (table not migrated to the binary collation):
                // never move that other owner's epoch, and never report a bump that did not happen.
                throw new LogicException('NowoUserKitBundle: the remember-me epoch of this owner collides with a look-alike owner under the collation of ' . $this->table() . '. Convert its user_type / user_id columns to a binary NO PAD collation (UPGRADING, "To 1.16.1").');
            }
            // A parallel first bump won: move it forward when ours is later.
            $this->connection()->executeStatement($update, $params, $types);
        });
    }

    /**
     * Deletes the epoch of the user.
     */
    public function forget(SessionUser $owner): void
    {
        $this->runner->statement($this->connection(), fn (): int => (int) $this->connection()->executeStatement(
            'DELETE FROM ' . $this->table() . ' WHERE ' . $this->ownerSql(),
            ['type' => $owner->type, 'id' => $owner->id],
        ));
    }

    /**
     * The owner's row, re-checked with `===`; null when none. `$current`: a locking read (the latest committed row, not
     * the transaction's snapshot, on MySQL / MariaDB).
     *
     * @phpstan-impure
     *
     * @return array<string, mixed>|null
     */
    private function row(SessionUser $owner, bool $current = false): ?array
    {
        foreach ($this->connection()->fetchAllAssociative(
            'SELECT user_type, user_id, epoch_at FROM ' . $this->table() . ' WHERE ' . $this->ownerSql() . ($current ? UserSessionStore::sharedLock($this->connection()->getDatabasePlatform()) : ''),
            ['type' => $owner->type, 'id' => $owner->id],
        ) as $row) {
            if (UserSessionStore::isOwner($owner, $row)) {
                return $row;
            }
        }

        return null;
    }

    private function ownerSql(): string
    {
        return UserSessionStore::exactOwnerSql($this->connection()->getDatabasePlatform());
    }

    /**
     * Prefixed table name.
     */
    public function table(): string
    {
        return $this->entityManager()->getClassMetadata(UserSessionEpoch::class)->getTableName();
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->entityManagerName !== null
            ? $this->doctrine->getManager($this->entityManagerName)
            : $this->doctrine->getManagerForClass(UserSessionEpoch::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoUserKitBundle: the remember-me epoch entity is not mapped by an ORM entity manager (sessions.doctrine.entity_manager).');
        }

        return $manager;
    }
}
