<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load\Support;

use Nowo\UserKitBundle\Sessions\Client\DeviceIdProviderInterface;
use Nowo\UserKitBundle\Sessions\Model\DeviceContext;

use function getenv;
use function is_numeric;
use function usleep;

/**
 * Widens the window of a sign-in between "count the owner's sessions" and "register this one": the registration asks
 * the device id provider first. `USER_KIT_LOAD_SLOW_DEVICE_MS` milliseconds (0 when unset).
 */
final class SlowDeviceId implements DeviceIdProviderInterface
{
    public function deviceIdFor(DeviceContext $context): ?string
    {
        $ms = getenv('USER_KIT_LOAD_SLOW_DEVICE_MS');
        if (is_numeric($ms) && (int) $ms > 0) {
            usleep((int) $ms * 1000);
        }

        return null;
    }
}
