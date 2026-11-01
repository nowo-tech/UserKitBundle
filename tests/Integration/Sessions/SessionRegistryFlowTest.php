<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionStartedEvent;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Registry life cycle on a real kernel: sign-in registers, requests touch (throttled), a revoked session is signed out
 * on its next request (two browsers), logout deletes the row, stateless firewalls are ignored.
 */
final class SessionRegistryFlowTest extends SessionsKernelTestCase
{
    public function testSignInRegistersTheSessionWithMinimisedClientData(): void
    {
        $this->boot();
        $alice  = $this->member('alice@example.com');
        $events = [];
        $this->dispatcher()->addListener(UserSessionStartedEvent::class, static function (UserSessionStartedEvent $e) use (&$events): void {
            $events[] = $e;
        });

        $browser = $this->signIn('alice@example.com');
        self::assertRedirectsTo($browser, '/page');
        $browser->request('GET', '/page', server: ['REMOTE_ADDR' => '203.0.113.77']);
        self::assertStringStartsWith('alice@example.com', $this->body($browser));

        $rows = $this->rowsOf($alice);
        self::assertCount(1, $rows);
        self::assertSame('Firefox', $rows[0]->getBrowser());
        self::assertSame('Linux', $rows[0]->getOs());
        self::assertSame('127.0.0.0/24', $rows[0]->getIp());
        self::assertSame(Member::class, $rows[0]->getUserType());
        self::assertSame((string) $alice->getId(), $rows[0]->getUserId());
        self::assertCount(1, $events);
        self::assertTrue($events[0]->atLogin);
    }

    public function testARevokedSessionIsSignedOutOnItsNextRequest(): void
    {
        $this->boot();
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->signIn('alice@example.com', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1');
        $phone->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($phone));
        self::assertCount(2, $this->rowsOf($alice));

        $revoked = [];
        $this->dispatcher()->addListener(UserSessionsRevokedEvent::class, static function (UserSessionsRevokedEvent $e) use (&$revoked): void {
            $revoked[] = $e;
        });
        $count = $this->manager()->revokeOthers($alice, $this->manager()->currentHash($laptop->getRequest()->getSession()));
        self::assertSame(1, $count);
        self::assertSame(RevocationReason::Others, $revoked[0]->reason);
        self::assertSame(1, $revoked[0]->count());

        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        $phone->request('GET', '/login');
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        $laptop->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($laptop));
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testLogoutDeletesTheRow(): void
    {
        $this->boot();
        $alice   = $this->member('alice@example.com');
        $browser = $this->signIn('alice@example.com');
        self::assertCount(1, $this->rowsOf($alice));

        $browser->request('GET', '/logout');
        self::assertCount(0, $this->rowsOf($alice));
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
