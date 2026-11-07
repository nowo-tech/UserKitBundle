<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\EventListener;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;

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
 */
final class PasswordChangeListener implements ResetInterface
{
    /** @var array<int, array{user: object, except: string|null}> */
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
     * Remembers a user whose password changed.
     */
    public function preUpdate(object $user, PreUpdateEventArgs $args): void
    {
        if ($args->hasChangedField($this->field)) {
            $this->pending[spl_object_id($user)] = ['user' => $user, 'except' => $this->ownSession($user)];
        }
    }

    /**
     * Signs the other sessions out once the change is stored.
     */
    public function postFlush(?PostFlushEventArgs $args = null): void
    {
        $pending       = $this->pending;
        $this->pending = [];
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
     * Drops pending changes between requests (worker mode).
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
