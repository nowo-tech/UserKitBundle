<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Presence;

use DateTimeInterface;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\ProfileSettings;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final class UserPresenceResolver
{
    public function __construct(
        private readonly ProfileRegistry $registry,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

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

        $elapsed = time() - $lastActivity->getTimestamp();

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
