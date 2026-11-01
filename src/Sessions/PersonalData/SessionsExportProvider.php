<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\PersonalData;

use DateTimeImmutable;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;

use function array_map;
use function is_string;

/**
 * Personal data export section `sessions` (GDPR art. 15 / 20): every registry row of the user — browser, OS, stored
 * IP form, device id, start, last activity, revocation. Registered when both `personal_data.enabled` and
 * `sessions.enabled` are true. The session hash is never exported.
 */
final readonly class SessionsExportProvider implements PersonalDataExportProviderInterface
{
    public function __construct(
        private UserSessionManager $sessions,
        private UserSessionStore $store,
    ) {
    }

    /**
     * Section key.
     */
    public function getSectionKey(): string
    {
        return 'sessions';
    }

    /**
     * Users the registry can reference.
     */
    public function supports(object $user): bool
    {
        return $this->sessions->owner($user) instanceof SessionUser;
    }

    /**
     * The rows of the user.
     *
     * @return list<array<string, mixed>>
     */
    public function export(object $user): array
    {
        $owner = $this->sessions->owner($user);
        if (!$owner instanceof SessionUser) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'browser'        => $row['browser'],
            'os'             => $row['os'],
            'ip'             => $row['ip'],
            'device_id'      => $row['device_id'],
            'created_at'     => self::date($row['created_at']),
            'last_seen_at'   => self::date($row['last_seen_at']),
            'revoked_at'     => self::date($row['revoked_at']),
            'revoked_reason' => $row['revoked_reason'],
        ], $this->store->allFor($owner));
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
