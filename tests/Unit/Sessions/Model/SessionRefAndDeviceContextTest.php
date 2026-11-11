<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions\Model;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Model\DeviceContext;
use Nowo\UserKitBundle\Sessions\Model\SessionRef;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function print_r;
use function serialize;

use const JSON_THROW_ON_ERROR;

/**
 * The framework-free session / device value objects never expose a session id or a cookie value in a dump.
 */
final class SessionRefAndDeviceContextTest extends TestCase
{
    private const SESSION_ID = 'REDACTED-TEST-session-id';

    public function testSessionRefKeepsTheId(): void
    {
        self::assertSame(self::SESSION_ID, (new SessionRef(self::SESSION_ID))->sessionId());
    }

    public function testSessionRefNeedsAnId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionRef('');
    }

    public function testSessionRefDumpsHideTheId(): void
    {
        $ref = new SessionRef(self::SESSION_ID);

        self::assertSame(['sessionId' => '[redacted]'], $ref->__debugInfo());
        self::assertStringNotContainsString(self::SESSION_ID, print_r($ref, true));
        self::assertSame('{}', json_encode($ref, JSON_THROW_ON_ERROR));
    }

    public function testSessionRefIsNeverSerialized(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^A session reference cannot be serialized; pass its registry hash instead\.$/');
        serialize(new SessionRef(self::SESSION_ID));
    }

    public function testDeviceContextLooksUpHeadersCaseInsensitively(): void
    {
        $context = new DeviceContext('203.0.113.7', 'Firefox/131.0', ['X-Device' => 'tablet-1'], ['device' => 'd-1']);

        self::assertSame('203.0.113.7', $context->clientIp);
        self::assertSame('Firefox/131.0', $context->userAgent);
        self::assertSame('tablet-1', $context->header('x-device'));
        self::assertSame('tablet-1', $context->header('X-DEVICE'));
        self::assertNull($context->header('Authorization'));
        self::assertSame('d-1', $context->cookie('device'));
        self::assertNull($context->cookie('Device'));
    }

    public function testDeviceContextDumpsShowNamesOnly(): void
    {
        $context = new DeviceContext(null, '', ['Authorization' => 'REDACTED-TEST-auth'], ['PHPSESSID' => self::SESSION_ID]);

        self::assertSame(['clientIp' => null, 'userAgent' => '', 'headers' => ['authorization'], 'cookies' => ['PHPSESSID']], $context->__debugInfo());
        $dump = print_r($context, true);
        self::assertStringNotContainsString(self::SESSION_ID, $dump);
        self::assertStringNotContainsString('REDACTED-TEST-auth', $dump);
    }
}
