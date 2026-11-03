<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\Deprecations\Deprecation;
use Nowo\UserKitBundle\Sessions\Model\SessionState;
use Nowo\UserKitBundle\Sessions\Model\SessionStatus;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Support\SharedMemoryPool;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * 1.11.0 API for sessions that are not the request's own (spec 006): AuthKit's account switcher parks one session per
 * account and asks for its state, ends it, and moves its row after migrating its id.
 */
final class ParkedSessionsTest extends SessionsKernelTestCase
{
    public function testStateOfALiveSession(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');

        $state = $this->manager()->sessionState($this->sid($phone), $alice);
        self::assertTrue($state->isLive());
        self::assertSame(SessionStatus::Live, $state->status);
        self::assertNull($state->reason);
        self::assertNotNull($state->lastSeenAt);
    }

    public function testStateOfARevokedSessionCarriesItsReason(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->manager()->revokeAll($alice);

        $state = $this->manager()->sessionState($this->sid($phone), $alice);
        self::assertFalse($state->isLive());
        self::assertSame(SessionStatus::Revoked, $state->status);
        self::assertSame('everywhere', $state->reason);
    }

    public function testStateOfAnIdleSessionIsExpiredLikeTheEarlyCheck(): void
    {
        $this->boot(['idle_lifetime' => 600, 'touch_interval' => 60]);
        $alice = $this->member('alice@example.com');
        $sid   = $this->sid($this->signIn('alice@example.com'));

        $this->clock()->modify('+659 seconds');
        self::assertTrue($this->manager()->sessionState($sid, $alice)->isLive());
        $this->clock()->modify('+1 second');
        self::assertSame(SessionStatus::Expired, $this->manager()->sessionState($sid, $alice)->status);
    }

    public function testUnknownSessionsOwnersAndIds(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $bob   = $this->member('bob@example.com');
        $sid   = $this->sid($this->signIn('alice@example.com'));

        self::assertSame(SessionStatus::Revoked, $this->manager()->sessionState('not-a-session', $alice)->status, 'Database store: no row = revoked (fail closed).');
        self::assertSame(SessionStatus::Revoked, $this->manager()->sessionState($sid, $bob)->status, 'Another owner never sees the row.');
        self::assertSame(SessionStatus::Unknown, $this->manager()->sessionState($sid, new stdClass())->status);
        self::assertSame(SessionStatus::Unknown, $this->manager()->sessionState('', $alice)->status);
    }

    public function testStatesOfSeveralOwnersKeepTheInputOrder(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $bob   = $this->member('bob@example.com');
        $a1    = $this->sid($this->signIn('alice@example.com'));
        $a2    = $this->sid($this->signIn('alice@example.com'));
        $b1    = $this->sid($this->signIn('bob@example.com'));
        $this->manager()->revokeAll($bob);

        $states = $this->manager()->sessionStates([[$b1, $bob], [$a1, $alice], ['gone', $alice], [$a2, $alice], [$a1, new stdClass()]]);
        self::assertSame(
            [SessionStatus::Revoked, SessionStatus::Live, SessionStatus::Revoked, SessionStatus::Live, SessionStatus::Unknown],
            array_map(static fn (SessionState $state): SessionStatus => $state->status, $states),
        );
        self::assertSame([], $this->manager()->sessionStates([]));
        self::assertSame([], $this->store()->statesOf(new SessionUser('t', '1'), []));
    }

    public function testEndSessionSignsAParkedSessionOutLikeALogout(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $other = $this->signIn('alice@example.com');
        $sid   = $this->sid($phone);

        $this->manager()->endSession($sid, $alice);
        $this->manager()->endSession($sid, new stdClass());
        $this->manager()->endSession('', $alice);
        self::assertSame(SessionStatus::Revoked, $this->manager()->sessionState($sid, $alice)->status);
        self::assertCount(1, $this->rowsOf($alice), 'Only the ended session goes.');

        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        $other->request('GET', '/page');
        self::assertTrue($other->getResponse()->isSuccessful());
    }

    public function testRekeyMovesTheRowsOfTheMarkersToTheNewId(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $old   = $this->sid($this->signIn('alice@example.com'));
        $owner = $this->manager()->owner($alice);
        self::assertInstanceOf(SessionUser::class, $owner);
        $session = new Session(new MockArraySessionStorage());
        $session->setId('migrated-session-id');
        $session->start();
        $session->set(UserSessionManager::ATTRIBUTE, [
            'main'  => ['h' => $this->manager()->hash($old), 'u' => $owner->key(), 's' => 1],
            'other' => ['h' => 'unrelated', 'u' => $owner->key(), 's' => 1],
            'bad'   => ['h' => $this->manager()->hash($old), 'u' => 'malformed', 's' => 1],
        ]);

        $this->manager()->rekey($session, $old);
        self::assertSame(SessionStatus::Revoked, $this->manager()->sessionState($old, $alice)->status);
        self::assertTrue($this->manager()->sessionState('migrated-session-id', $alice)->isLive());
        $markers = $this->manager()->markers($session);
        self::assertSame($this->manager()->hash('migrated-session-id'), $markers['main']['h']);
        self::assertSame('unrelated', $markers['other']['h']);
        self::assertSame($this->manager()->hash($old), $markers['bad']['h']);
    }

    public function testRekeyIsANoOpWithoutAChangeOrAMatchingMarker(): void
    {
        $this->boot();
        $session = new Session(new MockArraySessionStorage());
        $session->setId('same-id');
        $session->start();
        $this->manager()->rekey($session, 'same-id');
        $this->manager()->rekey($session, 'other-id');
        self::assertFalse($session->has(UserSessionManager::ATTRIBUTE));
    }

    public function testARevokedAndPurgedSessionStaysRevoked(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $sid   = $this->sid($this->signIn('alice@example.com'));
        $this->manager()->revokeAll($alice);
        $this->em()->getConnection()->executeStatement('DELETE FROM user_kit_session');
        $state = $this->manager()->sessionState($sid, $alice);
        self::assertSame(SessionStatus::Revoked, $state->status, 'DE-M1: never "unknown" (revivable) after the retention purge.');
        self::assertSame('signed_out', $state->reason);
    }

    public function testTheCacheStoreMarkerOutlivesTheRow(): void
    {
        $this->boot(['revocation' => ['store' => 'cache', 'cache_pool' => 'test.revocations']], services: static function (ContainerConfigurator $c): void {
            $c->services()->set('test.revocations', SharedMemoryPool::class)->public();
        });
        $alice = $this->member('alice@example.com');
        $sid   = $this->sid($this->signIn('alice@example.com'));
        $other = $this->sid($this->signIn('alice@example.com'));
        $this->manager()->revoke($alice, $this->rowsOf($alice)[1]->getPublicId());
        $this->em()->getConnection()->executeStatement('DELETE FROM user_kit_session');
        $states = $this->manager()->sessionStates([[$sid, $alice], [$other, $alice]]);
        self::assertSame([SessionStatus::Revoked, SessionStatus::Unknown], [$states[0]->status, $states[1]->status], 'The revoked one is still revoked; the other has no row and no marker.');
    }

    public function testListingSessionsTriggersNoDoctrineDeprecation(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        Deprecation::enableTrackingDeprecations();
        $before = Deprecation::getTriggeredDeprecations();
        self::assertCount(1, $this->manager()->listFor($alice));
        self::assertSame($before, Deprecation::getTriggeredDeprecations(), 'doctrine/orm 3.7 deprecates string sort directions (SortDirection).');
    }

    private function sid(KernelBrowser $browser): string
    {
        $sid = (string) $browser->getCookieJar()->get('MOCKSESSID')?->getValue();
        self::assertNotSame('', $sid);

        return $sid;
    }
}
