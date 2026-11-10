<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\AliasedActivityUser;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\TrackedUser;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\TestKernel;
use Nowo\UserKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function dirname;

/**
 * Last activity is written with a targeted UPDATE: pending changes of other entities are never flushed.
 */
final class LastActivityKernelTest extends TestCase
{
    private ?TestKernel $kernel = null;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel();
        $this->kernel->boot();

        $entityManager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        (new SchemaTool($entityManager))->createSchema([
            $entityManager->getClassMetadata(TrackedUser::class),
            $entityManager->getClassMetadata(AliasedActivityUser::class),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->kernel instanceof TestKernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove(dirname($cacheDir));
            $this->kernel = null;
        }
    }

    public function testUpdatesOnlyTheTimestampColumnOfTheUser(): void
    {
        $user  = new TrackedUser('alice', 'Alice');
        $other = new TrackedUser('bob', 'Bob');
        $this->entityManager->persist($user);
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        // Pending, unflushed changes made earlier in the request must stay pending.
        $user->displayName  = 'Alice (edited)';
        $other->displayName = 'Bob (edited)';

        $this->subscriber($user)->onKernelRequest($this->event());

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT display_name, lastActivityAt AS last_activity_at FROM user_kit_tracked_user WHERE username = ?',
            ['alice'],
        );
        $this->assertIsArray($row);
        $this->assertSame('Alice', $row['display_name']);
        $this->assertSame('2026-10-28 10:00:00', $row['last_activity_at']);
        $this->assertSame('Bob', $this->entityManager->getConnection()->fetchOne(
            'SELECT display_name FROM user_kit_tracked_user WHERE username = ?',
            ['bob'],
        ));

        // The new timestamp is the original value: the next flush only writes the host's own change.
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $this->assertSame(['displayName'], array_keys($unitOfWork->getEntityChangeSet($user)));
    }

    public function testACustomSetterOnAnUnmappedFieldWritesOnlyTheColumnsItChanged(): void
    {
        $user  = new AliasedActivityUser('carol', 'Carol');
        $other = new TrackedUser('dave', 'Dave');
        $this->entityManager->persist($user);
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        // Pending changes of the request (on the user itself, on another row, and a new entity) must stay pending.
        $user->displayName  = 'Carol (invalid form)';
        $other->displayName = 'Dave (edited)';
        $this->entityManager->persist(new TrackedUser('erin', 'Erin'));

        $this->subscriber($user, AliasedActivityUser::class)->onKernelRequest($this->event());

        $connection = $this->entityManager->getConnection();
        $this->assertSame(['display_name' => 'Carol', 'seen_at' => '2026-10-28 10:00:00'], $connection->fetchAssociative(
            'SELECT display_name, seen_at FROM user_kit_aliased_activity_user WHERE username = ?',
            ['carol'],
        ));
        $this->assertSame([['username' => 'dave', 'display_name' => 'Dave']], $connection->fetchAllAssociative('SELECT username, display_name FROM user_kit_tracked_user'));

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $this->assertSame(['displayName'], array_keys($unitOfWork->getEntityChangeSet($user)));
        $this->assertCount(1, $unitOfWork->getScheduledEntityInsertions());
    }

    public function testAUserNotPersistedYetIsNotWritten(): void
    {
        $user = new TrackedUser('frank', 'Frank');
        $this->entityManager->persist($user);

        $this->subscriber($user)->onKernelRequest($this->event());

        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM user_kit_tracked_user'));
        $this->assertNotNull($user->getLastActivityAt());
    }

    /**
     * FrankenPHP worker mode with the kernel kept alive (FRANKENPHP_RESET_KERNEL=false, no services resetter): the same
     * subscriber serves 1,200 requests of two alternating users. Memory stays flat, each row carries its own user's last
     * request time, and nothing else is ever written.
     */
    public function testAKeptAliveKernelServesManyRequestsOfTwoUsersWithFlatMemoryAndNoLeak(): void
    {
        $alice = new TrackedUser('alice', 'Alice');
        $bob   = new TrackedUser('bob', 'Bob');
        $this->entityManager->persist($alice);
        $this->entityManager->persist($bob);
        $this->entityManager->flush();
        $alice->displayName = 'Alice (pending)';

        $tokenStorage = new TokenStorage();
        $clock        = new MockClock(new DateTimeImmutable('2026-11-10 08:00:00'));
        $subscriber   = new LastActivitySubscriber(
            ProfileRegistryFactory::single(TrackedUser::class),
            $this->entityManager,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
            $clock,
        );

        $baseline = 0;
        for ($i = 0; $i < 1200; ++$i) {
            if ($i === 200) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
            $clock->sleep(1);
            $tokenStorage->setToken(new UsernamePasswordToken($i % 2 === 0 ? $alice : $bob, 'main', ['ROLE_USER']));
            $subscriber->onKernelRequest($this->event());
            $tokenStorage->setToken(null);
        }
        gc_collect_cycles();
        $this->assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory must stay flat across requests');

        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT username, display_name, lastActivityAt AS at FROM user_kit_tracked_user ORDER BY username');
        $this->assertSame([
            ['username' => 'alice', 'display_name' => 'Alice', 'at' => '2026-11-10 08:19:59'],
            ['username' => 'bob', 'display_name' => 'Bob', 'at' => '2026-11-10 08:20:00'],
        ], $rows);
        $this->assertSame(2, $this->entityManager->getUnitOfWork()->size());
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $this->assertSame(['displayName'], array_keys($unitOfWork->getEntityChangeSet($alice)));
        $this->assertSame([], $unitOfWork->getEntityChangeSet($bob));
    }

    /**
     * @param class-string $userClass
     */
    private function subscriber(TrackedUser|AliasedActivityUser $user, string $userClass = TrackedUser::class): LastActivitySubscriber
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        return new LastActivitySubscriber(
            ProfileRegistryFactory::single($userClass),
            $this->entityManager,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
            new MockClock(new DateTimeImmutable('2026-10-28 10:00:00')),
        );
    }

    private function event(): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
    }
}
