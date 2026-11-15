<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Nowo\UserKitBundle\OwnerImage\Access\DefaultOwnerImageAccess;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjectInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\Twig\OwnerImageExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Regression tests of audit QJ (formerly its PoC `tests/AuditQj/QjPocTest.php`): QJ-M1, two user classes with the same
 * id must not share one image subject; and the Twig helpers escape every attribute.
 */
final class OwnerImageUserClassTest extends TestCase
{
    public function testTwoUserClassesWithTheSameIdDoNotShareASubject(): void
    {
        $subjects = new OwnerImageSubjects(null, InMemoryUser::class);
        $customer = new InMemoryUser('42', null);
        $admin    = new OwnerImageAdminUser('42');
        self::assertSame('42', $subjects->idOf($customer));
        self::assertNull($subjects->idOf($admin), 'not the configured user class');

        $access = new DefaultOwnerImageAccess($subjects);
        $avatar = new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER);
        // Not vulnerable any more: the admin cannot change the customer's avatar (subject "42"), and has none of its own.
        self::assertNull($access->writableTarget($admin, $avatar, '42'));
        self::assertNotNull($access->writableTarget($customer, $avatar, '42'));

        // Without a configured class nobody gets the bare identifier.
        $none = new OwnerImageSubjects();
        self::assertNull($none->idOf($customer));
        self::assertNull($none->idOf($admin));
        self::assertNull((new DefaultOwnerImageAccess($none))->writableTarget($customer, $avatar, '42'));
    }

    public function testSeveralUserClassesUseTheirOwnSubjectIds(): void
    {
        $subjects = new OwnerImageSubjects(null, InMemoryUser::class);
        $admin    = new class implements UserInterface, OwnerImageSubjectInterface {
            public function getRoles(): array
            {
                return ['ROLE_ADMIN'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return '42';
            }

            public function getOwnerImageSubjectId(): string
            {
                return 'admin:42';
            }

            public function getOwnerImageInitials(): string
            {
                return 'AD';
            }
        };
        self::assertSame('admin:42', $subjects->idOf($admin));
        self::assertSame('42', $subjects->idOf(new InMemoryUser('42', null)));
    }

    public function testTwigEscapesEveryAttribute(): void
    {
        $images = $this->createMock(OwnerImagesInterface::class);
        $images->method('fileIds')->willReturn(['s1' => 'f"><script>']);
        $images->method('fileUrl')->willReturn('/files/x"><script>alert(1)</script>');
        $twig = new OwnerImageExtension($images, new OwnerImageSubjects());

        $html = $twig->image('avatar', 's1', 'md', '"><img src=x onerror=alert(1)>', '?', '" onmouseover="alert(1)');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('" onmouseover', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<b>', $twig->image('avatar', 'none', 'md', '', '<b>X</b>'));
    }
}
