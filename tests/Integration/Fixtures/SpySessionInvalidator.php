<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures;

use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;

final class SpySessionInvalidator implements SessionInvalidatorInterface
{
    /** @var list<object> */
    public array $invalidated = [];

    public function invalidateSessionsForUser(object $user): void
    {
        $this->invalidated[] = $user;
    }
}
