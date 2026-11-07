<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\SessionsTestKernel;

/**
 * The registry on another connection than the users: its rows cannot follow the users' transaction, so the whole
 * revocation of a password change waits for that transaction's commit.
 */
final class SessionRegistryOnAnotherConnectionTest extends SessionsKernelTestCase
{
    private Member $alice;

    private function start(): void
    {
        $config       = ['sessions' => ['enabled' => true, 'doctrine' => ['entity_manager' => 'registry']]];
        $this->kernel = new SessionsTestKernel('registry-manager', $config, null, [], false, null, ['registry_manager' => true]);
        $this->kernel->boot();
        $users    = $this->em();
        $registry = $this->container()->get('doctrine.orm.registry_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $registry);
        (new SchemaTool($users))->createSchema([$users->getClassMetadata(Member::class)]);
        (new SchemaTool($registry))->createSchema([$registry->getClassMetadata(UserSession::class)]);
        $this->alice = $this->member('alice@example.com');
        self::assertNotSame($users->getConnection(), $this->store()->connection());
        $this->store()->insert($this->manager()->owner($this->alice) ?? self::fail(), 'a-session', new SessionClient(), $this->manager()->now());
    }

    private function users(): Connection
    {
        return $this->em()->getConnection();
    }

    public function testARolledBackPasswordChangeLeavesTheRegistryAlone(): void
    {
        $this->start();
        $this->users()->beginTransaction();
        $this->alice->setPassword('changed');
        $this->em()->flush();
        $this->users()->rollBack();
        $this->manager()->releaseHeldRevocations();

        self::assertCount(1, $this->manager()->listFor($this->alice));
    }

    public function testACommittedPasswordChangeRevokesAfterTheCommit(): void
    {
        $this->start();
        $this->users()->beginTransaction();
        $this->alice->setPassword('changed');
        $this->em()->flush();
        self::assertCount(1, $this->manager()->listFor($this->alice), 'Still held.');
        $this->users()->commit();
        $this->manager()->releaseHeldRevocations();

        self::assertCount(0, $this->manager()->listFor($this->alice));
    }

    public function testWithoutATransactionItRevokesAtOnce(): void
    {
        $this->start();
        $this->alice->setPassword('changed');
        $this->em()->flush();

        self::assertCount(0, $this->manager()->listFor($this->alice));
    }
}
