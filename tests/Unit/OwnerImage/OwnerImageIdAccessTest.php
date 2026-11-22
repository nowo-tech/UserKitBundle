<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Nowo\UserKitBundle\OwnerImage\Access\DefaultOwnerImageAccess;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessChecker;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessInterface;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageIdAccessInterface;
use Nowo\UserKitBundle\OwnerImage\Event\OwnerImageIdAccessCheckEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;
use Nowo\UserKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * 1.23.0 (DDD-host checklist B8): owner-image access by subject id. Ids are matched byte for byte, refusals look alike,
 * listeners narrow but never widen, and an access service without the id twin refuses everything.
 */
final class OwnerImageIdAccessTest extends TestCase
{
    public function testTheDefaultAccessByIdFollowsTheKindSettings(): void
    {
        $checker = $this->checker();

        self::assertTrue($checker->canSee(null, 'logo', 'acme'), 'public: anonymous too.');
        self::assertFalse($checker->canSee(null, 'avatar', 'alice'), 'authenticated: not anonymous.');
        self::assertTrue($checker->canSee('bob', 'avatar', 'alice'));
        self::assertFalse($checker->canSee('bob', 'private', 'alice'), 'host: the default access says no.');
        self::assertSame(['alice', 'bob'], $checker->visibleOwners('carol', 'avatar', ['alice', 'bob', 'alice', '../x', 'a b']), 'Deduplicated, malformed ids left out, order kept.');
        self::assertSame([], $checker->visibleOwners('carol', 'avatar', ['../x']));

        self::assertTrue($checker->canChange('alice', 'avatar', 'alice'), 'Own avatar.');
        self::assertFalse($checker->canChange('alice', 'avatar', 'Alice'), 'Byte for byte.');
        self::assertFalse($checker->canChange('alice', 'logo', 'alice'), 'Not a user kind.');
    }

    public function testMalformedIdsAndUnknownKindsAreRefusals(): void
    {
        $checker = $this->checker();

        self::assertFalse($checker->canSee('bob', 'AVATAR', 'alice'), 'Kinds are exact.');
        self::assertFalse($checker->canSee('bob', 'unknown', 'alice'));
        self::assertFalse($checker->canSee('bob ', 'avatar', 'alice'), 'A padded viewer id is no viewer.');
        self::assertFalse($checker->canSee('bob', 'avatar', 'alice '), 'A padded owner id is no owner.');
        self::assertFalse($checker->canChange('alice ', 'avatar', 'alice'));
        self::assertFalse($checker->canChange('alice', 'avatar', "alice\u{200B}"));
        self::assertFalse($checker->canChange('alice', 'nope', 'alice'));
    }

    public function testListenersNarrowButNeverWiden(): void
    {
        $dispatcher = new EventDispatcher();
        $seen       = [];
        $dispatcher->addListener(OwnerImageIdAccessCheckEvent::class, static function (OwnerImageIdAccessCheckEvent $event) use (&$seen): void {
            $seen[] = [$event->userId, $event->kind, $event->action, $event->granted()];
            $event->deny('bob');
            if ($event->action === OwnerImageIdAccessCheckEvent::ACTION_CHANGE) {
                $event->denyAll();
            }
        });
        $dispatcher->addListener(OwnerImageIdAccessCheckEvent::class, static function (OwnerImageIdAccessCheckEvent $event): void {
            self::assertFalse($event->isGranted('bob'));
        });
        $checker = $this->checker(dispatcher: $dispatcher);

        self::assertSame(['alice'], $checker->visibleOwners('carol', 'avatar', ['alice', 'bob']));
        self::assertFalse($checker->canChange('alice', 'avatar', 'alice'), 'denyAll');
        self::assertFalse($checker->canSee('carol', 'private', 'alice'), 'No event for a refusal.');
        self::assertSame([
            ['carol', 'avatar', 'view', ['alice', 'bob']],
            ['alice', 'avatar', 'change', ['alice']],
        ], $seen);
    }

    public function testAListenerCannotAddAnOwner(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OwnerImageIdAccessCheckEvent::class, static function (OwnerImageIdAccessCheckEvent $event): void {
            $event->deny('nobody');
        });
        self::assertSame(['alice'], $this->checker(dispatcher: $dispatcher)->visibleOwners('carol', 'avatar', ['alice']));
    }

    public function testFailuresAndAnAccessWithoutTheIdTwinAreRefusals(): void
    {
        $logger     = new RecordingLogger();
        $objectOnly = new class implements OwnerImageAccessInterface {
            public function writableTarget(object $actor, OwnerImageKind $kind, string $subjectId): OwnerImageTarget
            {
                return new OwnerImageTarget(null, $subjectId);
            }

            public function readable(?object $viewer, OwnerImageKind $kind, array $subjectIds): array
            {
                return $subjectIds;
            }
        };
        $checker = $this->checker($objectOnly, logger: $logger);
        self::assertFalse($checker->canSee('bob', 'logo', 'acme'));
        self::assertFalse($checker->canChange('bob', 'avatar', 'bob'));
        self::assertStringContainsString('does not implement OwnerImageIdAccessInterface', $logger->messages[0] ?? '');

        $failing = new class implements OwnerImageAccessInterface, OwnerImageIdAccessInterface {
            public function writableTarget(object $actor, OwnerImageKind $kind, string $subjectId): ?OwnerImageTarget
            {
                return null;
            }

            public function readable(?object $viewer, OwnerImageKind $kind, array $subjectIds): array
            {
                return [];
            }

            public function writableTargetById(string $actorId, OwnerImageKind $kind, string $subjectId): ?OwnerImageTarget
            {
                throw new RuntimeException('directory down');
            }

            public function readableById(?string $viewerId, OwnerImageKind $kind, array $subjectIds): array
            {
                return ['intruder', ...$subjectIds];
            }
        };
        $checker = $this->checker($failing, logger: $logger);
        self::assertFalse($checker->canChange('bob', 'avatar', 'bob'), 'A failing access service is a refusal.');
        self::assertSame(['acme'], $checker->visibleOwners('bob', 'logo', ['acme']), 'Only ids that were asked are answered.');
        self::assertFalse($this->checker($failing)->canChange('bob', 'avatar', 'bob'), 'No logger needed.');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OwnerImageIdAccessCheckEvent::class, static function (): never {
            throw new RuntimeException('listener down');
        });
        self::assertSame([], $this->checker(dispatcher: $dispatcher, logger: $logger)->visibleOwners('bob', 'logo', ['acme']), 'A failing listener is a refusal.');
        self::assertFalse($this->checker(dispatcher: $dispatcher)->canChange('alice', 'avatar', 'alice'));
        self::assertSame([], $this->checker(dispatcher: $dispatcher)->visibleOwners('bob', 'logo', ['acme']));
    }

    public function testTheDefaultAccessIdTwin(): void
    {
        $access = new DefaultOwnerImageAccess(new OwnerImageSubjects());
        $avatar = new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER);
        self::assertEquals(new OwnerImageTarget(null, 'alice'), $access->writableTargetById('alice', $avatar, 'alice'));
        self::assertNull($access->writableTargetById('alice', $avatar, 'bob'));
        self::assertSame(['a'], $access->readableById(null, new OwnerImageKind('logo', read: OwnerImageKind::READ_PUBLIC), ['a']));
        self::assertSame([], $access->readableById(null, $avatar, ['a']));
        self::assertSame(['a'], $access->readableById('bob', $avatar, ['a']));
        self::assertSame([], $access->readableById('bob', new OwnerImageKind('private', read: OwnerImageKind::READ_HOST), ['a']));
    }

    private function checker(?OwnerImageAccessInterface $access = null, ?EventDispatcher $dispatcher = null, ?RecordingLogger $logger = null): OwnerImageAccessChecker
    {
        $kinds = new OwnerImageKinds([
            new OwnerImageKind('avatar', OwnerImageKind::SUBJECT_USER),
            new OwnerImageKind('logo', read: OwnerImageKind::READ_PUBLIC),
            new OwnerImageKind('private', read: OwnerImageKind::READ_HOST),
        ]);

        return new OwnerImageAccessChecker($kinds, $access ?? new DefaultOwnerImageAccess(new OwnerImageSubjects()), $dispatcher, $logger);
    }
}
