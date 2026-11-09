<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface;

/**
 * Resolves nobody (a host resolver that does not know the entity class).
 */
final class NoOwnerResolver implements SessionOwnerResolverInterface
{
    public function resolve(object $user): ?SessionUser
    {
        return null;
    }
}
