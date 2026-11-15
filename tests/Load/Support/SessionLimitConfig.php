<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load\Support;

/**
 * The configuration of the session limit race (shared by the test and its worker processes).
 */
final class SessionLimitConfig
{
    /**
     * @return array<string, mixed> `nowo_user_kit`
     */
    public static function userKit(string $policy): array
    {
        return ['sessions' => [
            'enabled' => true,
            'limit'   => ['max_sessions' => 1, 'policy' => $policy],
            'client'  => ['device_id_resolver' => SlowDeviceId::class],
        ]];
    }
}
