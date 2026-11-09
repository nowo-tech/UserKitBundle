<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The registry touch on MySQL semantics: without CLIENT_FOUND_ROWS an UPDATE that writes the values the row
 * already holds reports 0 affected rows, so two requests of one session touching in the same second must not read as
 * a session that is gone. A session that really is gone (deleted, revoked) still ends. Runs on SQLite, and on MySQL and
 * PostgreSQL in the `real-databases` CI job.
 */
final class SessionTouchFoundRowsTest extends TransactionalSessionsTestCase
{
    public function testTheConnectionReportsChangedRowsOnMySql(): void
    {
        $this->setUpDatabase();
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        self::assertCount(1, $this->rowsOf($alice));

        $affected = (int) $this->connection()->executeStatement('UPDATE ' . $this->store()->table() . ' SET last_seen_at = last_seen_at');
        if ($this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // The precondition of the bug: the test connection does not set PDO::MYSQL_ATTR_FOUND_ROWS.
            self::assertSame(0, $affected, 'The MySQL test connection counts matched rows; the touch test below proves nothing.');
        } else {
            self::assertSame(1, $affected);
        }
    }

    public function testTwoTouchesInTheSameSecondBothFindTheLiveRow(): void
    {
        $this->setUpDatabase();
        $alice          = $this->member('alice@example.com');
        $browser        = $this->signIn('alice@example.com');
        [$hash, $owner] = $this->sessionOf($browser, $alice);
        $now            = $this->clock()->now()->modify('+1 hour');

        self::assertTrue($this->store()->touch($hash, $owner, $now));
        self::assertTrue($this->store()->touch($hash, $owner, $now), 'A no-op touch (0 affected rows on MySQL) read as a gone session.');
        self::assertSame($now->format('Y-m-d H:i:s'), $this->lastSeen($hash));
    }

    public function testATouchOfARevokedOrMissingRowStillFails(): void
    {
        $this->setUpDatabase();
        $alice          = $this->member('alice@example.com');
        $browser        = $this->signIn('alice@example.com');
        [$hash, $owner] = $this->sessionOf($browser, $alice);
        $now            = $this->clock()->now();

        self::assertFalse($this->store()->touch('0' . $hash, $owner, $now), 'Unknown session.');
        self::assertFalse($this->store()->touch($hash, new SessionUser($owner->type, $owner->id . '0'), $now), 'Another owner.');

        $this->store()->markRevoked($hash, $owner, 'admin', $now);
        self::assertFalse($this->store()->touch($hash, $owner, $now), 'Revoked row, same values as the revocation wrote.');

        $this->store()->deleteRow($hash, $owner);
        self::assertFalse($this->store()->touch($hash, $owner, $now), 'Deleted row.');
    }

    public function testAParallelTouchInTheSameSecondKeepsTheSessionSignedIn(): void
    {
        $this->setUpDatabase();
        $alice          = $this->member('alice@example.com');
        $browser        = $this->signIn('alice@example.com');
        [$hash, $owner] = $this->sessionOf($browser, $alice);

        $this->clock()->modify('+301 seconds');
        // A parallel request of the same session already wrote this second's last_seen_at.
        self::assertTrue($this->store()->touch($hash, $owner, $this->clock()->now()));

        $browser->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($browser), 'The parallel touch signed the user out.');
        self::assertCount(1, $this->rowsOf($alice));
        $browser->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($browser));
    }

    public function testADeletedSessionIsStillSignedOutAtTheNextTouch(): void
    {
        $this->setUpDatabase();
        $alice          = $this->member('alice@example.com');
        $browser        = $this->signIn('alice@example.com');
        [$hash, $owner] = $this->sessionOf($browser, $alice);

        $this->store()->deleteRow($hash, $owner);
        $this->clock()->modify('+301 seconds');

        $browser->request('GET', '/page');
        self::assertRedirectsTo($browser, '/login');
        self::assertCount(0, $this->rowsOf($alice));
        $browser->request('GET', '/page');
        self::assertRedirectsTo($browser, '/login');
    }

    /**
     * @return array{string, SessionUser}
     */
    private function sessionOf(KernelBrowser $browser, Member $member): array
    {
        $hash  = $this->manager()->currentHash($browser->getRequest()->getSession());
        $owner = $this->manager()->owner($member);
        self::assertIsString($hash);
        self::assertInstanceOf(SessionUser::class, $owner);

        return [$hash, $owner];
    }

    private function lastSeen(string $hash): string
    {
        $value = $this->connection()->fetchOne('SELECT last_seen_at FROM ' . $this->store()->table() . ' WHERE session_hash = :hash', ['hash' => $hash]);
        $date  = $this->connection()->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);
        self::assertInstanceOf(DateTimeImmutable::class, $date);

        return $date->format('Y-m-d H:i:s');
    }
}
