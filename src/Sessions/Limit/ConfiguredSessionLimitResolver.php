<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Limit;

use Nowo\UserKitBundle\Sessions\Model\SessionLimit;
use Nowo\UserKitBundle\Sessions\Model\SessionLimitPolicy;

/**
 * The same limit for every user: `sessions.limit.max_sessions` (null = unlimited) with `sessions.limit.policy`.
 */
final readonly class ConfiguredSessionLimitResolver implements SessionLimitResolverInterface
{
    private SessionLimit $limit;

    public function __construct(?int $maxSessions = null, string $policy = 'evict_oldest')
    {
        $this->limit = new SessionLimit($maxSessions, SessionLimitPolicy::from($policy));
    }

    /**
     * The configured limit.
     */
    public function resolve(object $user): SessionLimit
    {
        return $this->limit;
    }
}
