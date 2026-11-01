<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Sessions\Doctrine\TablePrefixListener;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;

/**
 * One row per authenticated (stateful) session. Only an HMAC of the session id is stored — never the id itself — with
 * what the owner needs to recognise the session: browser / OS names, the optional host device id, the IP as configured
 * (`sessions.client.ip`), when it started and when it was last active. Rows are written through DBAL by
 * {@see UserSessionStore} (the request listeners never flush the entity
 * manager) and read through the ORM for the pages; the entity is read-only.
 *
 * Table `user_kit_session`, prefixed with `sessions.doctrine.table_prefix`
 * ({@see TablePrefixListener}). Column names are explicit so the raw DBAL
 * statements do not depend on the host naming strategy.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_user_kit_session_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_user_kit_session_hash', columns: ['session_hash'])]
#[ORM\Index(name: 'idx_user_kit_session_user', columns: ['user_type', 'user_id', 'revoked_at', 'last_seen_at'])]
#[ORM\Index(name: 'idx_user_kit_session_last_seen', columns: ['last_seen_at'])]
class UserSession
{
    /** Base table name (before `sessions.doctrine.table_prefix`). */
    public const TABLE = 'user_kit_session';

    /**
     * @param string $publicId opaque id used in URLs (32 hex characters)
     * @param string $sessionHash HMAC-SHA256 of the session id (hex)
     * @param int|null $id database id (generated; null before insert)
     */
    public function __construct(
        #[ORM\Column(name: 'public_id', length: 32)]
        private string $publicId,
        #[ORM\Column(name: 'user_type', length: 191)]
        private string $userType,
        #[ORM\Column(name: 'user_id', length: 191)]
        private string $userId,
        #[ORM\Column(name: 'session_hash', length: 64)]
        private string $sessionHash,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
        private DateTimeImmutable $lastSeenAt,
        #[ORM\Column(name: 'browser', length: 32)]
        private string $browser = '',
        #[ORM\Column(name: 'os', length: 32)]
        private string $os = '',
        #[ORM\Column(name: 'ip', length: 64, nullable: true)]
        private ?string $ip = null,
        #[ORM\Column(name: 'device_id', length: 64, nullable: true)]
        private ?string $deviceId = null,
        #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
        private ?DateTimeImmutable $revokedAt = null,
        #[ORM\Column(name: 'revoked_reason', length: 32, nullable: true)]
        private ?string $revokedReason = null,
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
     * Opaque id used in URLs.
     */
    public function getPublicId(): string
    {
        return $this->publicId;
    }

    /**
     * Owner class (Doctrine root entity, or the security user class).
     */
    public function getUserType(): string
    {
        return $this->userType;
    }

    /**
     * Owner id (Doctrine identifier, or the security user identifier).
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * HMAC of the session id.
     */
    public function getSessionHash(): string
    {
        return $this->sessionHash;
    }

    /**
     * Start of the session.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Last recorded activity (written at most once per `sessions.touch_interval`).
     */
    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * Browser name ('' when unknown or not stored).
     */
    public function getBrowser(): string
    {
        return $this->browser;
    }

    /**
     * Operating system name ('' when unknown or not stored).
     */
    public function getOs(): string
    {
        return $this->os;
    }

    /**
     * Truncated network (`203.0.113.0/24`), keyed hash (`h:…`) or null, per `sessions.client.ip`.
     */
    public function getIp(): ?string
    {
        return $this->ip;
    }

    /**
     * Whether {@see self::getIp()} is a displayable network (not a hash).
     */
    public function hasDisplayableIp(): bool
    {
        return $this->ip !== null && !str_starts_with($this->ip, 'h:');
    }

    /**
     * Host device id, when a device id resolver is configured.
     */
    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    /**
     * When the session was revoked (it is signed out on its next request).
     */
    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * Revocation reason ({@see RevocationReason} value).
     */
    public function getRevokedReason(): ?string
    {
        return $this->revokedReason;
    }
}
