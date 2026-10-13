<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application-level firewall user_checker; records calls so tests can prove it still runs.
 */
final class AppUserChecker implements UserCheckerInterface
{
    public int $calls = 0;

    public function checkPreAuth(UserInterface $user): void
    {
        ++$this->calls;
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        ++$this->calls;
    }
}
