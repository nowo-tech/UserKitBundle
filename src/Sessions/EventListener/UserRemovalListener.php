<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\EventListener;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Contracts\Service\ResetInterface;

use function spl_object_id;

/**
 * `sessions.user_removal.delete_sessions`: when a profile user entity is removed, its registry rows and remember-me
 * epoch are deleted ({@see UserSessionManager::forgetOwner()}) — the registry table has no foreign key to the host
 * user tables. The owner is read on `preRemove` (Doctrine entity listener on each profile user class, while the id is
 * still set) and the rows go on `postFlush`, only for entities the flush really removed; `onClear` drops what is
 * pending.
 */
final class UserRemovalListener implements ResetInterface
{
    /** @var array<int, array{user: object, owner: SessionUser}> */
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
        $owner = $this->sessions->owner($user);
        if ($owner instanceof SessionUser) {
            $this->pending[spl_object_id($user)] = ['user' => $user, 'owner' => $owner];
        }
    }

    /**
     * Deletes the sessions of the users the flush removed.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        foreach ($this->pending as $key => $removal) {
            $user = $removal['user'];
            if ($em->getMetadataFactory()->isTransient($user::class) || $uow->isScheduledForDelete($user) || $em->contains($user)) {
                // Another entity manager, or not removed by this flush (still scheduled, persisted again): keep waiting.
                continue;
            }
            unset($this->pending[$key]);
            $this->sessions->forgetOwner($removal['owner']);
        }
    }

    /**
     * Drops pending removals (entity manager cleared).
     */
    public function onClear(): void
    {
        $this->pending = [];
    }

    /**
     * Drops pending removals between requests (worker mode).
     */
    public function reset(): void
    {
        $this->pending = [];
    }
}
