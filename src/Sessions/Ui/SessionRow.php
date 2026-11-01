<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Ui;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;

/**
 * One row of the session lists, as handed to the templates: what the owner needs to recognise the session and
 * whether it is the viewer's own. The session hash never reaches a template (a `dump()` in a host override cannot
 * print it); `ip` is the displayable network only (hashed IPs are null).
 */
final readonly class SessionRow
{
    public function __construct(
        public string $publicId,
        public string $browser,
        public string $os,
        public ?string $ip,
        public ?string $deviceId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastSeenAt,
        public bool $current = false,
    ) {
    }

    /**
     * Row of a registry entry; `$currentHash` marks the viewer's own session.
     */
    public static function of(UserSession $session, ?string $currentHash = null): self
    {
        return new self(
            $session->getPublicId(),
            $session->getBrowser(),
            $session->getOs(),
            $session->hasDisplayableIp() ? $session->getIp() : null,
            $session->getDeviceId(),
            $session->getCreatedAt(),
            $session->getLastSeenAt(),
            $currentHash !== null && hash_equals($session->getSessionHash(), $currentHash),
        );
    }
}
