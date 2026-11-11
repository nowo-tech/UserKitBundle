<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\EventListener;

use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

use function spl_object_id;

/**
 * `sessions.user_removal.delete_sessions`: when a profile user entity is removed, its registry rows and remember-me
 * epoch are deleted ({@see UserSessionManager::forgetOwner()}) — the registry table has no foreign key to the host
 * user tables. The owner is read on `preRemove` (Doctrine entity listener on each profile user class, while the id is
 * still set) and the rows go on `postFlush`, only for entities the flush really removed; `onClear` drops what is
 * pending.
 *
 * The pending removals are kept per entity manager with the unit of work they were scheduled in, so the flush of
 * another manager that also maps the user class never acts on them, and a removal of a unit of work that is gone (a
 * failed flush, then a reset manager) is dropped. FrankenPHP worker mode with the kernel kept alive and NOT reset
 * (REQ-RUNTIME-002): also emptied at the main `kernel.request`, `kernel.terminate`, `console.terminate` and the
 * Messenger worker events; `kernel.reset` still empties it.
 */
final class UserRemovalListener implements ResetInterface
{
    /** @var array<int, array{uow: WeakReference<UnitOfWork>, removals: array<int, array{user: object, owner: SessionUser}>}> by entity manager object id */
    private array $pending = [];

    public function __construct(
        private readonly UserSessionManager $sessions,
    ) {
    }

    /**
     * Remembers the owner of a user about to be removed.
     */
    public function preRemove(object $user, PreRemoveEventArgs $args): void
    {
        $owner = $this->sessions->revocationOwner($user, 'user_removal');
        if ($owner instanceof SessionUser) {
            $manager = $args->getObjectManager();
            $key     = spl_object_id($manager);
            $uow     = $manager->getUnitOfWork();
            if (!isset($this->pending[$key]) || $this->pending[$key]['uow']->get() !== $uow) {
                // @igor-ignore - Removals of this unit of work, emptied at postFlush, onClear and every request/console/Messenger boundary.
                $this->pending[$key] = ['uow' => WeakReference::create($uow), 'removals' => []];
            }
            // @igor-ignore - Same as above.
            $this->pending[$key]['removals'][spl_object_id($user)] = ['user' => $user, 'owner' => $owner];
        }
    }

    /**
     * Deletes the sessions of the users the flush removed.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $em      = $args->getObjectManager();
        $uow     = $em->getUnitOfWork();
        $manager = spl_object_id($em);
        if (!isset($this->pending[$manager])) {
            return;
        }
        if ($this->pending[$manager]['uow']->get() !== $uow) {
            // Scheduled in a unit of work this manager no longer has (a failed flush, then a reset): never applied.
            unset($this->pending[$manager]);

            return;
        }
        foreach ($this->pending[$manager]['removals'] as $key => $removal) {
            $user = $removal['user'];
            if ($uow->isScheduledForDelete($user) || $em->contains($user)) {
                // Not removed by this flush (still scheduled, persisted again): keep waiting.
                continue;
            }
            unset($this->pending[$manager]['removals'][$key]);
            $this->sessions->forgetOwner($removal['owner']);
            // The user is gone: its epoch can go too.
            $this->sessions->forgetEpoch($removal['owner']);
        }
    }

    /**
     * Drops the pending removals of a cleared entity manager (all of them without a manager).
     */
    public function onClear(?OnClearEventArgs $args = null): void
    {
        if ($args instanceof OnClearEventArgs) {
            unset($this->pending[spl_object_id($args->getObjectManager())]);

            return;
        }
        $this->pending = [];
    }

    /**
     * Request boundary (main `kernel.request` only).
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    /**
     * Drops pending removals (request / console / Messenger boundaries and `kernel.reset`).
     */
    public function reset(): void
    {
        $this->pending = [];
    }
}
