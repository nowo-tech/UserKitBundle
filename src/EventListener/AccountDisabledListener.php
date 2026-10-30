<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventListener;

use Doctrine\ORM\Event\PostUpdateEventArgs;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;

use function is_array;

/**
 * Doctrine entity listener that invalidates the sessions of a user whose account was just disabled.
 */
final readonly class AccountDisabledListener
{
    /**
     * Receives the profile registry and the session invalidator.
     */
    public function __construct(
        private ProfileRegistry $registry,
        private SessionInvalidatorInterface $sessionInvalidator,
    ) {
    }

    /**
     * Invalidates the sessions when the profile enabled field changed from true to false.
     */
    public function postUpdate(object $entity, PostUpdateEventArgs $event): void
    {
        $profile = $this->registry->resolveForObject($entity);
        if (!$profile instanceof ProfileSettings || !$profile->invalidateSessionsOnDisable) {
            return;
        }

        $changeSet = $event->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
        if (!isset($changeSet[$profile->enabledField])) {
            return;
        }

        $fieldChange = $changeSet[$profile->enabledField];
        if (!is_array($fieldChange)) {
            return;
        }

        [$previous, $current] = $fieldChange;
        if ($this->normalizeBool($previous) && $this->normalizeBool($current) === false) {
            $this->sessionInvalidator->invalidateSessionsForUser($entity);
        }
    }

    private function normalizeBool(mixed $value): bool
    {
        return (bool) $value;
    }
}
