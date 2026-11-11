<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\DddHost\Infrastructure;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Domain\Account;

/**
 * The aggregate and its security read model are one session owner, referenced by id.
 */
final class AccountSessionOwner implements SessionOwnerResolverInterface
{
    public function resolve(object $user): ?SessionUser
    {
        return match (true) {
            $user instanceof SecurityAccount => new SessionUser(Account::class, $user->accountId),
            $user instanceof Account         => new SessionUser(Account::class, $user->id()),
            default                          => null,
        };
    }
}
