<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Presence;

/**
 * Whether a user is online (aliased to {@see UserPresenceResolver}).
 */
interface UserPresenceResolverInterface
{
    public function isOnline(object $user, ?string $profileName = null): bool;
}
