<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
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

        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(TrackedUser::class)]);
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

    private function subscriber(TrackedUser $user): LastActivitySubscriber
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        return new LastActivitySubscriber(
            ProfileRegistryFactory::single(TrackedUser::class),
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
