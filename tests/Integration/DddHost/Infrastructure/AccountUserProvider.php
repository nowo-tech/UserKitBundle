<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\DddHost\Infrastructure;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Domain\Account;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads the {@see SecurityAccount} read model from the XML-mapped aggregate.
 *
 * @implements UserProviderInterface<SecurityAccount>
 */
final readonly class AccountUserProvider implements UserProviderInterface
{
    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $account = $this->doctrine->getRepository(Account::class)->findOneBy(['email' => $identifier]);
        if (!$account instanceof Account) {
            throw new UserNotFoundException();
        }

        return new SecurityAccount($account->id(), $account->email(), $account->password());
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === SecurityAccount::class;
    }
}
