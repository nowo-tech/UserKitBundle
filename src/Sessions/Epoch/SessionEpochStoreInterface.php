<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Epoch;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Per-user remember-me epoch (`sessions.remember_me_epoch`): a remember-me cookie issued before the epoch of its user
 * is refused. The default ({@see DoctrineSessionEpochStore}) keeps one row per user in `user_kit_session_epoch`; point
 * `sessions.remember_me_epoch.store` at a host service to keep it elsewhere (e.g. a column of the user table).
 */
interface SessionEpochStoreInterface
{
    /**
     * Epoch of the user (Unix timestamp), null when none was ever set.
     */
    public function epoch(SessionUser $owner): ?int;

    /**
     * Moves the epoch of the user forward to `$at` (never backwards).
     */
    public function bump(SessionUser $owner, DateTimeImmutable $at): void;

    /**
     * Deletes the epoch of the user (account deleted or erased).
     */
    public function forget(SessionUser $owner): void;
}
