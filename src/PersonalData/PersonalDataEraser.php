<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function sprintf;

/**
 * Runs the erasure guards, then every supporting eraser in one Doctrine transaction (flushed once), through UserKit's
 * own transaction runner ({@see UserKitTransactionRunner}, 1.19.0): its own transaction, or a savepoint of the host's
 * when one is already open on the user's entity manager.
 */
final readonly class PersonalDataEraser implements PersonalDataErasureInterface
{
    private UserKitTransactionRunner $runner;

    /**
     * Receives the tagged erasers and guards plus the Doctrine registry.
     *
     * @param iterable<PersonalDataEraserInterface> $erasers ordered by priority
     * @param iterable<ErasureGuardInterface> $guards
     */
    public function __construct(
        private iterable $erasers,
        private iterable $guards,
        private ManagerRegistry $doctrine,
        private ?CommitQueue $commits = null,
        ?UserKitTransactionRunner $runner = null,
    ) {
        $this->runner = $runner ?? new UserKitTransactionRunner($doctrine);
    }

    /**
     * Pre-flight for the UI ("can this account be erased?"): every veto, nothing erased.
     *
     * @param array<string, mixed> $context
     *
     * @return list<ErasureVeto>
     */
    public function check(object $user, ?object $actor = null, array $context = []): array
    {
        return $this->vetoes($this->request($user, $actor, $context));
    }

    /**
     * Runs the guards, then every supporting eraser in one transaction, flushing once. Inside an open host transaction
     * the erasers nest in it (a savepoint; the host commits or rolls back, and a failed erasure marks it rollback-only);
     * otherwise the eraser opens and commits its own.
     *
     * @param array<string, mixed> $context
     *
     * @throws ErasureVetoedException when a guard vetoes (nothing is erased)
     * @throws UserKitStorageFailure when the storage failed transiently (deadlock, lock wait; nothing is erased)
     * @throws UserKitConflict when the erasure lost a unique key race (nothing is erased)
     *
     * @return list<string> keys of the erasers that ran, in order
     */
    public function erase(object $user, ?object $actor = null, array $context = []): array
    {
        // 1.12: the identifier is captured before any eraser can anonymise it.
        $request = $this->request($user, $actor, $context);
        $vetoes  = $this->vetoes($request);
        if ($vetoes !== []) {
            throw new ErasureVetoedException($vetoes);
        }

        $entityManager = $this->doctrine->getManagerForClass($user::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager manages "%s".', $user::class));
        }

        // 1.19.0 (Beacon spec 239): one unit of work of UserKit's own runner, flushed once inside its transaction. Inside
        // an open host transaction it nests (a savepoint): the host commits or rolls back the rest. After a failure no
        // level of the runner stays open, the manager is neither reset nor cleared (only the rows the erasure touched
        // are detached; a manager a failed flush closed is replaced at the next boundary), a deadlock or lock wait is a
        // UserKitStorageFailure (503) and a lost unique key race a UserKitConflict (409).
        $connection = $entityManager->getConnection();
        try {
            // @igor-ignore - Closure runs synchronously inside the runner; it is not stored on the service.
            $ran = $this->runner->runFor($entityManager, fn (): array => $this->runErasers($request));
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                // The host's transaction is still open: it must not commit without the erasure (1.18 behaviour). The
                // rollback-only flag is cleared only by the outermost rollBack(), not by Connection::close(): the host
                // must roll back (not just close) after a failed erasure, or its next commit on this connection throws
                // CommitFailedRollbackOnly.
                // @igor-ignore - marks the host's open transaction, which the host must roll back (see above)
                $connection->setRollbackOnly();
            }

            throw $exception;
        }
        // Marks in the revocation cache of the session eraser: what was erased is committed now, not at the end of the request.
        $this->commits?->release();

        return $ran;
    }

    /**
     * Every supporting eraser (the runner flushes once afterwards).
     *
     * @return list<string> keys of the erasers that ran, in order
     */
    private function runErasers(ErasureRequest $request): array
    {
        $ran = [];
        foreach ($this->erasers as $eraser) {
            if (!$eraser->supports($request->user)) {
                continue;
            }

            $eraser->erase($request);
            $ran[] = $eraser->getKey();
        }

        return $ran;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function request(object $user, ?object $actor, array $context): ErasureRequest
    {
        return new ErasureRequest($user, $actor, $context, $user instanceof UserInterface ? $user->getUserIdentifier() : null);
    }

    /**
     * @return list<ErasureVeto>
     */
    private function vetoes(ErasureRequest $request): array
    {
        $vetoes = [];
        foreach ($this->guards as $guard) {
            $veto = $guard->check($request);
            if ($veto instanceof ErasureVeto) {
                $vetoes[] = $veto;
            }
        }

        return $vetoes;
    }
}
