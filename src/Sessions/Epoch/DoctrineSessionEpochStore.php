<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Epoch;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

use function is_string;
use function strtotime;

/**
 * Default epoch store: one row per user in `user_kit_session_epoch`, written through parameterised DBAL statements
 * (the host entity manager is never flushed). A bump only moves the epoch forward; two parallel first bumps are one
 * ignored unique-key violation.
 */
final readonly class DoctrineSessionEpochStore implements SessionEpochStoreInterface
{
    /**
     * @param string|null $entityManagerName `sessions.doctrine.entity_manager` (null = the manager of the entity)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManagerName = null,
    ) {
    }

    /**
     * Epoch of the user (Unix timestamp), null when none.
     */
    public function epoch(SessionUser $owner): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT epoch_at FROM ' . $this->table() . ' WHERE user_type = :type AND user_id = :id',
            ['type' => $owner->type, 'id' => $owner->id],
        );
        $time = is_string($value) ? strtotime($value . ' UTC') : false;

        return $time === false ? null : $time;
    }

    /**
     * Moves the epoch forward to `$at`.
     */
    public function bump(SessionUser $owner, DateTimeImmutable $at): void
    {
        $at     = $at->setTimezone(new DateTimeZone('UTC'));
        $params = ['type' => $owner->type, 'id' => $owner->id, 'at' => $at];
        $types  = ['at' => Types::DATETIME_IMMUTABLE];
        $exists = $this->connection()->fetchOne('SELECT 1 FROM ' . $this->table() . ' WHERE user_type = :type AND user_id = :id', ['type' => $owner->type, 'id' => $owner->id]);
        if ($exists !== false) {
            $this->connection()->executeStatement('UPDATE ' . $this->table() . ' SET epoch_at = :at WHERE user_type = :type AND user_id = :id AND epoch_at < :at', $params, $types);

            return;
        }
        try {
            $this->connection()->insert($this->table(), ['user_type' => $owner->type, 'user_id' => $owner->id, 'epoch_at' => $at], ['epoch_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            // A parallel first bump won: move it forward when ours is later.
            $this->connection()->executeStatement('UPDATE ' . $this->table() . ' SET epoch_at = :at WHERE user_type = :type AND user_id = :id AND epoch_at < :at', $params, $types);
        }
    }

    /**
     * Deletes the epoch of the user.
     */
    public function forget(SessionUser $owner): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM ' . $this->table() . ' WHERE user_type = :type AND user_id = :id',
            ['type' => $owner->type, 'id' => $owner->id],
        );
    }

    /**
     * Prefixed table name.
     */
    public function table(): string
    {
        return $this->entityManager()->getClassMetadata(UserSessionEpoch::class)->getTableName();
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->entityManagerName !== null
            ? $this->doctrine->getManager($this->entityManagerName)
            : $this->doctrine->getManagerForClass(UserSessionEpoch::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoUserKitBundle: the remember-me epoch entity is not mapped by an ORM entity manager (sessions.doctrine.entity_manager).');
        }

        return $manager;
    }
}
