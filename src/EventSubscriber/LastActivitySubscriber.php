<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\ProfileSettings;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function array_key_first;
use function count;
use function max;

final class LastActivitySubscriber implements EventSubscriberInterface
{
    /** Upper bound of throttle entries kept per worker (oldest entries are evicted first). */
    public const MAX_THROTTLE_ENTRIES = 10000;

    /**
     * Throttle window end (unix time) per profile and user identifier, in insertion order. Expired
     * entries at the head are pruned on every write and the size is capped, so the map stays
     * bounded in long-running workers.
     *
     * @var array<string, int>
     */
    private array $throttledUntil = [];

    public function __construct(
        private readonly ProfileRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly ClockInterface $clock,
        private readonly ?ManagerRegistry $managerRegistry = null,
        private readonly ?Security $security = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $maxThrottleEntries = self::MAX_THROTTLE_ENTRIES,
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
        if (!$event->isMainRequest() || !$this->isBehindSecuredFirewall($event->getRequest())) {
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

        $now         = $this->clock->now();
        $nowUnix     = $now->getTimestamp();
        $throttleKey = $profile->name . "\0" . $userId;
        if (isset($this->throttledUntil[$throttleKey]) && $this->throttledUntil[$throttleKey] > $nowUnix) {
            return;
        }

        $entityManager = $this->resolveEntityManager($user);
        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        if ($user instanceof LastActivityInterface) {
            $user->setLastActivityAt($now);
        } elseif ($this->propertyAccessor->isWritable($user, $profile->lastActivityField)) {
            $this->propertyAccessor->setValue($user, $profile->lastActivityField, $now);
        } else {
            return;
        }

        try {
            $entityManager->flush();
        } catch (Throwable $exception) {
            $this->logger?->warning('UserKit: failed to store last activity.', [
                'bundle'    => 'nowo-tech/user-kit-bundle',
                'action'    => 'store_last_activity',
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);
            $this->resetClosedManagers();

            return;
        }

        $this->remember($throttleKey, $nowUnix, $profile->updateThrottle);
    }

    /**
     * Without a kernel reset between requests (worker mode), the token storage only reflects the
     * current visitor on requests handled by a firewall with security enabled.
     */
    private function isBehindSecuredFirewall(Request $request): bool
    {
        if (!$this->security instanceof Security) {
            return true;
        }

        if (!$request->attributes->has('_firewall_context')) {
            return false;
        }

        return $this->security->getFirewallConfig($request)?->isSecurityEnabled() === true;
    }

    private function resolveEntityManager(UserInterface $user): ?EntityManagerInterface
    {
        if (!$this->managerRegistry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->managerRegistry->getManagerForClass($user::class);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->resetClosedManagers();
            $manager = $this->managerRegistry->getManagerForClass($user::class);
        }

        return $manager instanceof EntityManagerInterface && $manager->isOpen() ? $manager : null;
    }

    private function resetClosedManagers(): void
    {
        if (!$this->managerRegistry instanceof ManagerRegistry) {
            return;
        }

        foreach ($this->managerRegistry->getManagers() as $name => $manager) {
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->managerRegistry->resetManager($name);
            }
        }
    }

    private function remember(string $throttleKey, int $nowUnix, int $updateThrottle): void
    {
        unset($this->throttledUntil[$throttleKey]);

        foreach ($this->throttledUntil as $key => $until) {
            if ($until > $nowUnix) {
                break;
            }
            unset($this->throttledUntil[$key]);
        }

        if ($updateThrottle <= 0) {
            return;
        }

        while (count($this->throttledUntil) >= max(1, $this->maxThrottleEntries)) {
            unset($this->throttledUntil[array_key_first($this->throttledUntil)]);
        }

        $this->throttledUntil[$throttleKey] = $nowUnix + $updateThrottle;
    }
}
