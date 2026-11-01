<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Sessions\Limit\SessionLimitResolverInterface;
use Nowo\UserKitBundle\Sessions\Model\SessionLimit;
use Nowo\UserKitBundle\Sessions\Model\SessionLimitPolicy;

use function in_array;

/**
 * Host limit resolver: administrators unlimited, everybody else one session (refuse new).
 */
final class RoleLimitResolver implements SessionLimitResolverInterface
{
    public function resolve(object $user): SessionLimit
    {
        return $user instanceof Member && in_array('ROLE_ADMIN', $user->getRoles(), true)
            ? SessionLimit::unlimited()
            : new SessionLimit(1, SessionLimitPolicy::RejectNew);
    }
}
