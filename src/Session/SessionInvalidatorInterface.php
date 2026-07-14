<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Session;

interface SessionInvalidatorInterface
{
    public function invalidateSessionsForUser(object $user): void;
}
