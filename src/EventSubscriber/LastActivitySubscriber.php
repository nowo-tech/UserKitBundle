<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\ProfileSettings;
use Psr\Clock\ClockInterface;
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
        private readonly ProfileRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly ClockInterface $clock,
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
        if (!$user instanceof UserInterface) {
            return;
        }

        $profile = $this->registry->resolveForObject($user);
        if (!$profile instanceof ProfileSettings || !$profile->lastActivityEnabled) {
            return;
        }

        /** @var string $userId */
        $userId = $user->getUserIdentifier();
        if ($userId === '' || $userId === '0') {
            return;
        }

        $now     = $this->clock->now();
        $nowUnix = $now->getTimestamp();
        if ($profile->updateThrottle > 0 && isset($this->lastWriteAt[$userId]) && $nowUnix - $this->lastWriteAt[$userId] < $profile->updateThrottle) {
            return;
        }

        if ($user instanceof LastActivityInterface) {
            $user->setLastActivityAt($now);
        } elseif ($this->propertyAccessor->isWritable($user, $profile->lastActivityField)) {
            $this->propertyAccessor->setValue($user, $profile->lastActivityField, $now);
        } else {
            return;
        }

        $this->entityManager->flush();
        $this->lastWriteAt[$userId] = $nowUnix;
    }
}
