<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions\Access;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;
use Nowo\UserKitBundle\PersonalData\Event\AccountErasedEvent;
use Nowo\UserKitBundle\Sessions\Access\SessionActor;
use Nowo\UserKitBundle\Sessions\Event\SessionIdAccessCheckEvent;
use Nowo\UserKitBundle\Sessions\Event\SessionLimitReachedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsDeletedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsPurgedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionStartedEvent;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionLimit;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Security\AllowAllSessionsAccessChecker;
use Nowo\UserKitBundle\Sessions\Security\RoleSessionsAccessChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

use function str_repeat;

/**
 * 1.23.0 (DDD-host checklist B4 / B8): the id-only models, the id twins of the built-in checkers and the event ids.
 */
final class IdAccessModelTest extends TestCase
{
    public function testAnActorCarriesItsReferenceAndRoles(): void
    {
        $alice = new SessionUser('App\\User', 'alice');
        $actor = SessionActor::of($alice, ['ROLE_USER', 'ROLE_X']);

        self::assertSame($alice, $actor->user);
        self::assertSame(['ROLE_USER', 'ROLE_X'], $actor->roles);
        self::assertTrue($actor->is(new SessionUser('App\\User', 'alice')));
        self::assertFalse($actor->is(new SessionUser('App\\User', 'Alice')), 'Exact id.');
        self::assertFalse($actor->is(new SessionUser('App\\Admin', 'alice')), 'Exact type.');
        self::assertSame([], (new SessionActor($alice))->roles);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableRoles(): iterable
    {
        yield 'empty' => [''];
        yield 'padded' => ['ROLE_ADMIN '];
        yield 'inner space' => ['ROLE ADMIN'];
        yield 'zero width' => ["ROLE_\u{200B}ADMIN"];
        yield 'newline' => ["ROLE_ADMIN\n"];
        yield 'too long' => [str_repeat('R', 256)];
    }

    #[DataProvider('unusableRoles')]
    public function testUnusableRolesAreRefused(string $role): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionActor(new SessionUser('App\\User', 'alice'), [$role]);
    }

    public function testTheRoleCheckerMatchesTheActorsRolesNotTheToken(): void
    {
        $token = $this->createMock(AuthorizationCheckerInterface::class);
        $token->expects(self::never())->method('isGranted');
        $alice = new SessionUser('App\\User', 'alice');
        $bob   = new SessionUser('App\\User', 'bob');

        $plain = new RoleSessionsAccessChecker($token, ['ROLE_USER'], ['ROLE_ADMIN']);
        self::assertTrue($plain->canAccessOwnById(SessionActor::of($alice, ['ROLE_USER'])));
        self::assertFalse($plain->canAccessOwnById(SessionActor::of($alice, ['ROLE_ADMIN'])), 'Without the hierarchy ROLE_ADMIN is only itself.');
        self::assertFalse($plain->canAccessOwnById(SessionActor::of($alice)));
        self::assertTrue($plain->canManageById(SessionActor::of($bob, ['ROLE_ADMIN']), $alice));
        self::assertFalse($plain->canManageById(SessionActor::of($bob, ['role_admin', 'ROLE_ADMINS']), $alice), 'Exact role names.');

        $hierarchy = new RoleSessionsAccessChecker($token, ['ROLE_USER'], ['ROLE_ADMIN'], new RoleHierarchy(['ROLE_SUPER' => ['ROLE_ADMIN'], 'ROLE_ADMIN' => ['ROLE_USER']]));
        self::assertTrue($hierarchy->canAccessOwnById(SessionActor::of($alice, ['ROLE_ADMIN'])));
        self::assertTrue($hierarchy->canManageById(SessionActor::of($bob, ['ROLE_SUPER']), $alice));
        self::assertFalse($hierarchy->canManageById(SessionActor::of($bob, ['ROLE_USER']), $alice));
    }

    public function testTheDemoCheckerGrantsEveryActor(): void
    {
        $checker = new AllowAllSessionsAccessChecker();
        $alice   = new SessionUser('App\\User', 'alice');
        self::assertTrue($checker->canAccessOwnById(SessionActor::of($alice)));
        self::assertTrue($checker->canManageById(SessionActor::of($alice), new SessionUser('App\\User', 'bob')));
    }

    public function testTheIdAccessEventOnlyNarrows(): void
    {
        $alice = new SessionUser('App\\User', 'alice');
        $event = new SessionIdAccessCheckEvent(new SessionUser('App\\User', 'bob'), $alice, str_repeat('a', 32), SessionIdAccessCheckEvent::ACTION_REVOKE);
        self::assertTrue($event->isGranted());
        self::assertFalse($event->isOwn());
        $event->deny();
        self::assertFalse($event->isGranted());
        self::assertTrue((new SessionIdAccessCheckEvent($alice, $alice, str_repeat('a', 32), SessionIdAccessCheckEvent::ACTION_VIEW))->isOwn());
        self::assertFalse((new SessionIdAccessCheckEvent(new SessionUser('App\\Admin', 'alice'), $alice, str_repeat('a', 32), 'view'))->isOwn());
    }

    public function testEventsCarryIdsAndTheInstant(): void
    {
        $at    = new DateTimeImmutable('2026-11-22 10:00:00');
        $owner = new SessionUser('App\\User', 'alice');
        $admin = new SessionUser('App\\User', 'admin');

        $revoked = new UserSessionsRevokedEvent($owner, null, null, RevocationReason::Admin, ['x'], actorRef: $admin, occurredAt: $at);
        self::assertSame($admin, $revoked->actorRef);
        self::assertSame($at, $revoked->occurredAt);
        self::assertTrue($revoked->byOther());
        self::assertFalse((new UserSessionsRevokedEvent($owner, null, null, RevocationReason::SignedOut, [], actorRef: new SessionUser('App\\User', 'alice')))->byOther());
        self::assertTrue((new UserSessionsRevokedEvent($owner, null, null, RevocationReason::Admin, [], actorRef: new SessionUser('App\\Admin', 'alice')))->byOther());
        self::assertFalse((new UserSessionsRevokedEvent($owner, null, null, RevocationReason::Evicted, []))->byOther(), 'No actor.');

        self::assertSame($at, (new UserSessionStartedEvent(new stdClass(), $owner, new SessionClient(), true, $at))->occurredAt);
        self::assertSame($at, (new UserSessionsDeletedEvent($owner, null, 2, $at))->occurredAt);
        self::assertSame($at, (new UserSessionsPurgedEvent($at, 1, null, $at))->occurredAt);
        self::assertSame($at, (new SessionLimitReachedEvent(new stdClass(), $owner, SessionLimit::unlimited(), 3, [], $at))->occurredAt);
        self::assertSame($at, (new OwnerImageChangedEvent('avatar', 'alice', 'f1', null, 'alice', $at))->occurredAt);

        $erased = new AccountErasedEvent(new stdClass(), 'App\\User', 'alice', null, [], $at, false, [], 'App\\User', 'admin');
        self::assertSame($at, $erased->occurredAt, 'Defaults to erasedAt.');
        self::assertSame('admin', $erased->actorId);
        self::assertSame('App\\User', $erased->actorType);
        self::assertSame($at, (new AccountErasedEvent(new stdClass(), null, null, null, [], new DateTimeImmutable(), true, occurredAt: $at))->occurredAt);
    }

    public function testEventsWithoutAnInstantTakeTheConstructionTime(): void
    {
        $before = new DateTimeImmutable();
        $owner  = new SessionUser('App\\User', 'alice');
        foreach ([
            (new UserSessionsRevokedEvent($owner, null, null, RevocationReason::Admin, []))->occurredAt,
            (new UserSessionStartedEvent(new stdClass(), $owner, new SessionClient(), true))->occurredAt,
            (new UserSessionsDeletedEvent($owner, null, 0))->occurredAt,
            (new UserSessionsPurgedEvent($before, 0))->occurredAt,
            (new SessionLimitReachedEvent(new stdClass(), $owner, SessionLimit::unlimited(), 1))->occurredAt,
            (new OwnerImageChangedEvent('avatar', 'alice', null, 'f1', null))->occurredAt,
        ] as $occurredAt) {
            self::assertGreaterThanOrEqual($before, $occurredAt);
        }
    }
}
