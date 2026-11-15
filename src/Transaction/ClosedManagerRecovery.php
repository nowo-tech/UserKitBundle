<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\EventSubscriber\UnitOfWorkBoundarySubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.18.0 (Beacon spec 239): remembers the entity managers a failed UserKit unit of work left closed (the ORM closes a
 * manager whose flush failed) and replaces them at the next request, command or Messenger message boundary
 * ({@see UnitOfWorkBoundarySubscriber}), never in the middle of a request: the
 * application's next flush in the failing request fails loudly ("The EntityManager is closed") instead of silently
 * writing nothing, and the worker's next request starts with an open manager. Up to 1.17 the manager was replaced only
 * incidentally (by the last-activity listener of a later request).
 *
 * Holds the names of the managers closed in the current request only; emptied at every boundary and by `kernel.reset`.
 */
final class ClosedManagerRecovery implements ResetInterface
{
    /** @var array<string, true> manager name => closed by a failed UserKit unit */
    private array $failed = [];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * A UserKit unit of work failed with this manager closed: replace it at the next boundary.
     */
    public function remember(EntityManagerInterface $manager): void
    {
        foreach ($this->doctrine->getManagers() as $name => $candidate) {
            if ($candidate === $manager) {
                // @igor-ignore - Names of the managers closed in this request; emptied at every boundary and by kernel.reset.
                $this->failed[$name] = true;
            }
        }
    }

    /**
     * At a boundary: replaces the remembered managers that are still closed.
     */
    public function reset(): void
    {
        $failed       = $this->failed;
        $this->failed = [];
        foreach ($failed as $name => $_) {
            $manager = $this->doctrine->getManager($name);
            if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
                continue;
            }
            $this->doctrine->resetManager($name);
            $this->logger?->warning('NowoUserKitBundle: an entity manager a failed write closed was replaced at the unit-of-work boundary.', ['bundle' => 'nowo_user_kit', 'manager' => $name]);
        }
    }
}
