<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\EventSubscriber;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Many consecutive requests on the same subscriber instance, with no `kernel.reset` in between.
 */
final class LastActivitySubscriberWorkerTest extends TestCase
{
    public function testThrottleMapIsCappedAcrossManyUsers(): void
    {
        $tokenStorage = new TokenStorage();
        $em           = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(5))->method('flush');

        $subscriber = $this->subscriber(
            ProfileRegistryFactory::single(WorkerActivityUser::class, ['last_activity' => ['update_throttle' => 600]]),
            $em,
            $tokenStorage,
            new MockClock(),
            maxThrottleEntries: 3,
        );

        foreach (['u1', 'u2', 'u3', 'u4'] as $identifier) {
            $this->request($subscriber, $tokenStorage, new WorkerActivityUser($identifier));
        }
        self::assertCount(3, $this->throttleMap($subscriber));

        // u1 was evicted, so it is written again; u4 is still throttled.
        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('u1'));
        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('u4'));
        self::assertCount(3, $this->throttleMap($subscriber));
    }

    public function testExpiredThrottleEntriesArePruned(): void
    {
        $tokenStorage = new TokenStorage();
        $clock        = new MockClock('2026-09-23 10:00:00');
        $em           = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(3))->method('flush');

        $subscriber = $this->subscriber(
            ProfileRegistryFactory::single(WorkerActivityUser::class, ['last_activity' => ['update_throttle' => 60]]),
            $em,
            $tokenStorage,
            $clock,
        );

        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('alice'));
        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('bob'));
        self::assertCount(2, $this->throttleMap($subscriber));

        $clock->sleep(61);
        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('carol'));
        self::assertSame(['default' . "\0" . 'carol'], array_keys($this->throttleMap($subscriber)));
    }

    public function testThrottleIsNotRecordedWhenDisabled(): void
    {
        $tokenStorage = new TokenStorage();
        $em           = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('flush');

        $subscriber = $this->subscriber(ProfileRegistryFactory::single(WorkerActivityUser::class), $em, $tokenStorage, new MockClock());

        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('alice'));
        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('alice'));
        self::assertSame([], $this->throttleMap($subscriber));
    }

    public function testSameIdentifierInTwoProfilesHasSeparateThrottleSlots(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'default' => $this->profileConfig(WorkerActivityUser::class),
            'admin'   => $this->profileConfig(WorkerActivityAdmin::class),
        ]);
        $tokenStorage = new TokenStorage();
        $em           = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('flush');

        $subscriber = $this->subscriber($registry, $em, $tokenStorage, new MockClock());

        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('john'));
        $admin = new WorkerActivityAdmin('john');
        $this->request($subscriber, $tokenStorage, $admin);

        self::assertNotNull($admin->getLastActivityAt());
    }

    public function testPreviousUserIsNotTouchedOnNextRequestOutsideFirewall(): void
    {
        $tokenStorage = new TokenStorage();
        $em           = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $security = $this->createMock(Security::class);
        $security->method('getFirewallConfig')->willReturnCallback(
            static fn (Request $request): FirewallConfig => new FirewallConfig('main', 'user_checker'),
        );

        $subscriber = $this->subscriber(
            ProfileRegistryFactory::single(WorkerActivityUser::class),
            $em,
            $tokenStorage,
            new MockClock(),
            security: $security,
        );

        // Request 1: behind the firewall, Alice is authenticated and her activity is stored.
        $alice = new WorkerActivityUser('alice');
        $this->request($subscriber, $tokenStorage, $alice, $this->firewalledRequest());
        $firstWrite = $alice->getLastActivityAt();
        self::assertNotNull($firstWrite);

        // Request 2: public page, anonymous visitor; the token storage still holds Alice.
        $subscriber->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/public'),
            HttpKernelInterface::MAIN_REQUEST,
        ));
        self::assertSame($firstWrite, $alice->getLastActivityAt());
    }

    public function testFirewallWithSecurityDisabledIsIgnored(): void
    {
        $tokenStorage = new TokenStorage();
        $em           = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $security = $this->createMock(Security::class);
        $security->method('getFirewallConfig')->willReturn(new FirewallConfig('assets', 'user_checker', securityEnabled: false));

        $subscriber = $this->subscriber(
            ProfileRegistryFactory::single(WorkerActivityUser::class),
            $em,
            $tokenStorage,
            new MockClock(),
            security: $security,
        );

        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('alice'), $this->firewalledRequest());
    }

    public function testClosedEntityManagerIsResetBeforeWriting(): void
    {
        $open = false;
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $em->expects($this->once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        $registry->expects($this->once())->method('resetManager')->with('default')
            ->willReturnCallback(static function () use (&$open, $em): EntityManagerInterface {
                $open = true;

                return $em;
            });

        $tokenStorage = new TokenStorage();
        $subscriber   = $this->subscriber(
            ProfileRegistryFactory::single(WorkerActivityUser::class),
            $this->createMock(EntityManagerInterface::class),
            $tokenStorage,
            new MockClock(),
            managerRegistry: $registry,
        );

        $user = new WorkerActivityUser('alice');
        $this->request($subscriber, $tokenStorage, $user);

        self::assertNotNull($user->getLastActivityAt());
    }

    public function testFlushFailureIsLoggedResetsManagerAndIsRetriedOnNextRequest(): void
    {
        $open     = true;
        $failures = 1;
        $em       = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $em->expects($this->exactly(2))->method('flush')->willReturnCallback(static function () use (&$open, &$failures): void {
            if ($failures-- > 0) {
                $open = false;

                throw new RuntimeException('Deadlock');
            }
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        $registry->expects($this->once())->method('resetManager')->with('default')
            ->willReturnCallback(static function () use (&$open, $em): EntityManagerInterface {
                $open = true;

                return $em;
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'UserKit: failed to store last activity.',
            $this->callback(static fn (array $context): bool => $context['message'] === 'Deadlock'),
        );

        $tokenStorage = new TokenStorage();
        $subscriber   = $this->subscriber(
            ProfileRegistryFactory::single(WorkerActivityUser::class, ['last_activity' => ['update_throttle' => 600]]),
            $this->createMock(EntityManagerInterface::class),
            $tokenStorage,
            new MockClock(),
            managerRegistry: $registry,
            logger: $logger,
        );

        $user = new WorkerActivityUser('alice');
        $this->request($subscriber, $tokenStorage, $user);
        self::assertSame([], $this->throttleMap($subscriber));

        $this->request($subscriber, $tokenStorage, $user);
        self::assertCount(1, $this->throttleMap($subscriber));
    }

    public function testFlushFailureWithoutRegistryDoesNotBreakTheRequest(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('Connection lost'));

        $tokenStorage = new TokenStorage();
        $subscriber   = $this->subscriber(ProfileRegistryFactory::single(WorkerActivityUser::class), $em, $tokenStorage, new MockClock());

        $this->request($subscriber, $tokenStorage, new WorkerActivityUser('alice'));

        self::assertSame([], $this->throttleMap($subscriber));
    }

    public function testUserThatIsNotAnEntityIsSkipped(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $tokenStorage = new TokenStorage();
        $subscriber   = $this->subscriber(
            ProfileRegistryFactory::single(WorkerActivityUser::class),
            $this->createMock(EntityManagerInterface::class),
            $tokenStorage,
            new MockClock(),
            managerRegistry: $registry,
        );

        $user = new WorkerActivityUser('alice');
        $this->request($subscriber, $tokenStorage, $user);

        self::assertNull($user->getLastActivityAt());
    }

    private function subscriber(
        ProfileRegistry $registry,
        EntityManagerInterface $em,
        TokenStorage $tokenStorage,
        MockClock $clock,
        ?ManagerRegistry $managerRegistry = null,
        ?Security $security = null,
        ?LoggerInterface $logger = null,
        int $maxThrottleEntries = LastActivitySubscriber::MAX_THROTTLE_ENTRIES,
    ): LastActivitySubscriber {
        return new LastActivitySubscriber(
            $registry,
            $em,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
            $clock,
            $managerRegistry,
            $security,
            $logger,
            $maxThrottleEntries,
        );
    }

    private function request(LastActivitySubscriber $subscriber, TokenStorage $tokenStorage, UserInterface $user, ?Request $request = null): void
    {
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $subscriber->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request ?? Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
        ));
    }

    private function firewalledRequest(): Request
    {
        $request = Request::create('/account');
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');

        return $request;
    }

    /**
     * @return array<string, int>
     */
    private function throttleMap(LastActivitySubscriber $subscriber): array
    {
        /** @var array<string, int> $map */
        $map = (new ReflectionProperty(LastActivitySubscriber::class, 'throttledUntil'))->getValue($subscriber);

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function profileConfig(string $userClass): array
    {
        return [
            'user_class'     => $userClass,
            'account_status' => [
                'enabled'                        => false,
                'field'                          => 'enabled',
                'invalidate_sessions_on_disable' => false,
            ],
            'last_activity' => [
                'enabled'          => true,
                'field'            => 'lastActivityAt',
                'online_threshold' => 300,
                'update_throttle'  => 600,
            ],
        ];
    }
}

class WorkerActivityUser implements UserInterface, LastActivityInterface
{
    private ?DateTimeInterface $lastActivityAt = null;

    /**
     * @param non-empty-string $identifier
     */
    public function __construct(private readonly string $identifier)
    {
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLastActivityAt(): ?DateTimeInterface
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(DateTimeInterface $lastActivityAt): void
    {
        $this->lastActivityAt = $lastActivityAt;
    }
}

final class WorkerActivityAdmin extends WorkerActivityUser
{
}
