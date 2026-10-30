<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventSubscriber;

use DateTimeImmutable;
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
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_filter;
use function array_key_first;
use function count;
use function max;
use function spl_object_id;
use function sprintf;

/**
 * Updates the last activity timestamp of the authenticated user on main requests, throttled per profile and user.
 *
 * The throttle map is the only state and is deliberately kept across requests of a worker (dropping it every request
 * would turn every request into a write). It holds no request data, is pruned on every write and capped at
 * {@see MAX_THROTTLE_ENTRIES}; {@see reset()} (called by `services_resetter` between requests in FrankenPHP worker
 * mode) evicts the windows that already expired.
 */
final class LastActivitySubscriber implements EventSubscriberInterface, ResetInterface
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

    /**
     * Receives the collaborators and the throttle map size cap.
     */
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

    /**
     * Subscribes to `kernel.request`.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    /**
     * Stores the last activity of the authenticated user unless throttled.
     */
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
            // @igor-ignore - Event subscriber clears request/runtime bridges; not cross-request leak.
            $user->setLastActivityAt($now);
        } elseif ($this->propertyAccessor->isWritable($user, $profile->lastActivityField)) {
            // Writes the user entity; the (readonly, stateless) accessor itself is not mutated. Igor reads a setter call
            // on $this->propertyAccessor as a mutation of that property, hence the local variable.
            $accessor = $this->propertyAccessor;
            $accessor->setValue($user, $profile->lastActivityField, $now);
        } else {
            return;
        }

        try {
            $this->store($entityManager, $user, $profile->lastActivityField, $now);
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
     * Evicts the expired throttle windows; windows still open are kept so the throttle survives the request.
     */
    public function reset(): void
    {
        $nowUnix              = $this->clock->now()->getTimestamp();
        $this->throttledUntil = array_filter($this->throttledUntil, static fn (int $until): bool => $until > $nowUnix);
    }

    /**
     * Writes only the timestamp column with a DQL UPDATE by identifier and marks the new value as the original one, so
     * the request never flushes unrelated pending changes of the unit of work (and a later flush does not write it
     * again). Falls back to a full flush when the field is not a mapped column (custom setter on an unmapped property).
     */
    private function store(EntityManagerInterface $entityManager, UserInterface $user, string $field, DateTimeImmutable $now): void
    {
        $metadata   = $entityManager->getClassMetadata($user::class);
        $identifier = $metadata->getIdentifierValues($user);
        if (!$metadata->hasField($field) || $identifier === []) {
            $entityManager->flush();

            return;
        }

        $queryBuilder = $entityManager->createQueryBuilder()
            ->update($metadata->getName(), 'u')
            ->set('u.' . $field, ':now')
            ->setParameter('now', $now, $metadata->getTypeOfField($field));
        $index = 0;
        foreach ($identifier as $name => $value) {
            $queryBuilder->andWhere(sprintf('u.%s = :id%d', $name, $index))->setParameter('id' . $index, $value);
            ++$index;
        }
        $queryBuilder->getQuery()->execute();

        if ($entityManager->contains($user)) {
            $entityManager->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($user), $field, $metadata->getFieldValue($user, $field));
        }
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
        $this->pruneExpired($nowUnix);

        if ($updateThrottle <= 0) {
            return;
        }

        while (count($this->throttledUntil) >= max(1, $this->maxThrottleEntries)) {
            unset($this->throttledUntil[array_key_first($this->throttledUntil)]);
        }

        // @igor-ignore - Event subscriber clears request/runtime bridges; not cross-request leak.
        $this->throttledUntil[$throttleKey] = $nowUnix + $updateThrottle;
    }

    /**
     * Drops the expired windows at the head of the map (insertion order = expiry order for one throttle value).
     */
    private function pruneExpired(int $nowUnix): void
    {
        foreach ($this->throttledUntil as $key => $until) {
            if ($until > $nowUnix) {
                break;
            }
            unset($this->throttledUntil[$key]);
        }
    }
}
