<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_diff_key;
use function count;
use function is_object;
use function random_int;
use function spl_object_id;
use function usleep;

/**
 * 1.19.0 (Beacon spec 239): the runner of UserKit's writes, registered as `nowo_user_kit.transaction_runner` and
 * injected into UserKit's services by its class. UserKit never runs its writes through a runner the application binds
 * to {@see TransactionRunnerInterface}: such a runner drives the application's entity manager (it would reset or clear
 * it after a UserKit failure). Modelled on ChatKit 1.30.0 and AuthKit 1.50.0 (audits QD and QG).
 *
 * Units of work ({@see self::runFor()}, the personal data erasure): one attempt in one transaction
 * ({@see DoctrineTransactionRunner::forConnection()} of nowo-tech/transaction-doctrine). The operation runs, then the
 * manager is flushed once inside the transaction (the erasers change the user entity and never flush; the manager is
 * not flushed before the unit, so UserKit never commits the application's pending work on its own). After any failure
 * no transaction level of the runner stays open: the levels are rolled back to the entry level, and the connection is
 * closed only when that rollback fails (a MySQL deadlock inside the nested flush, where the server already dropped the
 * savepoints: up to 1.18 a raw 1305 and a connection left nested for the worker's next request).
 *
 * After a failure the manager is never reset nor cleared:
 * - only the rows the unit added, flushed or changed are detached (their database state was rolled back), with the
 *   rows that point at them (to-one), and a warning is logged when such a pointing row is one the caller held; a row
 *   the caller held and the unit did not change stays managed with the caller's pending changes. The runner compares
 *   each row's field values with a copy taken before the unit (no change set is computed for the caller's rows); a lazy
 *   row the unit only loaded stays managed;
 * - the `remove()` calls of the unit are cancelled (audit QM-M1): a later flush never deletes what the failed unit meant
 *   to;
 * - when the unit changed a collection (orphan removal, cascaded children cannot be reverted row by row) the manager is
 *   closed, as `wrapInTransaction()` did up to 1.18 (audit QM-M1);
 * - a manager the ORM closed in a failed flush stays closed for the rest of the request (the application's next flush
 *   fails loudly) and {@see ClosedManagerRecovery} replaces it at the next boundary.
 *
 * Failures: a transient one (deadlock, lock wait timeout, serialization failure) is rethrown as
 * {@see UserKitStorageFailure} (503 + Retry-After), a lost unique key race as {@see UserKitConflict} (409), anything
 * else unchanged. The unit is not replayed: erasers and the listeners of the revocation events may have side effects.
 *
 * Single statements ({@see self::statement()}, the session registry and the last activity): an autocommit statement that
 * deadlocks or waits too long for a lock was rolled back on its own and is idempotent, so it is run again, up to
 * {@see self::STATEMENT_ATTEMPTS} attempts with a short random pause; inside a transaction the caller opened it is never
 * replayed (the server may have rolled back the whole transaction) and the transient failure surfaces as
 * {@see UserKitStorageFailure}.
 *
 * Stateless (worker safe).
 */
final readonly class UserKitTransactionRunner implements TransactionRunnerInterface
{
    /** The service id UserKit's services are wired to. */
    public const SERVICE_ID = 'nowo_user_kit.transaction_runner';

    /** Attempts of an autocommit statement that failed transiently. */
    public const STATEMENT_ATTEMPTS = 3;

    private TransientFailureClassifier $classifier;

    /**
     * @param ManagerRegistry $doctrine The managers ({@see self::run()} uses the default one)
     * @param ClosedManagerRecovery|null $recovery Replaces a manager a failed unit closed at the next boundary
     * @param int|null $retryAfterSeconds Retry-After hint of the UserKitStorageFailure; null for none
     * @param int $statementAttempts Attempts of an autocommit statement (1 = no retry)
     * @param int $pauseMicroseconds Upper bound of the random pause before the second attempt (doubled each time)
     * @param LoggerInterface|null $logger Warns when a failed unit detached a row of the caller
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?ClosedManagerRecovery $recovery = null,
        private ?int $retryAfterSeconds = 1,
        private int $statementAttempts = self::STATEMENT_ATTEMPTS,
        private int $pauseMicroseconds = 20000,
        private ?LoggerInterface $logger = null,
    ) {
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Runs a unit of work on the default entity manager (see {@see self::runFor()}).
     *
     * {@inheritDoc}
     */
    public function run(callable $operation): mixed
    {
        $manager = $this->doctrine->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoUserKitBundle: the default Doctrine manager is not an ORM entity manager.');
        }

        return $this->runFor($manager, $operation);
    }

    /**
     * Runs a unit of work on `$manager`: one transaction, the operation, one flush, the commit (see the class
     * description).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @throws UserKitStorageFailure When the storage failed transiently (nothing of the unit is pending)
     * @throws UserKitConflict When the unit lost a unique key race (nothing of the unit is pending)
     *
     * @return T
     */
    public function runFor(EntityManagerInterface $manager, callable $operation): mixed
    {
        $before    = $this->rows($manager);
        $states    = $this->states($manager);
        $deletions = $this->deletions($manager);
        $dirty     = $this->dirtyCollections($manager);
        $observer  = new FlushObserver();
        $manager->getEventManager()->addEventListener([Events::postFlush], $observer);

        try {
            return DoctrineTransactionRunner::forConnection($manager->getConnection(), $this->retryAfterSeconds)->run(static function () use ($operation, $manager): mixed {
                $result = $operation();
                $manager->flush();

                return $result;
            });
        } catch (Throwable $failure) {
            if ($manager->isOpen() && array_diff_key($this->dirtyCollections($manager), $dirty) !== []) {
                // Audit QM-M1: a collection the unit changed cannot be reverted row by row (orphan removal, cascaded
                // children): as `wrapInTransaction()` did up to 1.18, the manager is closed, so nothing of the failed unit
                // can be flushed later; loud for the rest of the request, replaced at the next boundary.
                $manager->close();
            }
            if ($manager->isOpen()) {
                $this->cancelDeletions($manager, $deletions);
                $this->forget($manager, $before, $states, $observer->flushed);
            } else {
                // Closed by the ORM in the failed flush: loud for the rest of the request, replaced at the next boundary.
                $this->recovery?->remember($manager);
            }

            throw $this->translate($failure);
        } finally {
            $manager->getEventManager()->removeEventListener([Events::postFlush], $observer);
        }
    }

    /**
     * Runs one autocommit statement (or an idempotent group of them) of `$connection`; see the class description.
     *
     * @template T
     *
     * @param callable(): T $statement
     *
     * @throws UserKitStorageFailure When it still failed transiently after the last attempt, or failed transiently
     *                               inside a transaction the caller opened
     *
     * @return T
     */
    public function statement(Connection $connection, callable $statement): mixed
    {
        $nested = $connection->isTransactionActive();
        for ($attempt = 1;; ++$attempt) {
            try {
                return $statement();
            } catch (Throwable $failure) {
                if ($failure instanceof UserKitStorageFailure || !$this->classifier->isTransient($failure)) {
                    throw $failure;
                }
                if ($nested || $attempt >= $this->statementAttempts) {
                    throw new UserKitStorageFailure($this->retryAfterSeconds, TransientStorageFailure::DEFAULT_MESSAGE, $failure);
                }
            }
            // Random pause, so two writers that collided do not collide again in lockstep.
            usleep(random_int(0, $this->pauseMicroseconds * (2 ** ($attempt - 1))));
        }
    }

    private function translate(Throwable $failure): Throwable
    {
        if ($failure instanceof UserKitStorageFailure || $failure instanceof UserKitConflict) {
            return $failure;
        }
        if ($failure instanceof TransientStorageFailure) {
            return new UserKitStorageFailure($this->retryAfterSeconds, TransientStorageFailure::DEFAULT_MESSAGE, $failure->getPrevious() ?? $failure);
        }
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return new UserKitConflict($failure);
            }
        }

        return $failure;
    }

    /**
     * The rows the manager holds, with the data it knows them by (before a unit of work).
     *
     * @return array<int, array<string, mixed>> object id => original data
     */
    private function rows(EntityManagerInterface $manager): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $rows       = [];
        foreach ($this->managed($manager) as $id => $entity) {
            $rows[$id] = $unitOfWork->getOriginalEntityData($entity);
        }

        return $rows;
    }

    /**
     * The field values (and to-one targets) of the rows the manager holds, read through the class metadata only: no
     * change set is computed, so the ORM's own bookkeeping of the caller's pending changes is left as it was.
     *
     * @return array<int, array<string, mixed>|null> object id => values (null: a lazy row not loaded)
     */
    private function states(EntityManagerInterface $manager): array
    {
        $states = [];
        foreach ($this->managed($manager) as $id => $entity) {
            $states[$id] = $this->state($manager, $entity);
        }

        return $states;
    }

    /**
     * @return array<int, object> The entities scheduled for deletion, by object id
     */
    private function deletions(EntityManagerInterface $manager): array
    {
        $deletions = [];
        foreach ($manager->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            $deletions[spl_object_id($entity)] = $entity;
        }

        return $deletions;
    }

    /**
     * Audit QM-M1: the `remove()` calls of the failed unit are cancelled (`persist()` turns a removed entity back into a
     * managed one; its row was never deleted, or its deletion was rolled back), so a later flush of the caller never
     * deletes what the failed unit meant to. Deletions the caller scheduled before the unit stay scheduled.
     *
     * @param array<int, object> $before {@see self::deletions()} before the unit
     */
    private function cancelDeletions(EntityManagerInterface $manager, array $before): void
    {
        foreach (array_diff_key($this->deletions($manager), $before) as $entity) {
            $manager->persist($entity);
        }
    }

    /**
     * The changed collections of the managed rows: a dirty `PersistentCollection`, or another collection set on a row
     * the manager already holds.
     *
     * @return array<int, true> collection object id => true
     */
    private function dirtyCollections(EntityManagerInterface $manager): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $dirty      = [];
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($this->isUninitialized($manager, $entity)) {
                    continue;
                }
                $metadata = $manager->getClassMetadata($entity::class);
                foreach ($metadata->getAssociationNames() as $field) {
                    $collection = $metadata->isCollectionValuedAssociation($field) ? $metadata->getFieldValue($entity, $field) : null;
                    if ($collection instanceof Collection && (!$collection instanceof PersistentCollection || $collection->isDirty())) {
                        $dirty[spl_object_id($collection)] = true;
                    }
                }
            }
        }

        return $dirty;
    }

    /**
     * @return array<int, object> The scheduled insertions and the identity map, by object id
     */
    private function managed(EntityManagerInterface $manager): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $managed    = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $managed[spl_object_id($entity)] = $entity;
        }
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                $managed[spl_object_id($entity)] = $entity;
            }
        }

        return $managed;
    }

    /**
     * @return array<string, mixed>|null null for a lazy row not loaded (reading it would load it)
     */
    private function state(EntityManagerInterface $manager, object $entity): ?array
    {
        if ($this->isUninitialized($manager, $entity)) {
            return null;
        }
        $metadata = $manager->getClassMetadata($entity::class);
        $state    = [];
        foreach ($metadata->getFieldNames() as $field) {
            $state[$field] = $metadata->getFieldValue($entity, $field);
        }
        foreach ($metadata->getAssociationNames() as $field) {
            if ($metadata->isSingleValuedAssociation($field)) {
                $state[$field] = $metadata->getFieldValue($entity, $field);
            }
        }

        return $state;
    }

    private function isUninitialized(EntityManagerInterface $manager, object $entity): bool
    {
        return $manager->getUnitOfWork()->isUninitializedObject($entity);
    }

    /**
     * After a failed unit: detaches the rows it added, flushed or changed (their database state was rolled back), and the
     * rows that point at them (they would drag them back as new entities on the next flush). A row the caller held and
     * the unit did not change stays managed with the caller's pending changes.
     *
     * @param array<int, array<string, mixed>> $before {@see self::rows()} before the unit
     * @param array<int, array<string, mixed>|null> $states {@see self::states()} before the unit
     */
    private function forget(EntityManagerInterface $manager, array $before, array $states, bool $flushed): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $stale      = [];
        $kept       = [];
        foreach ($this->managed($manager) as $id => $entity) {
            if (!isset($before[$id]) || ($flushed && $before[$id] !== $unitOfWork->getOriginalEntityData($entity)) || $this->touched($manager, $entity, $states[$id] ?? null)) {
                $stale[$id] = $entity;
            } else {
                $kept[$id] = $entity;
            }
        }
        $cascaded = 0;
        do {
            $more = false;
            foreach ($kept as $id => $entity) {
                if ($this->pointsAt($manager, $entity, $stale)) {
                    $stale[$id] = $entity;
                    unset($kept[$id]);
                    ++$cascaded;
                    $more = true;
                }
            }
        } while ($more);
        foreach ($stale as $entity) {
            if ($manager->contains($entity)) {
                $manager->detach($entity);
            }
        }
        if ($cascaded > 0) {
            $this->logger?->warning('NowoUserKitBundle: a failed write detached {count} of your entities that point at rows it changed; reload them before writing them again.', ['bundle' => 'nowo_user_kit', 'count' => $cascaded]);
        }
    }

    /**
     * Whether the unit changed the row (without flushing it). A lazy row the caller held is compared with the data the
     * ORM loaded it with (a proxy holds no caller change), so a row the unit only loaded stays managed.
     *
     * @param array<string, mixed>|null $state {@see self::state()} before the unit
     */
    private function touched(EntityManagerInterface $manager, object $entity, ?array $state): bool
    {
        if ($state !== null) {
            return $state !== $this->state($manager, $entity);
        }
        if ($this->isUninitialized($manager, $entity)) {
            return false;
        }
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSet($manager->getClassMetadata($entity::class), $entity);

        return $unitOfWork->getEntityChangeSet($entity) !== [];
    }

    /**
     * @param array<int, object> $stale
     */
    private function pointsAt(EntityManagerInterface $manager, object $entity, array $stale): bool
    {
        if (count($stale) === 0 || $this->isUninitialized($manager, $entity)) {
            return false;
        }
        $metadata = $manager->getClassMetadata($entity::class);
        foreach ($metadata->getAssociationNames() as $field) {
            $target = $metadata->isSingleValuedAssociation($field) ? $metadata->getFieldValue($entity, $field) : null;
            if (is_object($target) && isset($stale[spl_object_id($target)])) {
                return true;
            }
        }

        return false;
    }
}
