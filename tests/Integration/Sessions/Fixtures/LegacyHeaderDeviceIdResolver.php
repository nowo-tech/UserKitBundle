<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Sessions\Client\DeviceIdResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Device id from the `X-Device` test header through the deprecated `Request` port (BC / equivalence tests).
 */
final class LegacyHeaderDeviceIdResolver implements DeviceIdResolverInterface
{
    public function deviceId(Request $request): ?string
    {
        return $request->headers->get('X-Device');
    }
}
