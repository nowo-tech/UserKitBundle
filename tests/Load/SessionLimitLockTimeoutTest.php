<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load;

use Nowo\UserKitBundle\Sessions\Event\SessionLimitReachedEvent;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Load\Support\LoadTestCase;
use Nowo\UserKitBundle\Tests\Load\Support\SessionLimitConfig;
use Nowo\UserKitBundle\Transaction\UserKitNamedLock;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function json_decode;

/**
 * Audit QM-M2 (from its proof of concept), MySQL 8.4 / PostgreSQL 16: a sign-in whose owner lock is held by another
 * session past the 5 s wait answers 409 + CONFLICT_001 (503 up to 1.20) **and is not signed in**: its next request is a guest's and the user keeps one
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
        // 1.21.0 (REQ-RUNTIME-003 rule 6): nothing was committed: 409 + Retry-After + CONFLICT_001 (503 up to 1.20).
        self::assertSame(409, $second->getResponse()->getStatusCode());
        self::assertSame('1', $second->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('data-conflict-code="CONFLICT_001"', (string) $second->getResponse()->getContent());

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

    /**
     * 1.21.0 (REQ-RUNTIME-003 rule 6), committed-then-conflict on a real server: `evict_oldest` commits the eviction
     * (autocommit), then a write of the sign-in (here a listener of SessionLimitReachedEvent going through UserKit's
     * runner) waits too long for a row lock the peer holds. The answer is 409 + CONFLICT_002 without Retry-After (a blind
     * retry is not invited: part of the sign-in is committed), the failed sign-in is not signed in, and the connection
     * works for the next sign-in.
     */
    public function testAnEvictionCommittedBeforeALockWaitIsConflict002WithoutRetryAfter(): void
    {
        $this->boot(SessionLimitConfig::userKit('evict_oldest'));
        $ada   = $this->member('ada@example.com');
        $id    = (int) $ada->getId();
        $first = $this->browser();
        $first->request('POST', '/login', ['_username' => 'ada@example.com', '_password' => 'secret']);
        self::assertSame(302, $first->getResponse()->getStatusCode());
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM user_kit_session WHERE revoked_at IS NULL'));

        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('SELECT id FROM user_kit_test_member WHERE id = ' . $id . ' FOR UPDATE'));
        $this->shortLockWait();
        $connection = $this->connection();
        $runner     = $this->kernel->getContainer()->get('nowo_user_kit.transaction_runner');
        self::assertInstanceOf(UserKitTransactionRunner::class, $runner);
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $lockWait = static function () use ($runner, $connection, $id): void {
            $runner->statement($connection, static fn (): mixed => $connection->fetchOne('SELECT id FROM user_kit_test_member WHERE id = ? FOR UPDATE', [$id]));
        };
        $dispatcher->addListener(SessionLimitReachedEvent::class, $lockWait);

        $second = $this->browser();
        $second->request('POST', '/login', ['_username' => 'ada@example.com', '_password' => 'secret'], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $response = $second->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('CONFLICT_002', $body['code']);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM user_kit_session WHERE revoked_at IS NULL'), 'the eviction stands, the failed sign-in is not registered');

        self::assertSame('OK', $peer->execute('ROLLBACK'));
        $this->assertHealthy();
        $dispatcher->removeListener(SessionLimitReachedEvent::class, $lockWait);
        $third = $this->browser();
        $third->request('POST', '/login', ['_username' => 'ada@example.com', '_password' => 'secret']);
        self::assertSame(302, $third->getResponse()->getStatusCode());
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
