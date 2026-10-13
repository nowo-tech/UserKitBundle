<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;

use function sprintf;

/**
 * Runs the erasure guards, then every supporting eraser in one Doctrine transaction (flushed once).
 */
final class PersonalDataEraser
{
    /**
     * @param iterable<PersonalDataEraserInterface> $erasers ordered by priority
     * @param iterable<ErasureGuardInterface> $guards
     */
    public function __construct(
        private readonly iterable $erasers,
        private readonly iterable $guards,
        private readonly ManagerRegistry $doctrine,
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
        return $this->vetoes(new ErasureRequest($user, $actor, $context));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws ErasureVetoedException when a guard vetoes (nothing is erased)
     *
     * @return list<string> keys of the erasers that ran, in order
     */
    public function erase(object $user, ?object $actor = null, array $context = []): array
    {
        $request = new ErasureRequest($user, $actor, $context);
        $vetoes  = $this->vetoes($request);
        if ($vetoes !== []) {
            throw new ErasureVetoedException($vetoes);
        }

        $entityManager = $this->doctrine->getManagerForClass($user::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager manages "%s".', $user::class));
        }

        // @igor-ignore - Closure runs synchronously inside wrapInTransaction(); it is not stored on the service.
        return $entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($request): array {
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
        });
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
