<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Owner;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;

/**
 * Maps a user object to the owner reference its registry rows and remember-me epoch are stored under
 * (`sessions.owner.resolver`; default {@see SessionUserResolver}: Doctrine root class + id, else class + user identifier).
 *
 * Every object that stands for the same person must resolve to the same {@see SessionUser}: the security user signed in
 * at the firewall, and the entity the Doctrine listeners (`password_change`, `user_removal`,
 * `invalidate_sessions_on_disable`) see. A host whose security user is a non-entity adapter around a domain user maps
 * both to one owner here (for example `new SessionUser(DomainUser::class, $id)`).
 *
 * The owner type must be unique per identity space (the domain class name): two user classes sharing a type and an id
 * would be one owner (shared sessions, limit and remember-me epoch).
 */
interface SessionOwnerResolverInterface
{
    /**
     * Owner reference of the user, or null when the user cannot be registered.
     */
    public function resolve(object $user): ?SessionUser;
}
