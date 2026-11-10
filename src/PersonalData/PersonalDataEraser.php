<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function sprintf;

/**
 * Runs the erasure guards, then every supporting eraser in one Doctrine transaction (flushed once): its own, or the
 * host's when one is already open on the user's entity manager.
 */
final readonly class PersonalDataEraser implements PersonalDataErasureInterface
{
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
    ) {
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
     * the erasers join it (the host commits or rolls back); otherwise the eraser opens and commits its own.
     *
     * @param array<string, mixed> $context
     *
     * @throws ErasureVetoedException when a guard vetoes (nothing is erased)
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

        // The host's unit of work is open (an application service erasing inside its own transaction): join it — flush
        // once, and leave commit, rollback and closing the entity manager to the host. Otherwise, own the transaction.
        $connection = $entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            try {
                $ran = $this->runErasers($request, $entityManager);
            } catch (Throwable $exception) {
                // Earlier erasers' writes are pending or already executed: the host must not commit a partial erasure.
                // @igor-ignore - marks the host's open transaction only; DBAL clears the flag when that transaction ends.
                $connection->setRollbackOnly();

                throw $exception;
            }
        } else {
            // @igor-ignore - Closure runs synchronously inside wrapInTransaction(); it is not stored on the service.
            $ran = $entityManager->wrapInTransaction(fn (EntityManagerInterface $entityManager): array => $this->runErasers($request, $entityManager));
        }
        // Marks in the revocation cache of the session eraser: what was erased is committed now, not at the end of the request.
        $this->commits?->release();

        return $ran;
    }

    /**
     * Every supporting eraser, then one flush.
     *
     * @return list<string> keys of the erasers that ran, in order
     */
    private function runErasers(ErasureRequest $request, EntityManagerInterface $entityManager): array
    {
        $ran = [];
        foreach ($this->erasers as $eraser) {
            if (!$eraser->supports($request->user)) {
                continue;
            }

            $eraser->erase($request);
            $ran[] = $eraser->getKey();
        }

        $entityManager->flush();

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
