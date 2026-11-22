<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use Nowo\UserKitBundle\Sessions\Access\SessionAccessInterface;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Since 1.23.0 (DDD-host checklist B8): dispatched by {@see SessionAccessInterface} once the checker granted an actor
 * access to one session, before the answer is returned. Ids only: the actor's and the owner's references and the
 * session's public id, so a listener decides without a user object or an entity.
 *
 * A listener may only narrow: {@see self::deny()} refuses the access; nothing can grant what the checker refused (the
 * event is not dispatched for a refusal). Keep no state in the listener (FrankenPHP worker mode, kernel reset off).
 */
final class SessionIdAccessCheckEvent
{
    /** The actor asks to see the session. */
    public const ACTION_VIEW = 'view';

    /** The actor asks to sign the session out. */
    public const ACTION_REVOKE = 'revoke';

    private bool $granted = true;

    /**
     * @param SessionUser $actor who asks
     * @param SessionUser $owner owner of the session
     * @param string $publicId public id of the session (32 lowercase hex characters)
     * @param string $action {@see self::ACTION_VIEW} or {@see self::ACTION_REVOKE}
     */
    public function __construct(
        public readonly SessionUser $actor,
        public readonly SessionUser $owner,
        public readonly string $publicId,
        public readonly string $action,
    ) {
    }

    /**
     * Whether the actor asks about one of their own sessions (exact owner match).
     */
    public function isOwn(): bool
    {
        return $this->actor->type === $this->owner->type && $this->actor->id === $this->owner->id;
    }

    /**
     * Refuses the access. There is no way back: a later listener cannot grant it again.
     */
    public function deny(): void
    {
        // @igor-ignore - Event object created per check and dispatched once; not a shared service.
        $this->granted = false;
    }

    /**
     * The decision so far.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }
}
