<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Client\DeviceIdProviderInterface;
use Nowo\UserKitBundle\Sessions\Client\SessionClientFactory;
use Nowo\UserKitBundle\Sessions\Model\DeviceContext;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function is_string;
use function json_encode;
use function print_r;
use function serialize;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * `sessions.client.context_attributes` (1.16): the host's explicit allowlist of raw request attributes, objects
 * included, readable through `DeviceContext::attribute()` and never visible in a dump of the context.
 */
final class DeviceContextAttributesTest extends TestCase
{
    private function request(): Request
    {
        $device       = new stdClass();
        $device->id   = 'dev-1';
        $device->hint = 'REDACTED-TEST-x';

        $request = Request::create('/', cookies: ['device' => 'd-1'], server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_DEVICE' => 'tablet-1']);
        $request->attributes->add(['_device' => $device, 'tenant' => 'org-a', 'nullable' => null]);

        return $request;
    }

    public function testTheDefaultEmptyAllowlistBuildsExactlyThe115Context(): void
    {
        $request = $this->request();
        $context = SessionClientFactory::deviceContext($request);

        self::assertEquals(SessionClientFactory::deviceContext($request, []), $context);
        self::assertEquals(new DeviceContext('203.0.113.9', 'Symfony', $this->headersOf($context), ['device' => 'd-1']), $context);
        foreach (['_device', 'tenant', 'nullable'] as $name) {
            self::assertNull($context->attribute($name), $name);
            self::assertFalse($context->hasAttribute($name), $name);
        }
        self::assertSame(['clientIp' => '203.0.113.9', 'userAgent' => 'Symfony', 'headers' => $context->__debugInfo()['headers'], 'cookies' => ['device']], $context->__debugInfo());
    }

    public function testAnAllowlistedAttributeIsGivenRaw(): void
    {
        $request = $this->request();
        $context = SessionClientFactory::deviceContext($request, ['_device', 'nullable', 'absent']);

        self::assertSame($request->attributes->get('_device'), $context->attribute('_device'), 'The same object.');
        self::assertTrue($context->hasAttribute('nullable'));
        self::assertNull($context->attribute('nullable'));
        self::assertFalse($context->hasAttribute('absent'));
        self::assertNull($context->attribute('tenant'), 'Not allowlisted.');
        self::assertNull($context->attribute('_Device'), 'Exact names.');
        self::assertSame('d-1', $context->cookie('device'));
    }

    public function testTheProviderReceivesTheAllowlistedAttributePerCall(): void
    {
        $provider = new class implements DeviceIdProviderInterface {
            public function deviceIdFor(DeviceContext $context): ?string
            {
                $device = $context->attribute('_device');

                return $device instanceof stdClass && is_string($device->id) ? $device->id : null;
            }
        };

        self::assertNull((new SessionClientFactory(deviceIds: $provider))->deviceId($this->request()), 'Default: no attribute.');
        $factory = new SessionClientFactory(deviceIds: $provider, contextAttributes: ['_device']);
        self::assertSame('dev-1', $factory->deviceId($this->request()));
        self::assertNull($factory->deviceId(Request::create('/')), 'Nothing kept from the previous request.');
        self::assertSame('dev-1', $factory->fromRequest($this->request())->deviceId);
    }

    public function testAllowlistedValuesNeverShowInADump(): void
    {
        $context = SessionClientFactory::deviceContext($this->request(), ['_device', 'tenant']);

        $dumper = new CliDumper();
        $dumper->setColors(false);
        $dumps = [
            'var_dumper' => (string) $dumper->dump((new VarCloner())->cloneVar($context), true),
            'var_export' => var_export($context, true),
            'array'      => print_r((array) $context, true),
            'print_r'    => print_r($context, true),
            'json'       => json_encode($context, JSON_THROW_ON_ERROR),
        ];
        foreach ($dumps as $kind => $dump) {
            self::assertStringNotContainsString('REDACTED-TEST-', $dump, $kind);
            self::assertStringNotContainsString('org-a', $dump, $kind);
        }

        $clone = clone $context;
        self::assertNull($clone->attribute('_device'));
        self::assertSame('org-a', $context->attribute('tenant'));

        $this->expectException(InvalidArgumentException::class);
        serialize($context);
    }

    /** @return array<string, string> */
    private function headersOf(DeviceContext $context): array
    {
        $headers = [];
        foreach ($context->__debugInfo()['headers'] as $name) {
            $headers[$name] = (string) $context->header($name);
        }

        return $headers;
    }
}
