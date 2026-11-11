<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\EventListener;

use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

use function array_map;
use function array_merge;
use function array_values;
use function is_object;
use function spl_object_id;

/**
 * `sessions.password_change.revoke_sessions`: when the password field of a profile user changes (account page,
 * reset by e-mail, admin, CLI…), every other session of that user is signed out — the session that changed it stays
 * when it belongs to the same user. Collected on `preUpdate` (Doctrine entity listener on each profile user class),
 * applied on `postFlush`, so it hooks on what is written instead of on each password form.
 *
 * A flush inside a longer transaction is not the end of the story (HP-L2): what a rollback cannot undo waits for the
 * outermost commit, see {@see UserSessionManager::whenCommitted()}.
 *
 * FrankenPHP worker mode with the kernel kept alive and NOT reset (REQ-RUNTIME-002): the pending changes are kept per
 * entity manager together with the unit of work that collected them. A flush that fails after `preUpdate` never reaches
 * `postFlush`; its changes are dropped at the next `onFlush` of a new unit of work (the failed flush closed the manager,
 * a reset gives it a new one), on `onClear`, and at the main `kernel.request`, `kernel.terminate`, `console.terminate`
 * and the Messenger worker events. A nested flush of the same unit of work keeps them. `kernel.reset` still empties
 * them (1.14.2: before, the next flush of any user signed out every other session of the user whose change failed).
 */
final class PasswordChangeListener implements ResetInterface
{
    /** @var array<int, array{uow: WeakReference<UnitOfWork>, changes: array<int, array{user: object, except: string|null}>}> by entity manager object id */
    private array $pending = [];

    /**
     * @param string $field `sessions.password_change.field`
     */
    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly string $field = 'password',
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    /**
     * A new flush: the changes of an earlier unit of work of this manager (a flush that failed) are dropped; a nested
     * flush of the same unit of work keeps them.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        $key     = spl_object_id($manager);
        if (isset($this->pending[$key]) && $this->pending[$key]['uow']->get() !== $manager->getUnitOfWork()) {
            unset($this->pending[$key]);
        }
    }

    /**
     * Remembers a user whose password changed.
     */
    public function preUpdate(object $user, PreUpdateEventArgs $args): void
    {
        // 1.14: a user without owner is reported (log or exception, before the write) instead of being skipped silently.
        if ($args->hasChangedField($this->field) && $this->sessions->revocationOwner($user, 'password_change') instanceof SessionUser) {
            $manager = $args->getObjectManager();
            $key     = spl_object_id($manager);
            $uow     = $manager->getUnitOfWork();
            if (!isset($this->pending[$key]) || $this->pending[$key]['uow']->get() !== $uow) {
                // @igor-ignore - Changes of the flush in progress, emptied at postFlush, the next unit of work, onClear and every request/console/Messenger boundary.
                $this->pending[$key] = ['uow' => WeakReference::create($uow), 'changes' => []];
            }
            // @igor-ignore - Same as above.
            $this->pending[$key]['changes'][spl_object_id($user)] = ['user' => $user, 'except' => $this->ownSession($user)];
        }
    }

    /**
     * Signs the other sessions out once the change is stored (only the changes of the manager that flushed).
     */
    public function postFlush(?PostFlushEventArgs $args = null): void
    {
        if ($args instanceof PostFlushEventArgs) {
            $key     = spl_object_id($args->getObjectManager());
            $pending = array_values($this->pending[$key]['changes'] ?? []);
            unset($this->pending[$key]);
        } else {
            $pending       = array_merge(...array_values(array_map(static fn (array $bucket): array => array_values($bucket['changes']), $this->pending)));
            $this->pending = [];
        }
        foreach ($pending as $change) {
            $revoke = function () use ($change): void {
                $this->sessions->revokeAll($change['user'], null, RevocationReason::PasswordChanged, $change['except']);
            };
            if ($args instanceof PostFlushEventArgs) {
                $this->sessions->whenCommitted($args->getObjectManager()->getConnection(), $revoke);
            } else {
                $revoke();
            }
        }
    }

    /**
     * Drops the pending changes of a cleared entity manager.
     */
    public function onClear(OnClearEventArgs $args): void
    {
        unset($this->pending[spl_object_id($args->getObjectManager())]);
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
     * Drops pending changes (request / console / Messenger boundaries and `kernel.reset`).
     */
    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * Registry hash of the current session when it is signed in as `$user` (it keeps its session).
     */
    private function ownSession(object $user): ?string
    {
        $current = $this->tokens?->getToken()?->getUser();
        $owner   = $this->sessions->owner($user);
        if (!is_object($current) || !$owner instanceof SessionUser || $this->sessions->byOther($owner, $current)) {
            return null;
        }

        return $this->sessions->currentHash($this->requests?->getMainRequest());
    }
}
