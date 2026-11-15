<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Nowo\UserKitBundle\OwnerImage\OwnerImageRefused;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\TrackedUser;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\User;
use Twig\Environment;

/**
 * Audit QJ-M1: two Doctrine user classes whose rows share an id (`User#alice` and `TrackedUser#alice`) never share an
 * owner image. Only the configured user class (`owner_images.user_class`, default: the class of the only profile) gets
 * its identifier as subject id; the other one gets none, so it can neither change, export nor erase that image, and
 * `user_avatar()` shows its initials.
 */
final class UserClassOwnerImagesTest extends OwnerImagesKernelTestCase
{
    private function lookalike(): TrackedUser
    {
        $em = $this->em();
        $em->persist(new TrackedUser('alice', 'Other Alice'));
        $em->flush();
        $em->clear();

        return $em->find(TrackedUser::class, 'alice') ?? self::fail('no tracked user');
    }

    private function images(): OwnerImagesInterface
    {
        return $this->service(OwnerImagesInterface::class);
    }

    /**
     * @param callable(): mixed $call
     */
    private function refused(callable $call, string $reason): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (OwnerImageRefused $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testAnotherUserClassWithTheSameIdGetsNoSubject(): void
    {
        $this->boot();
        $alice = $this->user('alice');
        $other = $this->lookalike();
        $view  = $this->images()->upload($alice, 'avatar', 'alice', self::png(), 'me.png');

        $subjects = $this->service(OwnerImageSubjects::class);
        self::assertSame('alice', $subjects->idOf($alice));
        self::assertNull($subjects->idOf($other));

        // Write: the look-alike may neither replace nor remove alice's picture.
        $this->refused(fn (): mixed => $this->images()->upload($other, 'avatar', 'alice', self::png(), 'x.png'), OwnerImageRefused::FORBIDDEN);
        $this->refused(fn (): mixed => $this->images()->remove($other, 'avatar', 'alice'), OwnerImageRefused::FORBIDDEN);
        self::assertFalse($this->images()->ready($other, 'avatar', 'alice'));
        self::assertSame(['alice' => $view->fileId], $this->images()->fileIds('avatar', ['alice']));

        // Twig: the look-alike shows its initials, not alice's picture.
        $html = $this->service(Environment::class)->createTemplate("{{ user_avatar(other, 'sm') }}")->render(['other' => $other]);
        self::assertStringNotContainsString($view->fileId, $html);

        // GDPR: the look-alike's export lists nothing of alice's, its erasure leaves alice's picture alone.
        $exporter = $this->container()->get('test.personal_data.exporter');
        self::assertInstanceOf(PersonalDataExporter::class, $exporter);
        self::assertArrayNotHasKey('owner_images', $exporter->export($other)['sections']);
        $eraser = $this->container()->get('test.personal_data.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);
        self::assertNotContains('owner_images', $eraser->erase($other));
        self::assertSame(['alice' => $view->fileId], $this->images()->fileIds('avatar', ['alice']));
        self::assertCount(3, $this->buckets()->keys());
    }

    public function testTheConfiguredUserClassWins(): void
    {
        $this->boot(['owner_images' => ['user_class' => '\\' . TrackedUser::class]]);
        $other = $this->lookalike();

        self::assertNull($this->service(OwnerImageSubjects::class)->idOf($this->user('alice')));
        $view = $this->images()->upload($other, 'avatar', 'alice', self::png(), 'me.png');
        self::assertSame('alice', $view->subjectId);
        $this->refused(fn (): mixed => $this->images()->remove($this->user('alice'), 'avatar', 'alice'), OwnerImageRefused::FORBIDDEN);
    }

    public function testSeveralProfilesNeedTheUserClass(): void
    {
        $profiles = ['profiles' => ['default' => ['user_class' => User::class], 'tracked' => ['user_class' => TrackedUser::class]]];
        $this->boot($profiles, variant: 'none');
        $other = $this->lookalike();
        self::assertNull($this->service(OwnerImageSubjects::class)->idOf($this->user('alice')));
        self::assertNull($this->service(OwnerImageSubjects::class)->idOf($other));
        $this->kernel?->shutdown();

        $this->boot($profiles + ['owner_images' => ['user_class' => User::class]], variant: 'user');
        self::assertSame('alice', $this->service(OwnerImageSubjects::class)->idOf($this->user('alice')));
        self::assertNull($this->service(OwnerImageSubjects::class)->idOf($this->lookalike()));
    }
}
