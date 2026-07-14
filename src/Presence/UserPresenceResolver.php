<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Presence;

use DateTimeInterface;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final class UserPresenceResolver
{
    public function __construct(
        private readonly int $onlineThreshold,
        private readonly string $lastActivityField,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function isOnline(object $user): bool
    {
        $lastActivity = $this->resolveLastActivity($user);
        if (!$lastActivity instanceof DateTimeInterface) {
            return false;
        }

        $elapsed = time() - $lastActivity->getTimestamp();

        return $elapsed <= $this->onlineThreshold;
    }

    private function resolveLastActivity(object $user): ?DateTimeInterface
    {
        if ($user instanceof LastActivityInterface) {
            return $user->getLastActivityAt();
        }

        if (!$this->propertyAccessor->isReadable($user, $this->lastActivityField)) {
            return null;
        }

        $value = $this->propertyAccessor->getValue($user, $this->lastActivityField);

        return $value instanceof DateTimeInterface ? $value : null;
    }
}
