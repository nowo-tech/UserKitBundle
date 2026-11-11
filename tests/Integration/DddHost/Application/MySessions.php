<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\DddHost\Application;

use Nowo\UserKitBundle\Sessions\Model\SessionRef;
use Nowo\UserKitBundle\Sessions\SessionHashesInterface;
use Nowo\UserKitBundle\Sessions\UserSessionsInterface;

use function count;

/**
 * Application service of the DDD host: depends on the bundle ports and value objects only (no `Request`, no
 * `SessionInterface`, no entity).
 */
final readonly class MySessions
{
    public function __construct(
        private UserSessionsInterface $sessions,
        private SessionHashesInterface $hashes,
    ) {
    }

    /**
     * @return array{count: int, current: int, devices: list<string|null>}
     */
    public function overview(object $user, ?SessionRef $session): array
    {
        $rows    = $this->sessions->listRowsFor($user, $this->hashes->sessionHash($session));
        $current = 0;
        $devices = [];
        foreach ($rows as $row) {
            $current += $row->current ? 1 : 0;
            $devices[] = $row->deviceId;
        }

        return ['count' => count($rows), 'current' => $current, 'devices' => $devices];
    }

    public function signOutOthers(object $user, ?SessionRef $session): int
    {
        return $this->sessions->revokeOthers($user, $this->hashes->sessionHash($session));
    }
}
