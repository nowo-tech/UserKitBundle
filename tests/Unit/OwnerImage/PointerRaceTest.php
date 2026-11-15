<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 1.19.0 (audit QM-M3): the pointer write's lost first-row race. The first read finds no row, the insert loses to a
 * request that committed meanwhile; the real two-session race is `tests/Load/OwnerImageLoadTest`.
 */
final class PointerRaceTest extends TestCase
{
    public function testALostFirstRowRaceWritesOverTheWinner(): void
    {
        $connection = $this->connection([[], [['id' => 7, 'kind' => 'avatar', 'subject_id' => 'alice']]]);
        $connection->expects(self::once())->method('update')->with(DbalOwnerImagePointerStore::TABLE, self::callback(static fn (array $data): bool => $data['file_id'] === 'ours'), ['id' => 7]);

        $this->store($connection)->set('avatar', 'alice', 'ours');
    }

    public function testARaceLostToARowItCannotReadIsAConflict(): void
    {
        $connection = $this->connection([[], []]);
        $connection->expects(self::never())->method('update');

        $this->expectException(UserKitConflict::class);
        $this->store($connection)->set('avatar', 'alice', 'ours');
    }

    /**
     * @param list<list<array<string, mixed>>> $reads the row reads, in order
     *
     * @return Connection&MockObject
     */
    private function connection(array $reads): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(...$reads);
        $connection->method('insert')->willThrowException(new UniqueConstraintViolationException(new class('taken', null, 19) extends AbstractException {
        }, null));

        return $connection;
    }

    private function store(Connection $connection): DbalOwnerImagePointerStore
    {
        $metadata = new ClassMetadata(OwnerImagePointer::class);
        $manager  = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($manager);

        return new DbalOwnerImagePointerStore($doctrine);
    }
}
