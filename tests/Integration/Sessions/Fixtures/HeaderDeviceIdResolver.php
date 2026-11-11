<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Sessions\Client\DeviceIdProviderInterface;
use Nowo\UserKitBundle\Sessions\Model\DeviceContext;

/**
 * Device id from the `X-Device` test header (framework-free port).
 */
final class HeaderDeviceIdResolver implements DeviceIdProviderInterface
{
    public function deviceIdFor(DeviceContext $context): ?string
    {
        return $context->header('X-Device');
    }
}
