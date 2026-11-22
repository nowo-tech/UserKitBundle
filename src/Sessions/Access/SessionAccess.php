<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Access;

use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Event\SessionIdAccessCheckEvent;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Security\SessionsAccessCheckerInterface;
use Nowo\UserKitBundle\Sessions\Security\SessionsIdAccessCheckerInterface;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function preg_match;

/**
 * Since 1.23.0: {@see SessionAccessInterface} on the session registry and the configured access checker. Stateless:
 * every call reads the row by its exact public id and asks the checker again.
 *
 * @internal Hosts depend on the interface
 */
final readonly class SessionAccess implements SessionAccessInterface
{
    /** Syntax of a public id (the generator's: 16 random bytes in lowercase hex). */
    private const PUBLIC_ID_PATTERN = '/\A[0-9a-f]{32}\z/';

    public function __construct(
        private UserSessionStore $store,
        private UserSessionManager $sessions,
        private SessionsAccessCheckerInterface $checker,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function canViewSession(SessionActor|SessionUser $actor, string $publicId): bool
    {
        return $this->decide($actor, $publicId, SessionIdAccessCheckEvent::ACTION_VIEW);
    }

    public function canRevokeSession(SessionActor|SessionUser $actor, string $publicId): bool
    {
        return $this->decide($actor, $publicId, SessionIdAccessCheckEvent::ACTION_REVOKE);
    }

    private function decide(SessionActor|SessionUser $actor, string $publicId, string $action): bool
    {
        $actor = $actor instanceof SessionActor ? $actor : new SessionActor($actor);
        if (preg_match(self::PUBLIC_ID_PATTERN, $publicId) !== 1) {
            return false;
        }
        try {
            if (!$this->checker instanceof SessionsIdAccessCheckerInterface) {
                $this->logger?->warning('NowoUserKitBundle: the session access checker {checker} does not implement SessionsIdAccessCheckerInterface; session access by id is refused.', ['checker' => $this->checker::class]);

                return false;
            }
            $row = $this->store->sessionByPublicId($publicId);
            if ($row === null || $row['revoked']) {
                return false;
            }
            if ($action === SessionIdAccessCheckEvent::ACTION_VIEW && $row['last_seen_at'] < $this->sessions->aliveSince()) {
                return false;
            }
            $owner   = $row['owner'];
            $granted = $actor->is($owner)
                ? $this->checker->canAccessOwnById($actor) || $this->checker->canManageById($actor, $owner)
                : $this->checker->canManageById($actor, $owner);
            if (!$granted) {
                return false;
            }
            $event = new SessionIdAccessCheckEvent($actor->user, $owner, $publicId, $action);
            $this->dispatcher?->dispatch($event);

            return $event->isGranted();
        } catch (Throwable $exception) {
            $this->logger?->warning('NowoUserKitBundle: a session access check by id failed; answered as a refusal.', ['action' => $action, 'exception' => $exception::class]);

            return false;
        }
    }
}
