<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface;
use RuntimeException;

/**
 * Host owner resolver that maps members (keyed by e-mail) and throws for any other object, as a strict host resolver
 * might for an actor it does not know (audit YQ-L2). The message carries personal data, which must never be logged.
 */
final class ThrowingActorOwnerResolver implements SessionOwnerResolverInterface
{
    public function resolve(object $user): SessionUser
    {
        if ($user instanceof Member || $user instanceof SecurityMember) {
            return new SessionUser('member', $user->getUserIdentifier());
        }

        throw new RuntimeException('Unmapped actor of alice@example.com');
    }
}
