<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessInterface;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImages;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;
use Nowo\UserKitBundle\OwnerImage\PersonalData\OwnerImageEraser;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\OwnerImage\Pointer\PointerCollationListener;
use Nowo\UserKitBundle\OwnerImage\Storage\OwnerImageStorageInterface;
use Nowo\UserKitBundle\OwnerImage\Storage\StorageKit\OwnerImageFileContext;
use Nowo\UserKitBundle\OwnerImage\Twig\OwnerImageExtension;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * 1.18 owner images: the corners the kernel tests do not reach — the pointer table collation per platform, a store
 * without a mapping manager, a host access answering a scope StorageKit refuses, an erasure of a user without a subject
 * id, the Twig helpers on malformed ids and sizes.
 */
final class OwnerImageEdgesTest extends TestCase
{
    public function testThePointerColumnsAreCollatedOnMysqlOnly(): void
    {
        foreach ([[new MySQL80Platform(), 'utf8mb4_0900_bin', null], [new MySQL80Platform(), 'utf8mb4_0900_bin', 'images'], [new PostgreSQLPlatform(), null, null]] as [$platform, $collation, $manager]) {
            $table = $this->table();
            $args  = new GenerateSchemaTableEventArgs(new ClassMetadata(OwnerImagePointer::class), new Schema(), $table);
            (new PointerCollationListener($this->registry($platform, $manager), $manager))->postGenerateSchemaTable($args);
            foreach (PointerCollationListener::COLUMNS as $column) {
                $result = $args->getClassTable()->getColumn($column);
                self::assertSame($collation, $result->hasPlatformOption('collation') ? $result->getPlatformOption('collation') : null);
            }
            self::assertFalse($args->getClassTable()->getColumn('file_id')->hasPlatformOption('collation'));
        }

        $table = $this->table();
        $args  = new GenerateSchemaTableEventArgs(new ClassMetadata(stdClass::class), new Schema(), $table);
        (new PointerCollationListener($this->registry(new MySQL80Platform(), null)))->postGenerateSchemaTable($args);
        self::assertSame($table, $args->getClassTable());
    }

    public function testAStoreWithoutAMappingManagerSaysSo(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Doctrine entity manager maps');
        (new DbalOwnerImagePointerStore($registry))->find('avatar', ['alice']);
    }

    public function testAHostTargetStorageKitRefusesFailsClosed(): void
    {
        $access = $this->createStub(OwnerImageAccessInterface::class);
        $access->method('writableTarget')->willReturn(new OwnerImageTarget('Not A Scope!', 'alice'));
        $access->method('readable')->willReturn(['a', 'zzz']);
        $context = new OwnerImageFileContext(new OwnerImageKind('avatar'), $access, $this->createStub(OwnerImagePointerStoreInterface::class));

        self::assertSame('avatar', $context->type());
        self::assertNull($context->writableTarget(new InMemoryUser('alice', null), 'alice'));
        self::assertNull($context->writableTarget(new InMemoryUser('alice', null), '../alice'));
        self::assertSame(['a'], $context->readable(null, ['a', 'b']));
    }

    public function testAnErasureOfAUserWithoutASubjectIdDoesNothing(): void
    {
        $pointers = $this->createMock(OwnerImagePointerStoreInterface::class);
        $pointers->expects(self::never())->method('find');
        $images = new OwnerImages(
            new OwnerImageKinds([new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER)]),
            $this->createStub(OwnerImageAccessInterface::class),
            $pointers,
            $this->createStub(OwnerImageStorageInterface::class),
            new OwnerImageSubjects(),
        );
        $eraser = new OwnerImageEraser($images, new OwnerImageSubjects());
        self::assertFalse($eraser->supports(new stdClass()));
        $eraser->erase(new ErasureRequest(new stdClass()));
    }

    public function testTheTwigHelpersOnMalformedIdsAndSizes(): void
    {
        $pointers = $this->createStub(OwnerImagePointerStoreInterface::class);
        $pointers->method('find')->willReturnCallback(static fn (string $kind, array $ids): array => $ids === ['alice'] ? ['alice' => 'f1'] : []);
        $storage = $this->createStub(OwnerImageStorageInterface::class);
        $storage->method('url')->willReturnCallback(static fn (string $file, int $pixels): string => '/f/' . $file . '/' . $pixels);
        $images = new OwnerImages(
            new OwnerImageKinds([new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER)]),
            $this->createStub(OwnerImageAccessInterface::class),
            $pointers,
            $storage,
            new OwnerImageSubjects(),
        );
        $twig = new OwnerImageExtension($images, new OwnerImageSubjects(), null, ['sm' => 20], 'avatar');

        self::assertCount(5, $twig->getFunctions());
        self::assertNull($twig->imageUrl('avatar', '../alice'));
        self::assertNull($twig->imageUrl('avatar', null));
        self::assertFalse($twig->ready('avatar', 'alice'), 'no token storage');
        self::assertStringContainsString('src="/f/f1/72"', $twig->image('avatar', 'alice', 'huge'), 'unknown size: md (36 px), twice');
        self::assertSame('/f/f1/112', $twig->imageUrl('avatar', 'alice', 'nope'));
        self::assertStringContainsString('>?</span>', $twig->image('avatar', 'bob', initials: ''));
        self::assertStringContainsString('>?</span>', $twig->userAvatar(null));
        $twig->reset();
    }

    private function table(): Table
    {
        $table = new Table(DbalOwnerImagePointerStore::TABLE);
        foreach (['kind', 'subject_id', 'file_id'] as $column) {
            $table->addColumn($column, Types::STRING, ['length' => 64]);
        }

        return $table;
    }

    private function registry(AbstractPlatform $platform, ?string $managerName): ManagerRegistry
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($managerName === null ? $manager : null);
        $registry->method('getManager')->willReturn($manager);

        return $registry;
    }
}
