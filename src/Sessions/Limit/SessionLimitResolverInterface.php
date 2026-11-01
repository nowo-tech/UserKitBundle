<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Limit;

use Nowo\UserKitBundle\Sessions\Model\SessionLimit;

/**
 * Concurrent-session limit of a user. The default ({@see ConfiguredSessionLimitResolver}) applies
 * `sessions.limit.max_sessions` / `policy` to everyone; point `sessions.limit.resolver` at a host service for limits
 * per role, plan, organization… Called at sign-in only, never per request.
 */
interface SessionLimitResolverInterface
{
    /**
     * Limit of the user signing in.
     */
    public function resolve(object $user): SessionLimit;
}
