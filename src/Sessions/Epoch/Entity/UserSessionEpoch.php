<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Epoch\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Sessions\Doctrine\TablePrefixListener;
use Nowo\UserKitBundle\Sessions\Epoch\DoctrineSessionEpochStore;

/**
 * Remember-me epoch of one user (`sessions.remember_me_epoch` with the default store): remember-me cookies issued
 * before `epoch_at` are refused. Written through DBAL by {@see DoctrineSessionEpochStore}; the entity only carries the
 * mapping (table `user_kit_session_epoch`, prefixed by {@see TablePrefixListener}). Mapped only when the epoch is
 * enabled with the default store.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_user_kit_session_epoch_owner', columns: ['user_type', 'user_id'])]
class UserSessionEpoch
{
    /** Base table name (before `sessions.doctrine.table_prefix`). */
    public const TABLE = 'user_kit_session_epoch';

    public function __construct(
        #[ORM\Column(name: 'user_type', length: 191)]
        private string $userType,
        #[ORM\Column(name: 'user_id', length: 191)]
        private string $userId,
        #[ORM\Column(name: 'epoch_at', type: 'datetime_immutable')]
        private DateTimeImmutable $epochAt,
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column(name: 'id', type: 'integer')]
        private ?int $id = null,
    ) {
    }

    /**
     * Database id (null before insert).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Owner class.
     */
    public function getUserType(): string
    {
        return $this->userType;
    }

    /**
     * Owner identifier.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Remember-me cookies issued before this are refused.
     */
    public function getEpochAt(): DateTimeImmutable
    {
        return $this->epochAt;
    }
}
