<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImages;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;
use Nowo\UserKitBundle\OwnerImage\PersonalData\OwnerImageEraser;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\OwnerImage\Storage\OwnerImageStorageInterface;
use Nowo\UserKitBundle\OwnerImage\Storage\StorageKit\OwnerImageFileContext;
use Nowo\UserKitBundle\OwnerImage\Twig\OwnerImageExtension;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * 1.18 owner images: the corners the kernel tests do not reach (the pointer table collation: OwnerCollationListenerTest), a store
 * without a mapping manager, a host access answering a scope StorageKit refuses, an erasure of a user without a subject
 * id, the Twig helpers on malformed ids and sizes.
 */
final class OwnerImageEdgesTest extends TestCase
{
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

    /**
     * 1.19.0: a transient failure of the pointer write removes the new file and answers 503, not a `storage` refusal.
     */
    private function images(OwnerImagePointerStoreInterface $pointers, OwnerImageStorageInterface $storage, ?ManagerRegistry $doctrine = null, ?string $manager = null): OwnerImages
    {
        $access = $this->createStub(OwnerImageAccessInterface::class);
        $access->method('writableTarget')->willReturn(new OwnerImageTarget());

        return new OwnerImages(
            new OwnerImageKinds([new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER)]),
            $access,
            $pointers,
            $storage,
            new OwnerImageSubjects(),
            null,
            new NullLogger(),
            $doctrine,
            $manager,
        );
    }

    public function testATransientPointerFailureIsRethrown(): void
    {
        $pointers = $this->createMock(OwnerImagePointerStoreInterface::class);
        $pointers->method('find')->willReturn([]);
        $pointers->method('set')->willThrowException(new UserKitStorageFailure(1));
        $storage = $this->createMock(OwnerImageStorageInterface::class);
        $storage->method('store')->willReturn('new-file');
        $storage->expects(self::once())->method('delete')->with(self::anything(), 'alice', 'new-file');
        $access = $this->createStub(OwnerImageAccessInterface::class);
        $access->method('writableTarget')->willReturn(new OwnerImageTarget());
        $images = new OwnerImages(
            new OwnerImageKinds([new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER)]),
            $access,
            $pointers,
            $storage,
            new OwnerImageSubjects(),
        );

        $this->expectException(UserKitStorageFailure::class);
        $images->upload(new stdClass(), 'avatar', 'alice', 'bytes', 'a.png');
    }

    public function testAReferenceFailureAfterThePointerKeepsTheNewFile(): void
    {
        $pointers = $this->createMock(OwnerImagePointerStoreInterface::class);
        $pointers->method('find')->willReturn([]);
        $storage = $this->createMock(OwnerImageStorageInterface::class);
        $storage->method('store')->willReturn('new-file');
        $storage->method('attach')->willThrowException(new RuntimeException('reference'));
        $storage->expects(self::never())->method('delete');

        $this->expectExceptionMessage('reference');
        $this->images($pointers, $storage)->upload(new stdClass(), 'avatar', 'alice', 'bytes', 'a.png');
    }

    /**
     * 1.19.0 (audit QM-D-L1): inside a transaction of the pointer's connection and without the commit queue the
     * replaced picture is expired (no bucket call), never deleted before the commit.
     */
    public function testInsideATransactionWithoutTheCommitQueueThePreviousPictureIsExpired(): void
    {
        $pointers = $this->createMock(OwnerImagePointerStoreInterface::class);
        $pointers->method('find')->willReturn(['alice' => 'old-file']);
        $storage = $this->createMock(OwnerImageStorageInterface::class);
        $storage->method('store')->willReturn('new-file');
        $storage->expects(self::once())->method('delete')->with(self::anything(), 'alice', 'old-file', true);
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($manager);

        $this->images($pointers, $storage, $doctrine, 'default')->upload(new stdClass(), 'avatar', 'alice', 'bytes', 'a.png');
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
}
