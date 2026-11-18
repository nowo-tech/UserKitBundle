<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\Model\EnabledUserTrait;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, AccountStatusInterface
{
    use EnabledUserTrait;

    /** 1.20 owner images `pointer_store: column` (kind `avatar`). */
    #[ORM\Column(name: 'avatar_file_id', type: 'string', length: 64, nullable: true)]
    private ?string $avatarFileId = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 180)]
        private string $username,
        #[ORM\Column(type: 'string')]
        private string $password,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->username !== '' ? $this->username : 'anonymous';
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }

    public function getAvatarFileId(): ?string
    {
        return $this->avatarFileId;
    }

    public function setAvatarFileId(?string $avatarFileId): void
    {
        $this->avatarFileId = $avatarFileId;
    }
}
