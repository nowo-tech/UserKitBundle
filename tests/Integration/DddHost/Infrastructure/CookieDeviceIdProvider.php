<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\DddHost\Infrastructure;

use Nowo\UserKitBundle\Sessions\Client\DeviceIdProviderInterface;
use Nowo\UserKitBundle\Sessions\Model\DeviceContext;

/**
 * Framework-free device id port: the `device` cookie set by the host's device-fingerprinting code.
 */
final class CookieDeviceIdProvider implements DeviceIdProviderInterface
{
    public function deviceIdFor(DeviceContext $context): ?string
    {
        return $context->cookie('device');
    }
}
