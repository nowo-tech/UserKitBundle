<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Event\SessionLimitReachedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsPurgedEvent;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionLimit;
use Nowo\UserKitBundle\Sessions\Model\SessionLimitPolicy;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Ui\SessionRow;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ModelTest extends TestCase
{
    public function testSessionLimit(): void
    {
        self::assertFalse(SessionLimit::unlimited()->isLimited());
        self::assertTrue((new SessionLimit(2))->isLimited());
        self::assertSame(SessionLimitPolicy::EvictOldest, (new SessionLimit(2))->policy);

        $this->expectException(InvalidArgumentException::class);
        new SessionLimit(0);
    }

    public function testSessionUser(): void
    {
        $user = new SessionUser('App\User', '7');
        self::assertSame('App\User#7', $user->key());

        $this->expectException(InvalidArgumentException::class);
        new SessionUser('App\User', '');
    }

    public function testSessionUserKeys(): void
    {
        $owner = SessionUser::fromKey('App\\User#7#x');
        self::assertNotNull($owner);
        self::assertSame('App\\User', $owner->type);
        self::assertSame('7#x', $owner->id);
        self::assertNull(SessionUser::fromKey('no-separator'));
        self::assertNull(SessionUser::fromKey('#7'));
    }

    public function testSessionRowHidesTheHash(): void
    {
        $now     = new DateTimeImmutable('2026-11-01 10:00:00');
        $session = new UserSession(str_repeat('a', 32), 'App\\User', '7', str_repeat('b', 64), $now, $now, 'Firefox', 'Linux', 'h:0123456789abcdef', 'd-1');
        $row     = SessionRow::of($session, str_repeat('b', 64));
        self::assertTrue($row->current);
        self::assertNull($row->ip);
        self::assertSame('d-1', $row->deviceId);
        self::assertFalse(SessionRow::of($session)->current);
        self::assertArrayNotHasKey('sessionHash', get_object_vars($row));
    }

    public function testSessionUserRejectsLongIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionUser('App\User', str_repeat('x', 192));
    }

    public function testRevocationReasonMessageKeys(): void
    {
        self::assertSame('sessions.signed_out.evicted', RevocationReason::Evicted->messageKey());
    }

    public function testEntityGetters(): void
    {
        $now     = new DateTimeImmutable('2026-11-01 10:00:00');
        $session = new UserSession(str_repeat('a', 32), 'App\User', '7', str_repeat('b', 64), $now, $now, 'Firefox', 'Linux', '203.0.113.0/24', 'device-1', $now, 'admin', 5);

        self::assertSame(5, $session->getId());
        self::assertSame('device-1', $session->getDeviceId());
        self::assertSame($now, $session->getRevokedAt());
        self::assertSame('admin', $session->getRevokedReason());
        self::assertTrue($session->hasDisplayableIp());
        self::assertFalse((new UserSession('p', 't', 'i', 'h', $now, $now))->hasDisplayableIp());
    }

    public function testEvents(): void
    {
        $limit = new SessionLimit(1, SessionLimitPolicy::RejectNew);
        $event = new SessionLimitReachedEvent(new stdClass(), new SessionUser('t', '1'), $limit, 1);
        self::assertTrue($event->isRejected());
        $event->setMessage('custom');
        self::assertSame('custom', $event->getMessage());

        $purged = new UserSessionsPurgedEvent(new DateTimeImmutable(), 3);
        self::assertSame(3, $purged->deleted);
    }
}
