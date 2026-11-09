<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface;

/**
 * Host owner resolver: the entity and its non-entity security adapter are one owner, keyed by e-mail; anything else
 * has none.
 */
final class MemberOwnerResolver implements SessionOwnerResolverInterface
{
    public function resolve(object $user): ?SessionUser
    {
        return match (true) {
            $user instanceof Member, $user instanceof SecurityMember => new SessionUser('member', $user->getUserIdentifier()),
            default                                                  => null,
        };
    }
}
