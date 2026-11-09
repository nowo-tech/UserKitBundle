<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use SortDirection;

use function array_column;
use function array_map;
use function bin2hex;
use function constant;
use function count;
use function enum_exists;
use function random_bytes;

/**
 * The session registry table. Lists are read through the ORM; every write is one parameterised DBAL statement, so the
 * request listeners never flush the entity manager, and a race between two first requests of the same session is one
 * ignored unique-key violation, not a closed entity manager. The table name comes from the (prefixed) metadata.
 */
final readonly class UserSessionStore
{
    /** Upper bound of the rows a page lists (REQ-PERF-001). */
    public const MAX_LIST = 200;

    /**
     * @param string|null $entityManagerName `sessions.doctrine.entity_manager` (null = the manager of the entity)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManagerName = null,
    ) {
    }

    /**
     * Registers a session.
     *
     * @return bool false when the owner's row of that session already exists (a parallel first request won)
     */
    public function insert(SessionUser $user, string $hash, SessionClient $client, DateTimeImmutable $now): bool
    {
        try {
            $this->connection()->insert($this->table(), [
                'public_id'    => bin2hex(random_bytes(16)),
                'user_type'    => $user->type,
                'user_id'      => $user->id,
                'session_hash' => $hash,
                'device_id'    => $client->deviceId,
                'browser'      => $client->browser,
                'os'           => $client->os,
                'ip'           => $client->ip,
                'created_at'   => $now,
                'last_seen_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'last_seen_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Records activity of the owner's live row of a session (and its device id when it was unknown).
     *
     * The affected-row count is not the answer: MySQL reports 0 for an UPDATE that writes the values the row already
     * holds (unless the connection sets CLIENT_FOUND_ROWS), so two requests of one session touching in the same second
     * would read as a session that is gone. On 0 affected rows the live row is looked up again; a row deleted or revoked
     * meanwhile is still not found, so the session still ends (no fail-open).
     *
     * @phpstan-impure
     *
     * @return bool whether the owner's live (not revoked) row exists and was touched
     */
    public function touch(string $hash, SessionUser $owner, DateTimeImmutable $now, ?string $deviceId = null): bool
    {
        $params   = ['hash' => $hash, 'type' => $owner->type, 'id' => $owner->id];
        $affected = $this->connection()->executeStatement(
            'UPDATE ' . $this->table() . ' SET last_seen_at = :now, device_id = COALESCE(device_id, :device) WHERE session_hash = :hash AND user_type = :type AND user_id = :id AND revoked_at IS NULL',
            ['now' => $now, 'device' => $deviceId] + $params,
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        if ($affected > 0) {
            return true;
        }

        return $this->connection()->fetchOne(
            'SELECT 1 FROM ' . $this->table() . ' WHERE session_hash = :hash AND user_type = :type AND user_id = :id AND revoked_at IS NULL' . self::sharedLock($this->connection()->getDatabasePlatform()),
            $params,
        ) !== false;
    }

    /**
     * Locking clause of the touch fallback: on MySQL/MariaDB (`REPEATABLE READ` by default) a plain `SELECT` inside a
     * transaction that already read reuses that snapshot and would see a session revoked since as live (IY-L1). A
     * locking read reads the latest committed version, as the `UPDATE` does. PostgreSQL (each statement takes a new
     * snapshot under `READ COMMITTED`) and SQLite (one writer) count matched rows and need none.
     *
     * @internal
     */
    public static function sharedLock(AbstractPlatform $platform): string
    {
        return match (true) {
            $platform instanceof MySQL80Platform       => ' FOR SHARE',
            $platform instanceof AbstractMySQLPlatform => ' LOCK IN SHARE MODE', // MariaDB, MySQL 5.7
            default                                    => '',
        };
    }

    /**
     * State of the owner's row of a session.
     *
     * @return array{revoked: bool, reason: string|null}|null null when the row does not exist
     */
    public function state(string $hash, SessionUser $owner): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT revoked_at, revoked_reason FROM ' . $this->table() . ' WHERE session_hash = :hash AND user_type = :type AND user_id = :id',
            ['hash' => $hash, 'type' => $owner->type, 'id' => $owner->id],
        );
        if ($row === false) {
            return null;
        }
        $revoked = $row['revoked_at'] !== null;

        return ['revoked' => $revoked, 'reason' => $revoked ? (string) ($row['revoked_reason'] ?? RevocationReason::SignedOut->value) : null];
    }

    /**
     * State of the owner's rows of several sessions in one query (sessions that are not the request's own, e.g. the
     * parked sessions of AuthKit's account switcher). Hashes without a row are left out.
     *
     * @param list<string> $hashes
     *
     * @return array<string, array{revoked: bool, reason: string|null, last_seen_at: DateTimeImmutable}> by hash
     */
    public function statesOf(SessionUser $owner, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $rows = $this->connection()->executeQuery(
            'SELECT session_hash, revoked_at, revoked_reason, last_seen_at FROM ' . $this->table() . ' WHERE user_type = :type AND user_id = :id AND session_hash IN (:hashes)',
            ['type' => $owner->type, 'id' => $owner->id, 'hashes' => $hashes],
            ['hashes' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
        $platform = $this->connection()->getDatabasePlatform();
        $states   = [];
        foreach ($rows as $row) {
            $revoked = $row['revoked_at'] !== null;
            /** @var DateTimeImmutable $lastSeen not-null column */
            $lastSeen                              = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row['last_seen_at'], $platform);
            $states[(string) $row['session_hash']] = [
                'revoked'      => $revoked,
                'reason'       => $revoked ? (string) ($row['revoked_reason'] ?? RevocationReason::SignedOut->value) : null,
                'last_seen_at' => $lastSeen,
            ];
        }

        return $states;
    }

    /**
     * Descending order for the ORM query builder: `SortDirection::Descending` where it exists (PHP 8.6 or
     * symfony/polyfill-php86, required by doctrine/orm 3.7, which deprecates string directions), else `'DESC'`.
     *
     * @return 'DESC'|SortDirection
     */
    private function descending(): mixed
    {
        return enum_exists('SortDirection') ? constant('SortDirection::Descending') : 'DESC';
    }

    /**
     * The session id changed (migrated): the owner's row follows it.
     */
    public function rekey(string $from, string $to, SessionUser $owner): void
    {
        $this->connection()->executeStatement(
            'UPDATE ' . $this->table() . ' SET session_hash = :to WHERE session_hash = :from AND user_type = :type AND user_id = :id',
            ['to' => $to, 'from' => $from, 'type' => $owner->type, 'id' => $owner->id],
        );
    }

    /**
     * Deletes every row of a session (the whole session was signed out).
     */
    public function deleteByHash(string $hash): void
    {
        $this->connection()->executeStatement('DELETE FROM ' . $this->table() . ' WHERE session_hash = :hash', ['hash' => $hash]);
    }

    /**
     * Deletes the owner's row of a session (logout, sign-in, owner switch in one firewall context).
     */
    public function deleteRow(string $hash, SessionUser $owner): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM ' . $this->table() . ' WHERE session_hash = :hash AND user_type = :type AND user_id = :id',
            ['hash' => $hash, 'type' => $owner->type, 'id' => $owner->id],
        );
    }

    /**
     * Deletes the owner's row of a session only if it is live: one statement, so a revocation cannot slip between
     * reading the row and deleting it.
     *
     * @return bool false when the row is revoked or does not exist
     */
    public function deleteLiveRow(string $hash, SessionUser $owner): bool
    {
        return $this->connection()->executeStatement(
            'DELETE FROM ' . $this->table() . ' WHERE session_hash = :hash AND user_type = :type AND user_id = :id AND revoked_at IS NULL',
            ['hash' => $hash, 'type' => $owner->type, 'id' => $owner->id],
        ) > 0;
    }

    /**
     * Marks the owner's row of a session revoked.
     */
    public function markRevoked(string $hash, SessionUser $owner, string $reason, DateTimeImmutable $now): void
    {
        $this->connection()->executeStatement(
            'UPDATE ' . $this->table() . ' SET revoked_at = :now, revoked_reason = :reason WHERE session_hash = :hash AND user_type = :type AND user_id = :id AND revoked_at IS NULL',
            ['now' => $now, 'reason' => $reason, 'hash' => $hash, 'type' => $owner->type, 'id' => $owner->id],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Those of `$hashes` that have a live row (a revocation of them was undone by a rolled-back savepoint).
     *
     * @param non-empty-list<string> $hashes
     *
     * @return list<string>
     */
    public function liveAmong(array $hashes): array
    {
        return array_map(strval(...), $this->connection()->executeQuery(
            'SELECT session_hash FROM ' . $this->table() . ' WHERE revoked_at IS NULL AND session_hash IN (:hashes)',
            ['hashes' => $hashes],
            ['hashes' => ArrayParameterType::STRING],
        )->fetchFirstColumn());
    }

    /**
     * The live sessions of a user (not revoked, seen since `$since`), the most recent first.
     *
     * @return list<UserSession>
     */
    public function findActive(SessionUser $user, DateTimeImmutable $since, int $limit = self::MAX_LIST): array
    {
        /** @var list<UserSession> $rows */
        $rows = $this->entityManager()->createQueryBuilder()
            ->select('s')
            ->from(UserSession::class, 's')
            ->andWhere('s.userType = :type')
            ->andWhere('s.userId = :id')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.lastSeenAt >= :since')
            ->setParameter('type', $user->type)
            ->setParameter('id', $user->id)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->orderBy('s.lastSeenAt', $this->descending())
            ->addOrderBy('s.id', $this->descending())
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Number of live sessions of a user, `$except` left out.
     */
    public function countActive(SessionUser $user, DateTimeImmutable $since, ?string $except = null): int
    {
        $qb = $this->connection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table())
            ->where('user_type = :type')
            ->andWhere('user_id = :id')
            ->andWhere('revoked_at IS NULL')
            ->andWhere('last_seen_at >= :since')
            ->setParameter('type', $user->type)
            ->setParameter('id', $user->id)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        if ($except !== null) {
            $qb->andWhere('session_hash <> :except')->setParameter('except', $except);
        }

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Public ids of the live sessions of a user other than `$except`, the least recently active first (eviction
     * order).
     *
     * @return list<string>
     */
    public function oldestActive(SessionUser $user, DateTimeImmutable $since, ?string $except, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $qb = $this->connection()->createQueryBuilder()
            ->select('public_id')
            ->from($this->table())
            ->where('user_type = :type')
            ->andWhere('user_id = :id')
            ->andWhere('revoked_at IS NULL')
            ->andWhere('last_seen_at >= :since')
            ->setParameter('type', $user->type)
            ->setParameter('id', $user->id)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->orderBy('last_seen_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit);
        if ($except !== null) {
            $qb->andWhere('session_hash <> :except')->setParameter('except', $except);
        }

        return array_map(strval(...), $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Marks the matching live rows of a user revoked.
     *
     * @param list<string>|null $publicIds only these rows (null: every live row)
     * @param string|null $deviceId only the rows of this device
     * @param string|null $except keep this session (the caller's own)
     *
     * @return list<array{public_id: string, hash: string}> the revoked rows
     */
    public function revoke(SessionUser $user, RevocationReason|string $reason, DateTimeImmutable $now, ?array $publicIds = null, ?string $deviceId = null, ?string $except = null): array
    {
        if ($publicIds === []) {
            return [];
        }
        $qb = $this->connection()->createQueryBuilder()
            ->select('public_id', 'session_hash')
            ->from($this->table())
            ->where('user_type = :type')
            ->andWhere('user_id = :id')
            ->andWhere('revoked_at IS NULL')
            ->setParameter('type', $user->type)
            ->setParameter('id', $user->id);
        if ($publicIds !== null) {
            $qb->andWhere('public_id IN (:ids)')->setParameter('ids', $publicIds, ArrayParameterType::STRING);
        }
        if ($deviceId !== null) {
            $qb->andWhere('device_id = :device')->setParameter('device', $deviceId);
        }
        if ($except !== null) {
            $qb->andWhere('session_hash <> :except')->setParameter('except', $except);
        }
        $rows = array_map(
            static fn (array $row): array => ['public_id' => (string) $row['public_id'], 'hash' => (string) $row['session_hash']],
            $qb->executeQuery()->fetchAllAssociative(),
        );
        if ($rows === []) {
            return [];
        }
        $this->connection()->executeStatement(
            'UPDATE ' . $this->table() . ' SET revoked_at = :now, revoked_reason = :reason WHERE revoked_at IS NULL AND session_hash IN (:hashes)',
            ['now' => $now, 'reason' => $reason instanceof RevocationReason ? $reason->value : $reason, 'hashes' => array_column($rows, 'hash')],
            ['now' => Types::DATETIME_IMMUTABLE, 'hashes' => ArrayParameterType::STRING],
        );

        return $rows;
    }

    /**
     * Every row of a user (personal data export).
     *
     * @return list<array<string, mixed>>
     */
    public function allFor(SessionUser $user): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT browser, os, ip, device_id, created_at, last_seen_at, revoked_at, revoked_reason FROM ' . $this->table() . ' WHERE user_type = :type AND user_id = :id ORDER BY created_at ASC, id ASC',
            ['type' => $user->type, 'id' => $user->id],
        );
    }

    /**
     * Deletes every row of a user (personal data erasure).
     */
    public function deleteFor(SessionUser $user): int
    {
        return (int) $this->connection()->executeStatement(
            'DELETE FROM ' . $this->table() . ' WHERE user_type = :type AND user_id = :id',
            ['type' => $user->type, 'id' => $user->id],
        );
    }

    /**
     * Retention purge: deletes, in batches, the rows not seen since `$before` (expired sessions, and revoked ones that
     * never came back). With `$revokedBefore`, revoked rows follow their own retention instead: they are deleted once
     * revoked before it, whatever their last activity (shorter or longer than the live rows).
     *
     * @return int deleted rows
     */
    public function purge(DateTimeImmutable $before, int $batchSize = 1000, ?DateTimeImmutable $revokedBefore = null): int
    {
        $deleted = 0;
        do {
            $qb = $this->connection()->createQueryBuilder()
                ->select('id')
                ->from($this->table())
                ->setParameter('before', $before, Types::DATETIME_IMMUTABLE);
            if ($revokedBefore instanceof DateTimeImmutable) {
                $qb->where('(revoked_at IS NULL AND last_seen_at < :before) OR (revoked_at IS NOT NULL AND revoked_at < :revoked)')
                    ->setParameter('revoked', $revokedBefore, Types::DATETIME_IMMUTABLE);
            } else {
                $qb->where('last_seen_at < :before');
            }
            $ids = $qb
                ->orderBy('id', 'ASC')
                ->setMaxResults($batchSize)
                ->executeQuery()
                ->fetchFirstColumn();
            if ($ids === []) {
                break;
            }
            $deleted += (int) $this->connection()->executeStatement(
                'DELETE FROM ' . $this->table() . ' WHERE id IN (:ids)',
                ['ids' => array_map(intval(...), $ids)],
                ['ids' => ArrayParameterType::INTEGER],
            );
        } while (count($ids) === $batchSize);

        return $deleted;
    }

    /**
     * Prefixed table name of the registry.
     */
    public function table(): string
    {
        return $this->entityManager()->getClassMetadata(UserSession::class)->getTableName();
    }

    /**
     * Connection the registry writes on.
     */
    public function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->entityManagerName !== null
            ? $this->doctrine->getManager($this->entityManagerName)
            : $this->doctrine->getManagerForClass(UserSession::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoUserKitBundle: the session registry entity is not mapped by an ORM entity manager (sessions.doctrine.entity_manager).');
        }

        return $manager;
    }
}
