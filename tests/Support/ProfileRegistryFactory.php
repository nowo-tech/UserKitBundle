<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Support;

use Nowo\UserKitBundle\Profile\ProfileRegistry;

final class ProfileRegistryFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function single(string $userClass, array $overrides = [], string $profileName = 'default'): ProfileRegistry
    {
        return self::fromProfiles([
            $profileName => array_replace_recursive([
                'user_class'     => $userClass,
                'account_status' => [
                    'enabled'                        => true,
                    'field'                          => 'enabled',
                    'invalidate_sessions_on_disable' => false,
                ],
                'last_activity' => [
                    'enabled'          => true,
                    'field'            => 'lastActivityAt',
                    'online_threshold' => 300,
                    'update_throttle'  => 0,
                ],
            ], $overrides),
        ], $profileName);
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    public static function fromProfiles(array $profiles, string $defaultProfileName = 'default'): ProfileRegistry
    {
        return new ProfileRegistry($profiles, $defaultProfileName);
    }
}
