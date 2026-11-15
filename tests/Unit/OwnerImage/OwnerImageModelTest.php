<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\UserKitBundle\OwnerImage\Access\DefaultOwnerImageAccess;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImageRefused;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjectInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function json_encode;
use function str_repeat;

/**
 * 1.18 owner images: kinds, subject ids, subjects, default access, refusals and the pointer row.
 */
final class OwnerImageModelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function badKinds(): iterable
    {
        yield 'empty name' => ['', 'user', 'public', 'circle'];
        yield 'upper case' => ['Avatar', 'user', 'public', 'circle'];
        yield 'too long' => ['a_very_long_kind_name', 'user', 'public', 'circle'];
        yield 'subject' => ['avatar', 'org', 'public', 'circle'];
        yield 'read' => ['avatar', 'user', 'everyone', 'circle'];
        yield 'shape' => ['avatar', 'user', 'public', 'hexagon'];
    }

    #[DataProvider('badKinds')]
    public function testAMalformedKindIsRefused(string $name, string $subject, string $read, string $shape): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OwnerImageKind($name, $subject, $read, $shape);
    }

    public function testKinds(): void
    {
        $avatar = new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER, OwnerImageKind::READ_PUBLIC, OwnerImageKind::SHAPE_CIRCLE);
        $logo   = new OwnerImageKind('org_logo');
        self::assertTrue($avatar->isUserKind());
        self::assertFalse($logo->isUserKind());
        self::assertSame([OwnerImageKind::SUBJECT_OTHER, OwnerImageKind::READ_AUTHENTICATED, OwnerImageKind::SHAPE_SQUARE], [$logo->subject, $logo->read, $logo->shape]);

        $kinds = new OwnerImageKinds([$avatar, $logo]);
        self::assertSame([$avatar, $logo], $kinds->all());
        self::assertSame([$avatar], $kinds->userKinds());
        self::assertSame($logo, $kinds->get('org_logo'));
        self::assertNull($kinds->get('nope'));
    }

    public function testSubjectIds(): void
    {
        foreach (['alice', '42', '0190d6f4-7b1a-7cc1-9b3e-4f2b8f3c2a10', 'a.b:c-d_e', str_repeat('a', 64)] as $id) {
            self::assertTrue(OwnerImageKinds::isSubjectId($id), $id);
        }
        foreach (['', ' alice', 'alice ', "alice\n", 'a/b', '../a', 'á', str_repeat('a', 65), 42, null] as $id) {
            self::assertFalse(OwnerImageKinds::isSubjectId($id), (string) json_encode($id));
        }
    }

    public function testSubjectsAndInitials(): void
    {
        $subjects = new OwnerImageSubjects(null, InMemoryUser::class);
        $own      = new class implements OwnerImageSubjectInterface {
            public function getOwnerImageSubjectId(): string
            {
                return 'org-7';
            }

            public function getOwnerImageInitials(): string
            {
                return 'AC';
            }
        };
        self::assertSame('org-7', $subjects->idOf($own));
        self::assertSame('AC', $subjects->initialsOf($own));
        self::assertSame('alice', $subjects->idOf(new InMemoryUser('alice', null)));
        self::assertSame('É', $subjects->initialsOf(new InMemoryUser('élodie', null)));
        self::assertNull($subjects->idOf(new InMemoryUser('alice smith', null)), 'not a usable subject id');
        self::assertNull($subjects->idOf(new stdClass()));
        self::assertSame('?', $subjects->initialsOf(new stdClass()));
        self::assertSame('?', $subjects->initialsOf(null));
    }

    public function testTheDefaultAccess(): void
    {
        $access = new DefaultOwnerImageAccess(new OwnerImageSubjects(null, InMemoryUser::class));
        $alice  = new InMemoryUser('alice', null);
        $avatar = new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER, OwnerImageKind::READ_AUTHENTICATED);
        $target = $access->writableTarget($alice, $avatar, 'alice');
        self::assertInstanceOf(OwnerImageTarget::class, $target);
        self::assertSame([null, 'alice'], [$target->scope, $target->owner]);
        self::assertNull($access->writableTarget($alice, $avatar, 'bob'));
        self::assertNull($access->writableTarget($alice, $avatar, 'Alice'));
        self::assertNull($access->writableTarget($alice, new OwnerImageKind('org_logo'), 'alice'));

        self::assertSame(['a', 'b'], $access->readable($alice, $avatar, ['a', 'b']));
        self::assertSame([], $access->readable(null, $avatar, ['a', 'b']));
        self::assertSame(['a'], $access->readable(null, new OwnerImageKind('pub', read: OwnerImageKind::READ_PUBLIC), ['a']));
        self::assertSame([], $access->readable($alice, new OwnerImageKind('own', read: OwnerImageKind::READ_HOST), ['a']));
    }

    public function testRefusalsAndThePointerRow(): void
    {
        $previous = new RuntimeException('bucket');
        $refused  = new OwnerImageRefused(OwnerImageRefused::STORAGE, $previous);
        self::assertSame([OwnerImageRefused::STORAGE, OwnerImageRefused::STORAGE, $previous], [$refused->reason, $refused->getMessage(), $refused->getPrevious()]);

        $at  = new DateTimeImmutable('2026-11-15 10:00:00');
        $row = new OwnerImagePointer('avatar', 'alice', 'file-1', $at);
        self::assertSame([null, 'avatar', 'alice', 'file-1', $at], [$row->getId(), $row->getKind(), $row->getSubjectId(), $row->getFileId(), $row->getUpdatedAt()]);
    }
}
