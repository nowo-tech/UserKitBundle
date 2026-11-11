<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Client\DeviceIdProviderInterface;
use Nowo\UserKitBundle\Sessions\Client\DeviceIdResolverInterface;
use Nowo\UserKitBundle\Sessions\Client\SessionClientFactory;
use Nowo\UserKitBundle\Sessions\Limit\ConfiguredSessionLimitResolver;
use Nowo\UserKitBundle\Sessions\Model\DeviceContext;
use Nowo\UserKitBundle\Sessions\Model\SessionLimitPolicy;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Revocation\CacheRevocationStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

final class ClientAndLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function userAgents(): iterable
    {
        yield 'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge', 'Windows'];
        yield 'opera' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Chrome/130.0 Safari/537.36 OPR/115.0', 'Opera', 'macOS'];
        yield 'samsung' => ['Mozilla/5.0 (Linux; Android 14) SamsungBrowser/26.0 Chrome/122.0 Mobile Safari/537.36', 'Samsung Internet', 'Android'];
        yield 'safari ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari', 'iOS'];
        yield 'chromeos' => ['Mozilla/5.0 (X11; CrOS x86_64 15000.0.0) Chrome/130.0 Safari/537.36', 'Chrome', 'ChromeOS'];
        yield 'unknown' => ['curl/8.0', '', ''];
    }

    #[DataProvider('userAgents')]
    public function testUserAgentLabels(string $userAgent, string $browser, string $os): void
    {
        $client = (new SessionClientFactory())->fromRequest(Request::create('/', server: ['HTTP_USER_AGENT' => $userAgent, 'REMOTE_ADDR' => '198.51.100.23']));

        self::assertSame($browser, $client->browser);
        self::assertSame($os, $client->os);
        self::assertSame('198.51.100.0/24', $client->ip);
        self::assertNull($client->deviceId);
    }

    public function testIpModes(): void
    {
        self::assertSame('198.51.0.0/16', (new SessionClientFactory(ipv4Prefix: 16))->ip('198.51.100.23'));
        self::assertSame('2001:db8:85a3::/48', (new SessionClientFactory())->ip('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        self::assertSame('2001:db8:85a3:8d3::/64', (new SessionClientFactory(ipv6Prefix: 64))->ip('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        self::assertNull((new SessionClientFactory())->ip('not-an-ip'));
        self::assertNull((new SessionClientFactory())->ip(null));
        self::assertNull((new SessionClientFactory('none'))->ip('198.51.100.23'));
        $hash = (new SessionClientFactory('hash', secret: 's'))->ip('198.51.100.23');
        self::assertMatchesRegularExpression('/^h:[0-9a-f]{16}$/', (string) $hash);
        self::assertSame($hash, (new SessionClientFactory('hash', secret: 's'))->ip('198.51.100.99'));
        self::assertNotSame($hash, (new SessionClientFactory('hash', secret: 'other'))->ip('198.51.100.23'));
    }

    public function testUserAgentCanBeDropped(): void
    {
        $client = (new SessionClientFactory(userAgentMode: 'none'))->fromRequest(Request::create('/', server: ['HTTP_USER_AGENT' => 'Firefox/131.0']));
        self::assertSame('', $client->browser);
    }

    public function testDeviceIds(): void
    {
        $resolver = new class implements DeviceIdResolverInterface {
            public function deviceId(Request $request): ?string
            {
                return $request->headers->get('X-Device');
            }
        };
        $this->expectUserDeprecationMessage('Since nowo-tech/user-kit-bundle 1.15: implementing "DeviceIdResolverInterface" for "sessions.client.device_id_resolver" is deprecated, implement "DeviceIdProviderInterface" instead.');
        $factory = new SessionClientFactory(deviceIds: $resolver);
        self::assertNull($factory->deviceId(Request::create('/', server: ['HTTP_X_DEVICE' => ''])));
        self::assertSame(str_repeat('d', 64), $factory->deviceId(Request::create('/', server: ['HTTP_X_DEVICE' => str_repeat('d', 80)])));
    }

    /**
     * The framework-free provider and the deprecated `Request` resolver give the same stored device id for the same
     * request (empty → null, cut to 64 characters, header lookup case-insensitive), and a service implementing both is
     * called through the new port.
     */
    public function testDeviceIdProviderIsEquivalentToTheLegacyResolver(): void
    {
        $legacy = new class implements DeviceIdResolverInterface {
            public function deviceId(Request $request): ?string
            {
                return $request->headers->get('X-Device');
            }
        };
        $provider = new class implements DeviceIdProviderInterface {
            public function deviceIdFor(DeviceContext $context): ?string
            {
                return $context->header('X-Device');
            }
        };
        $both = new class implements DeviceIdProviderInterface, DeviceIdResolverInterface {
            public function deviceIdFor(DeviceContext $context): string
            {
                return 'provider';
            }

            public function deviceId(Request $request): string
            {
                return 'legacy';
            }
        };

        $this->expectUserDeprecationMessageMatches('/DeviceIdResolverInterface/');
        $old = new SessionClientFactory('hash', userAgentMode: 'none', secret: 'k', deviceIds: $legacy);
        $new = new SessionClientFactory('hash', userAgentMode: 'none', secret: 'k', deviceIds: $provider);
        foreach ([null, '', 'tablet-1', str_repeat('d', 80), 'ñandú-é'] as $device) {
            $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.9'] + ($device === null ? [] : ['HTTP_X_DEVICE' => $device]));
            self::assertSame($old->deviceId($request), $new->deviceId($request), (string) $device);
            self::assertEquals($old->fromRequest($request), $new->fromRequest($request), (string) $device);
        }
        self::assertSame('provider', (new SessionClientFactory(deviceIds: $both))->deviceId(new Request()));
        self::assertNull((new SessionClientFactory())->deviceId(new Request()));
    }

    public function testDeviceContextOfARequest(): void
    {
        $request = Request::create('/', cookies: ['device' => 'd-1', 'nested' => ['a' => 'b']], server: [
            'REMOTE_ADDR'     => '203.0.113.9',
            'HTTP_USER_AGENT' => 'Firefox/131.0',
            'HTTP_X_DEVICE'   => 'tablet-1',
        ]);
        $context = SessionClientFactory::deviceContext($request);

        self::assertSame('203.0.113.9', $context->clientIp);
        self::assertSame('Firefox/131.0', $context->userAgent);
        self::assertSame('tablet-1', $context->header('X-Device'));
        self::assertSame('d-1', $context->cookie('device'));
        self::assertNull($context->cookie('nested'));
    }

    public function testInvalidModes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionClientFactory('full');
    }

    public function testInvalidPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionClientFactory(ipv6Prefix: 12);
    }

    public function testConfiguredLimit(): void
    {
        $limit = (new ConfiguredSessionLimitResolver(3, 'reject_new'))->resolve(new stdClass());
        self::assertSame(3, $limit->maxSessions);
        self::assertSame(SessionLimitPolicy::RejectNew, $limit->policy);
        self::assertFalse((new ConfiguredSessionLimitResolver())->resolve(new stdClass())->isLimited());
    }

    public function testCacheRevocationStore(): void
    {
        $owner = new SessionUser('App\\User', '1');
        $store = new CacheRevocationStore(new ArrayAdapter(), 60);
        self::assertNull($store->reason('h1', $owner));
        $store->mark(['h1', 'h2'], 'admin');
        self::assertSame('admin', $store->reason('h1', $owner));
        self::assertSame('admin', $store->reason('h2', new SessionUser('App\\Other', '9')), 'The marker is per session (any owner).');
    }

    public function testEmbeddedIpv4IsTruncatedAsIpv4(): void
    {
        $factory = new SessionClientFactory();
        self::assertSame('203.0.113.0/24', $factory->ip('::ffff:203.0.113.77'));
        self::assertSame('203.0.113.0/24', $factory->ip('::203.0.113.77'));
        self::assertSame('::1/48', $factory->ip('::1'), 'Loopback is not an embedded IPv4 address.');
        self::assertSame('2001:db8:85a3::/48', $factory->ip('2001:db8:85a3:1234:5678:8a2e:370:7334'));
        self::assertNull(SessionClientFactory::embeddedIpv4('203.0.113.77'));
        $hash = new SessionClientFactory('hash', secret: 's');
        self::assertSame($hash->ip('203.0.113.1'), $hash->ip('::ffff:203.0.113.77'));
    }
}
