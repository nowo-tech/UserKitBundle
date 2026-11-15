<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Load\Support\LoadTestCase;
use Nowo\UserKitBundle\Tests\Load\Support\SessionLimitConfig;
use Nowo\UserKitBundle\Transaction\UserKitNamedLock;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Audit QM-M2 (from its proof of concept), MySQL 8.4 / PostgreSQL 16: a sign-in whose owner lock is held by another
 * session past the 5 s wait answers 503 **and is not signed in**: its next request is a guest's and the user keeps one
 * live session under `max_sessions: 1`. On the reviewed head the 503 left the token in the session and the next request
 * registered a second live session without the limit.
 */
final class SessionLimitLockTimeoutTest extends LoadTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function policies(): iterable
    {
        yield 'evict_oldest' => ['evict_oldest'];
        yield 'reject_new' => ['reject_new'];
    }

    #[DataProvider('policies')]
    public function testASignInThatTimesOutOnTheLockIsNotSignedIn(string $policy): void
    {
        $this->boot(SessionLimitConfig::userKit($policy));
        $ada   = $this->member('ada@example.com');
        $first = $this->browser();
        $first->request('POST', '/login', ['_username' => 'ada@example.com', '_password' => 'secret']);
        self::assertSame(302, $first->getResponse()->getStatusCode());
        if ($policy === 'reject_new') {
            // Below the limit, so the credentials step lets the second sign-in through to the lock.
            $first->request('GET', '/logout');
        }
        $owner = $this->get(UserSessionManager::class)->owner($ada);
        self::assertInstanceOf(SessionUser::class, $owner);
        $key = 'session_limit:' . $owner->key();
        // Another sign-in of ada holds the lock (a queue of sign-ins, a slow registration).
        $this->otherSession()->value($this->mysql() ? "SELECT GET_LOCK('" . UserKitNamedLock::name($key) . "', 1)" : 'SELECT pg_advisory_lock(' . UserKitNamedLock::advisoryKey($key) . ')');

        $second = $this->browser();
        $second->request('POST', '/login', ['_username' => 'ada@example.com', '_password' => 'secret']);
        self::assertSame(503, $second->getResponse()->getStatusCode());
        self::assertSame('1', $second->getResponse()->headers->get('Retry-After'));

        $this->otherSession()->value($this->mysql() ? "SELECT RELEASE_LOCK('" . UserKitNamedLock::name($key) . "')" : 'SELECT pg_advisory_unlock(' . UserKitNamedLock::advisoryKey($key) . ')');
        if ($policy === 'reject_new') {
            $third = $this->browser();
            $third->request('POST', '/login', ['_username' => 'ada@example.com', '_password' => 'secret']);
            self::assertSame(302, $third->getResponse()->getStatusCode());
        }
        $second->request('GET', '/page');
        self::assertSame(302, $second->getResponse()->getStatusCode(), 'the failed sign-in is a guest');
        self::assertStringEndsWith('/login', (string) $second->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM user_kit_session WHERE revoked_at IS NULL'));
    }

    private function browser(): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        $browser->catchExceptions(true);

        return $browser;
    }
}
