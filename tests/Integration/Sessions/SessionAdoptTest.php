<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Sessions\Event\UserSessionStartedEvent;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * {@see UserSessionManager::adopt()} (GY-L7, HP-I2): a failed sign-in hands the session back to the earlier account, and
 * revocation and idle expiry reach that session at once.
 */
final class SessionAdoptTest extends TransactionalSessionsTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'database store' => ['database'];
        yield 'cache store' => ['cache'];
    }

    #[DataProvider('stores')]
    public function testTheRestoredSessionIsRegisteredOnTheFailedRequestItself(string $store): void
    {
        $this->setUpDatabase($store);
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');

        $browser->request('GET', '/page/failed?target=bob@example.com');
        self::assertSame('restored', $this->body($browser));

        // On this request: without adopt() there is no row until the next stateful one.
        self::assertCount(1, $this->rowsOf($alice));

        $browser->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($browser));
        self::assertCount(1, $this->rowsOf($alice), 'The next request registers nothing more.');
    }

    #[DataProvider('stores')]
    public function testSignOutEverywhereReachesTheRestoredSessionWithoutAStatefulRequestInBetween(string $store): void
    {
        $this->setUpDatabase($store);
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');
        $browser->request('GET', '/page/failed?target=bob@example.com');

        self::assertSame(1, $this->manager()->revokeAll($this->fresh($alice)), 'GY-L7 R2: it revoked 0.');

        $browser->request('GET', '/page/stateless');
        self::assertRedirectsTo($browser, '/login');
    }

    public function testIdleExpiryReachesTheRestoredSession(): void
    {
        $this->setUpDatabase();
        $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');
        $browser->request('GET', '/page/failed?target=bob@example.com');

        $this->clock()->modify('+3 days');

        // GY-L7 R4: without a marker the session stayed alive.
        $browser->request('GET', '/page/stateless');
        self::assertRedirectsTo($browser, '/login');
    }

    #[DataProvider('stores')]
    public function testARevocationBeforeTheSignInDeletedTheRowIsNotLost(string $store): void
    {
        $this->setUpDatabase($store);
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');

        $browser->request('GET', '/page/failed?target=bob@example.com&revoke=before');
        self::assertCount(0, $this->rowsOf($alice));
        self::assertSame(RevocationReason::AccountChanged->value, $this->connection()->fetchOne('SELECT revoked_reason FROM ' . $this->store()->table() . ' WHERE revoked_at IS NOT NULL'), 'The reason of the revocation stays.');

        $browser->request('GET', '/page');
        self::assertRedirectsTo($browser, '/login');
    }

    #[DataProvider('stores')]
    public function testARevocationBeforeARolledBackSignInIsNotLost(string $store): void
    {
        $this->setUpDatabase($store);
        $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');

        $browser->request('GET', '/page/failed?target=bob@example.com&revoke=before&tx=1');
        self::assertSame('restored', $this->body($browser));

        $browser->request('GET', '/page');
        self::assertRedirectsTo($browser, '/login');
    }

    /**
     * What another connection's revocation does once the rollback released the row it was waiting for.
     */
    #[DataProvider('stores')]
    public function testARevocationOfTheRowARollbackBroughtBackIsNotLost(string $store): void
    {
        $this->setUpDatabase($store);
        $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');

        $browser->request('GET', '/page/failed?target=bob@example.com&revoke=rolled-back&tx=1');
        self::assertSame('restored', $this->body($browser));

        $browser->request('GET', '/page');
        self::assertRedirectsTo($browser, '/login');
    }

    /**
     * Documented gap (docs/SECURITY.md): between the delete and adopt() the session has no row, and without a transaction
     * holding that row nothing can record a revocation. This test pins the behaviour, it does not endorse it.
     */
    public function testARevocationInTheWindowWithoutARowIsNotSeen(): void
    {
        $this->setUpDatabase();
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');

        $browser->request('GET', '/page/failed?target=bob@example.com&revoke=after');

        self::assertCount(1, $this->rowsOf($alice));
        $browser->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($browser));
        self::assertSame(1, $this->manager()->revokeAll($this->fresh($alice)), 'Listed, so the next sign-out everywhere reaches it.');
    }

    public function testARowARollbackBroughtBackFollowsTheSessionInsteadOfStayingBehind(): void
    {
        $this->setUpDatabase();
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser  = $this->signIn('alice@example.com');
        $publicId = $this->rowsOf($alice)[0]->getPublicId();

        // Under `touch_interval`: only adopt() writes the activity of this request.
        $this->clock()->modify('+1 minute');
        $browser->request('GET', '/page/failed?target=bob@example.com&tx=1');
        self::assertSame('restored', $this->body($browser));

        // HP-I2: two rows after the next request, one of them for an id nobody holds.
        self::assertSame(1, $this->allRows());
        $rows = $this->rowsOf($alice);
        self::assertCount(1, $rows);
        self::assertSame($publicId, $rows[0]->getPublicId(), 'The same row: its public id and creation time stay.');
        self::assertEquals($this->manager()->now(), $rows[0]->getLastSeenAt(), 'This request counts as activity.');

        $browser->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($browser));
        self::assertSame(1, $this->allRows());
    }

    public function testNoLimitEvictionOrSignInEvent(): void
    {
        $this->setUpDatabase(sessions: ['limit' => ['max_sessions' => 1, 'policy' => 'evict_oldest']]);
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');
        $this->log()->clear();

        // A session of alice's appears while the failed sign-in runs. A sign-in would evict one of the two.
        $this->store()->insert($this->manager()->owner($alice) ?? self::fail(), 'other-device', new SessionClient(), $this->manager()->now());
        $browser->request('GET', '/page/failed?target=bob@example.com');

        self::assertCount(2, $this->rowsOf($alice));
        self::assertSame([], $this->log()->revoked);
        $atLogin = array_values(array_filter($this->log()->started, static fn (UserSessionStartedEvent $e): bool => $e->owner->id === (string) $alice->getId()));
        self::assertCount(1, $atLogin);
        self::assertFalse($atLogin[0]->atLogin, 'adopt() is not a sign-in.');
    }

    public function testNoSignInEventWhenTheRowComesBackFromTheRollback(): void
    {
        $this->setUpDatabase();
        $this->member('alice@example.com');
        $bob     = $this->member('bob@example.com');
        $browser = $this->signIn('alice@example.com');
        $this->log()->clear();

        $browser->request('GET', '/page/failed?target=bob@example.com&tx=1');

        self::assertSame([(string) $bob->getId()], array_map(static fn (UserSessionStartedEvent $e): string => $e->owner->id, $this->log()->started), 'Only the rolled-back sign-in; nothing for alice.');
    }

    public function testASecondCallChangesNothing(): void
    {
        $this->setUpDatabase();
        $alice               = $this->member('alice@example.com');
        [$request, $session] = $this->request();

        $this->manager()->adopt($request, $alice);
        $marker = $this->manager()->markers($session);
        self::assertSame(1, $this->allRows());
        self::assertCount(1, $this->log()->started);
        $this->clock()->modify('+1 minute');

        $this->manager()->adopt($request, $alice);
        self::assertSame($marker, $this->manager()->markers($session));
        self::assertSame(1, $this->allRows());
        self::assertCount(1, $this->log()->started);
    }

    public function testAMarkerOfAnotherAccountIsReplaced(): void
    {
        $this->setUpDatabase();
        $alice               = $this->member('alice@example.com');
        $bob                 = $this->member('bob@example.com');
        [$request, $session] = $this->request();
        $this->manager()->adopt($request, $bob);

        $this->manager()->adopt($request, $alice);

        self::assertSame($this->manager()->owner($alice)?->key(), $this->manager()->markers($session)['main']['u']);
        self::assertCount(0, $this->rowsOf($bob));
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testAMarkerOfTheSameAccountUnderAnOldIdIsRekeyed(): void
    {
        $this->setUpDatabase();
        $alice               = $this->member('alice@example.com');
        [$request, $session] = $this->request();
        $this->manager()->adopt($request, $alice);
        $publicId = $this->rowsOf($alice)[0]->getPublicId();
        $session->migrate();

        $this->manager()->adopt($request, $alice);

        self::assertSame($this->manager()->hash($session->getId()), $this->manager()->markers($session)['main']['h']);
        self::assertSame($publicId, $this->rowsOf($alice)[0]->getPublicId());
        self::assertSame(1, $this->allRows());
    }

    public function testAMarkerWhoseRowIsGoneComesBackRevoked(): void
    {
        $this->setUpDatabase();
        $alice               = $this->member('alice@example.com');
        [$request, $session] = $this->request();
        $this->manager()->adopt($request, $alice);
        $owner = $this->manager()->owner($alice) ?? self::fail();
        $this->store()->deleteFor($owner);
        $session->migrate();

        $this->manager()->adopt($request, $alice);

        self::assertSame(['revoked' => true, 'reason' => RevocationReason::SignedOut->value], $this->store()->state($this->manager()->hash($session->getId()), $owner));
        self::assertCount(0, $this->rowsOf($alice));
    }

    public function testAMarkerOfAnUnknownOwnerIsReplacedWithoutTouchingARow(): void
    {
        $this->setUpDatabase();
        $alice               = $this->member('alice@example.com');
        [$request, $session] = $this->request();
        $session->set(UserSessionManager::ATTRIBUTE, ['main' => ['h' => 'x', 'u' => 'not-an-owner-key', 's' => 1]]);

        $this->manager()->adopt($request, $alice);

        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testEachFirewallContextHasItsOwnMarker(): void
    {
        $this->setUpDatabase(options: ['back_firewall' => true]);
        $alice               = $this->member('alice@example.com');
        [$request, $session] = $this->request('/back/page');
        $session->set(UserSessionManager::ATTRIBUTE, ['main' => ['h' => 'other', 'u' => $this->manager()->owner($alice)?->key(), 's' => 1]]);

        $this->manager()->adopt($request, $alice);

        $markers = $this->manager()->markers($session);
        self::assertSame(['main', 'back'], array_keys($markers));
        self::assertSame('other', $markers['main']['h']);
    }

    public function testAPendingSignInIsLeftToRegisterWhenItsTokenIsFinal(): void
    {
        $this->setUpDatabase();
        $alice               = $this->member('alice@example.com');
        [$request, $session] = $this->request();
        $session->set(SessionRegistryListener::PENDING_ATTRIBUTE, ['main' => true]);

        $this->manager()->adopt($request, $alice);

        self::assertSame([], $this->manager()->markers($session));
        self::assertSame(0, $this->allRows());
    }

    public function testNoOpsWithoutASessionOnStatelessRoutesAndFirewallsAndForForeignUsers(): void
    {
        $this->setUpDatabase();
        $alice = $this->member('alice@example.com');

        $this->manager()->adopt(Request::create('/page'), $alice);

        [$stateless, $statelessSession] = $this->request('/page');
        $stateless->attributes->set('_stateless', true);
        $this->manager()->adopt($stateless, $alice);
        self::assertSame([], $this->manager()->markers($statelessSession));

        [$api, $apiSession] = $this->request('/api/page');
        $this->manager()->adopt($api, $alice);
        self::assertSame([], $this->manager()->markers($apiSession));

        [$page, $pageSession] = $this->request();
        $this->manager()->adopt($page, new stdClass());
        self::assertSame([], $this->manager()->markers($pageSession));

        self::assertSame(0, $this->allRows());
    }

    /**
     * @return array{Request, Session}
     */
    private function request(string $path = '/page'): array
    {
        $request = Request::create($path);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        return [$request, $session];
    }
}
