<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Profile;

final readonly class ProfileSettings
{
    /**
     * @param class-string $userClass
     */
    public function __construct(
        public string $name,
        public string $userClass,
        public bool $accountStatusEnabled,
        public string $enabledField,
        public bool $invalidateSessionsOnDisable,
        public bool $lastActivityEnabled,
        public string $lastActivityField,
        public int $onlineThreshold,
        public int $updateThrottle,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        /** @var class-string $userClass */
        $userClass = $config['user_class'];

        return new self(
            name: $name,
            userClass: $userClass,
            accountStatusEnabled: $config['account_status']['enabled'],
            enabledField: $config['account_status']['field'],
            invalidateSessionsOnDisable: $config['account_status']['invalidate_sessions_on_disable'],
            lastActivityEnabled: $config['last_activity']['enabled'],
            lastActivityField: $config['last_activity']['field'],
            onlineThreshold: $config['last_activity']['online_threshold'],
            updateThrottle: $config['last_activity']['update_throttle'],
        );
    }
}
