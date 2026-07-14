<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventSubscriber;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class LastActivitySubscriber implements EventSubscriberInterface
{
    /** @var array<string, int> */
    private array $lastWriteAt = [];

    public function __construct(
        private readonly string $userClass,
        private readonly string $lastActivityField,
        private readonly int $updateThrottle,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface || !is_a($user, $this->userClass, true)) {
            return;
        }

        /** @var string $userId */
        $userId = $user->getUserIdentifier();
        if ($userId === '' || $userId === '0') {
            return;
        }

        $now = time();
        if ($this->updateThrottle > 0 && isset($this->lastWriteAt[$userId]) && $now - $this->lastWriteAt[$userId] < $this->updateThrottle) {
            return;
        }

        $timestamp = new DateTimeImmutable();
        if ($user instanceof LastActivityInterface) {
            $user->setLastActivityAt($timestamp);
        } elseif ($this->propertyAccessor->isWritable($user, $this->lastActivityField)) {
            $this->propertyAccessor->setValue($user, $this->lastActivityField, $timestamp);
        } else {
            return;
        }

        $this->entityManager->flush();
        $this->lastWriteAt[$userId] = $now;
    }
}
