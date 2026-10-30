<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Presence;

use DateTimeInterface;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\ProfileSettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Tells whether a user is online from its last activity timestamp and the profile online threshold.
 */
final readonly class UserPresenceResolver
{
    /**
     * Receives the profile registry, the property accessor and the clock.
     */
    public function __construct(
        private ProfileRegistry $registry,
        private PropertyAccessorInterface $propertyAccessor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Whether the user was active within the online threshold of its (or the given) profile.
     */
    public function isOnline(object $user, ?string $profileName = null): bool
    {
        $profile = $profileName !== null
            ? $this->registry->getByName($profileName)
            : $this->registry->resolveForObject($user);

        if (!$profile instanceof ProfileSettings) {
            return false;
        }

        $lastActivity = $this->resolveLastActivity($user, $profile->lastActivityField);
        if (!$lastActivity instanceof DateTimeInterface) {
            return false;
        }

        $elapsed = $this->clock->now()->getTimestamp() - $lastActivity->getTimestamp();

        return $elapsed <= $profile->onlineThreshold;
    }

    private function resolveLastActivity(object $user, string $lastActivityField): ?DateTimeInterface
    {
        if ($user instanceof LastActivityInterface) {
            return $user->getLastActivityAt();
        }

        if (!$this->propertyAccessor->isReadable($user, $lastActivityField)) {
            return null;
        }

        $value = $this->propertyAccessor->getValue($user, $lastActivityField);

        return $value instanceof DateTimeInterface ? $value : null;
    }
}
