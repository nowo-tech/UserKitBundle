<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventListener;

use Doctrine\ORM\Event\PostUpdateEventArgs;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;

use function is_array;

final class AccountDisabledListener
{
    public function __construct(
        private readonly string $userClass,
        private readonly string $enabledField,
        private readonly SessionInvalidatorInterface $sessionInvalidator,
    ) {
    }

    public function postUpdate(object $entity, PostUpdateEventArgs $event): void
    {
        if (!is_a($entity, $this->userClass, true)) {
            return;
        }

        $changeSet = $event->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
        if (!isset($changeSet[$this->enabledField])) {
            return;
        }

        $fieldChange = $changeSet[$this->enabledField];
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
