<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\UserKitBundle\Sessions\Access\SessionAccess;
use Nowo\UserKitBundle\Sessions\Access\SessionAccessInterface;
use Nowo\UserKitBundle\Sessions\Access\SessionActor;
use Nowo\UserKitBundle\Sessions\Event\SessionIdAccessCheckEvent;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Security\SessionsAccessCheckerInterface;
use Nowo\UserKitBundle\Sessions\Security\SessionsIdAccessCheckerInterface;
use Nowo\UserKitBundle\Sessions\Security\UnavailableSessionsAccessChecker;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function sort;
use function sprintf;
use function str_pad;
use function strtoupper;
use function substr;

/**
 * 1.23.0 (DDD-host checklist B8): session access by ids. The public id is matched exactly (SQL, byte for byte on MySQL /
 * MariaDB, and `===` in PHP); every refusal looks the same; listeners narrow, never widen; nothing is kept between
 * calls. Runs on SQLite, or on `USER_KIT_TEST_DB_URL` (MySQL / PostgreSQL in CI).
 */
final class SessionAccessByIdTest extends TransactionalSessionsTestCase
{
    public function testOwnSessionsNeedAccessRolesAndOthersNeedManageRoles(): void
    {
        [$alice, $bob] = $this->owners();
        $aliceSession  = $this->register($alice, 'h-alice');
        $access        = $this->access();

        self::assertTrue($access->canViewSession(SessionActor::of($alice, ['ROLE_USER']), $aliceSession), 'Own session with ROLE_USER.');
        self::assertTrue($access->canRevokeSession(SessionActor::of($alice, ['ROLE_USER']), $aliceSession));
        self::assertFalse($access->canViewSession($alice, $aliceSession), 'A bare reference carries no role.');
        self::assertFalse($access->canViewSession(SessionActor::of($bob, ['ROLE_USER']), $aliceSession), 'Another user\'s session needs a manage role.');
        self::assertTrue($access->canViewSession(SessionActor::of($bob, ['ROLE_ADMIN']), $aliceSession), 'Manage role.');
        self::assertTrue($access->canRevokeSession(SessionActor::of($bob, ['ROLE_ADMIN']), $aliceSession));
        self::assertTrue($access->canViewSession(SessionActor::of($alice, ['ROLE_ADMIN']), $aliceSession), 'ROLE_ADMIN reaches ROLE_USER through the hierarchy.');
        self::assertFalse($access->canViewSession(SessionActor::of($bob, ['role_admin', 'ROLE_ADMINS']), $aliceSession), 'Roles match exactly.');
        self::assertFalse($access->canViewSession(SessionActor::of(new SessionUser(Member::class, '01'), ['ROLE_USER']), $aliceSession), 'A look-alike actor is another user.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lookalikeIds(): iterable
    {
        yield 'upper case' => ['upper'];
        yield 'trailing space' => ['pad'];
        yield 'leading space' => ['lead'];
        yield 'too short' => ['short'];
        yield 'unknown' => ['unknown'];
        yield 'empty' => ['empty'];
    }

    #[DataProvider('lookalikeIds')]
    public function testOnlyTheExactPublicIdIsFound(string $variant): void
    {
        [$alice] = $this->owners();
        $id      = $this->register($alice, 'h-alice');
        $asked   = match ($variant) {
            'upper'   => strtoupper($id),
            'pad'     => $id . ' ',
            'lead'    => ' ' . $id,
            'short'   => substr($id, 0, 31),
            'unknown' => str_pad('', 32, '0'),
            default   => '',
        };
        $actor = SessionActor::of($alice, ['ROLE_ADMIN']);

        self::assertFalse($this->access()->canViewSession($actor, $asked), sprintf('"%s" is not "%s".', $asked, $id));
        self::assertFalse($this->access()->canRevokeSession($actor, $asked));
        self::assertNull($this->store()->sessionByPublicId($asked));
        self::assertTrue($this->access()->canViewSession($actor, $id), 'The exact id is found.');
    }

    /**
     * A registry column still on a folding host collation (MySQL / MariaDB): the SQL byte comparison and the PHP
     * re-check keep the upper-case look-alike out even where `=` alone would match it.
     */
    public function testAFoldingPublicIdColumnStillMatchesExactly(): void
    {
        $this->setUpDatabase();
        if (!$this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Host collations exist on MySQL / MariaDB only.');
        }
        $this->connection()->executeStatement(sprintf('ALTER TABLE %s MODIFY public_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL', $this->store()->table()));
        $alice = new SessionUser(Member::class, '1');
        $id    = $this->register($alice, 'h-alice');

        self::assertSame(1, (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE public_id = ?', $this->store()->table()), [strtoupper($id)]), 'The column folds.');
        self::assertNull($this->store()->sessionByPublicId(strtoupper($id)));
        self::assertNotNull($this->store()->sessionByPublicId($id));
    }

    public function testRevokedAndExpiredSessions(): void
    {
        [$alice] = $this->owners();
        $revoked = $this->register($alice, 'h-revoked');
        $stale   = $this->register($alice, 'h-stale');
        $this->store()->markRevoked('h-revoked', $alice, RevocationReason::SignedOut->value, $this->clock()->now());
        $this->clock()->modify('+3 days');
        $actor = SessionActor::of($alice, ['ROLE_USER']);

        self::assertFalse($this->access()->canViewSession($actor, $revoked), 'A revoked session is not listed.');
        self::assertFalse($this->access()->canRevokeSession($actor, $revoked), 'Nothing left to revoke.');
        self::assertFalse($this->access()->canViewSession($actor, $stale), 'Idle past the lifetime: not listed.');
        self::assertTrue($this->access()->canRevokeSession($actor, $stale), 'Still revocable, as revoke() would act on it.');
    }

    public function testListenersNarrowButNeverWiden(): void
    {
        [$alice, $bob] = $this->owners();
        $id            = $this->register($alice, 'h-alice');
        $seen          = [];
        $this->dispatcher()->addListener(SessionIdAccessCheckEvent::class, static function (SessionIdAccessCheckEvent $event) use (&$seen): void {
            $seen[] = [$event->actor->id, $event->owner->id, $event->publicId, $event->action, $event->isOwn()];
            if ($event->action === SessionIdAccessCheckEvent::ACTION_REVOKE && !$event->isOwn()) {
                $event->deny();
            }
        });

        self::assertTrue($this->access()->canViewSession(SessionActor::of($bob, ['ROLE_ADMIN']), $id));
        self::assertFalse($this->access()->canRevokeSession(SessionActor::of($bob, ['ROLE_ADMIN']), $id), 'The listener refuses.');
        self::assertTrue($this->access()->canRevokeSession(SessionActor::of($alice, ['ROLE_USER']), $id));
        self::assertFalse($this->access()->canViewSession(SessionActor::of($bob, ['ROLE_USER']), $id), 'Refused by the checker.');
        self::assertSame([
            [$bob->id, $alice->id, $id, 'view', false],
            [$bob->id, $alice->id, $id, 'revoke', false],
            [$alice->id, $alice->id, $id, 'revoke', true],
        ], $seen, 'No event for a refusal: nothing to widen.');

        $this->dispatcher()->addListener(SessionIdAccessCheckEvent::class, static function (): never {
            throw new RuntimeException('listener down');
        });
        self::assertFalse($this->access()->canViewSession(SessionActor::of($bob, ['ROLE_ADMIN']), $id), 'A failing listener is a refusal.');
    }

    public function testACheckerWithoutTheIdTwinRefusesEverything(): void
    {
        [$alice]    = $this->owners();
        $id         = $this->register($alice, 'h-alice');
        $logger     = new RecordingLogger();
        $objectOnly = new class implements SessionsAccessCheckerInterface {
            public function canAccessOwn(?object $user): bool
            {
                return true;
            }

            public function canManage(?object $actor, object $subject): bool
            {
                return true;
            }
        };
        $access = new SessionAccess($this->store(), $this->manager(), $objectOnly, null, $logger);
        self::assertFalse($access->canViewSession(SessionActor::of($alice, ['ROLE_USER']), $id));
        self::assertStringContainsString('does not implement SessionsIdAccessCheckerInterface', $logger->messages[0] ?? '');

        $failing = new class implements SessionsAccessCheckerInterface, SessionsIdAccessCheckerInterface {
            public function canAccessOwn(?object $user): bool
            {
                return true;
            }

            public function canManage(?object $actor, object $subject): bool
            {
                return true;
            }

            public function canAccessOwnById(SessionActor $actor): bool
            {
                throw new RuntimeException('directory down');
            }

            public function canManageById(SessionActor $actor, SessionUser $subject): bool
            {
                return true;
            }
        };
        $access = new SessionAccess($this->store(), $this->manager(), $failing, null, $logger);
        self::assertFalse($access->canViewSession(SessionActor::of($alice), $id), 'A failing checker is a refusal.');
        self::assertTrue($access->canViewSession(SessionActor::of(new SessionUser(Member::class, 'other')), $id), 'Another actor goes through canManageById.');
        self::assertFalse((new SessionAccess($this->store(), $this->manager(), $failing))->canViewSession($alice, $id), 'No logger needed.');
    }

    /**
     * A 1.22 container with the pages off and an `access_checker` id that does not exist compiled (the checker was only
     * registered with the pages): it still compiles, and the id path answers no.
     */
    public function testAMissingCheckerWithoutThePagesFailsClosedAndKeepsTheContainerCompiling(): void
    {
        $this->setUpDatabase('database', ['web_ui' => ['enabled' => false], 'security' => ['access_checker' => 'app.missing_checker']]);
        $alice = new SessionUser(Member::class, '1');
        $id    = $this->register($alice, 'h-alice');

        foreach ([SessionActor::of($alice, ['ROLE_USER', 'ROLE_ADMIN']), SessionActor::of(new SessionUser(Member::class, '2'), ['ROLE_ADMIN'])] as $actor) {
            self::assertFalse($this->access()->canViewSession($actor, $id));
            self::assertFalse($this->access()->canRevokeSession($actor, $id));
        }
        $checker = $this->container()->get(SessionsAccessCheckerInterface::class);
        self::assertInstanceOf(UnavailableSessionsAccessChecker::class, $checker);
        self::assertSame('app.missing_checker', $checker->serviceId);
        self::assertFalse($checker->canAccessOwn(new stdClass()));
        self::assertFalse($checker->canManage(new stdClass(), new stdClass()));
    }

    public function testAStoredRowWithAnUnusableOwnerIsNeverReturned(): void
    {
        $this->setUpDatabase();
        $now = $this->clock()->now();
        $this->connection()->insert($this->store()->table(), [
            'public_id'  => str_pad('', 32, 'a'), 'user_type' => Member::class, 'user_id' => 'alice ', 'session_hash' => 'h-pad', 'browser' => 'Firefox', 'os' => 'Linux',
            'created_at' => $now, 'last_seen_at' => $now,
        ], ['created_at' => 'datetime_immutable', 'last_seen_at' => 'datetime_immutable']);

        self::assertNull($this->store()->sessionByPublicId(str_pad('', 32, 'a')), 'A padded owner written before 1.16.1 is no owner.');
    }

    public function testRevocationEventsCarryTheActorReferenceAndTheInstant(): void
    {
        $this->setUpDatabase();
        $alice = $this->member('alice@example.com');
        $admin = $this->member('admin@example.com', ['ROLE_ADMIN']);
        $owner = $this->manager()->owner($alice);
        self::assertInstanceOf(SessionUser::class, $owner);
        $id = $this->register($owner, 'h-alice');

        self::assertTrue($this->manager()->revoke($alice, $id, $admin));
        $event = $this->log()->revoked[0] ?? self::fail('No revocation event.');
        self::assertSame((string) $admin->getId(), $event->actorRef?->id);
        self::assertSame(Member::class, $event->actorRef->type);
        self::assertTrue($event->byOther());
        self::assertEquals($this->clock()->now(), $event->occurredAt);

        $this->log()->clear();
        $this->manager()->revokeAll($alice, null, RevocationReason::PasswordChanged);
        $automatic = $this->log()->revoked[0] ?? self::fail('No revocation event.');
        self::assertNull($automatic->actorRef, 'Automatic revocation: no actor.');
        self::assertFalse($automatic->byOther());
    }

    /**
     * Audit YR-U-L1 (1.23.1): inside the application's transaction the actor's reference is resolved before the
     * commit. A database failure of a resolver that queries is rethrown, not swallowed: it may have rolled the
     * revocation back (a deadlock on MySQL) or aborted the transaction (PostgreSQL), and no event may say "revoked".
     */
    public function testADatabaseFailureOfTheActorResolverInsideTheHostTransactionIsRethrown(): void
    {
        $this->setUpDatabase(sessions: ['owner' => ['resolver' => 'test.database_failing_owners']]);
        $alice = $this->member('alice@example.com');
        $owner = $this->manager()->owner($alice);
        self::assertInstanceOf(SessionUser::class, $owner);
        self::assertSame((string) $alice->getId(), $owner->id);
        $this->register($owner, 'h-yr-1');
        $this->register($owner, 'h-yr-2');
        $this->log()->clear();

        $this->connection()->beginTransaction();
        try {
            $this->manager()->revokeOthers($alice, null, new stdClass());
            self::fail('The database failure was swallowed.');
        } catch (DBALException $exception) {
            self::assertInstanceOf(TableNotFoundException::class, $exception);
        }
        $this->connection()->rollBack();

        self::assertSame([], $this->log()->revoked, 'No event says "revoked".');
        self::assertSame(['h-yr-1', 'h-yr-2'], $this->sorted($this->store()->liveAmong(['h-yr-1', 'h-yr-2'])), 'The host rolled back: the sessions are still live.');
        self::assertSame(2, $this->manager()->revokeOthers($alice, null), 'Outside the failure the same revocation goes through.');
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    public function testTheServiceIsPublicAndStateless(): void
    {
        [$alice] = $this->owners();
        $id      = $this->register($alice, 'h-alice');
        $access  = $this->access();
        self::assertInstanceOf(SessionAccess::class, $access);
        self::assertTrue($access->canViewSession(SessionActor::of($alice, ['ROLE_USER']), $id));
        $this->store()->markRevoked('h-alice', $alice, RevocationReason::SignedOut->value, $this->clock()->now());
        self::assertFalse($access->canViewSession(SessionActor::of($alice, ['ROLE_USER']), $id), 'Read again on every call (worker mode).');
    }

    /**
     * @return array{SessionUser, SessionUser}
     */
    private function owners(): array
    {
        $this->setUpDatabase();

        return [new SessionUser(Member::class, '1'), new SessionUser(Member::class, '2')];
    }

    /**
     * Registers a session of `$owner` and returns its public id.
     */
    private function register(SessionUser $owner, string $hash): string
    {
        self::assertTrue($this->store()->insert($owner, $hash, new SessionClient('Firefox', 'Linux', '192.0.2.0'), $this->clock()->now()));

        return (string) $this->connection()->fetchOne(sprintf('SELECT public_id FROM %s WHERE session_hash = ?', $this->store()->table()), [$hash]);
    }

    private function access(): SessionAccessInterface
    {
        $access = $this->container()->get(SessionAccessInterface::class);
        self::assertInstanceOf(SessionAccessInterface::class, $access);

        return $access;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
