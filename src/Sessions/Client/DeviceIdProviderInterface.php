<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Client;

use Nowo\UserKitBundle\Sessions\Model\DeviceContext;
use Nowo\UserKitBundle\Sessions\UserSessionManager;

/**
 * Optional host hook (`sessions.client.device_id_resolver`), framework-free: the id of the device behind a request (for
 * example a device-fingerprinting bundle's device id), stored with the session so
 * {@see UserSessionManager::revokeDevice()} can end that device's sessions. A device id is never a credential.
 *
 * Replaces {@see DeviceIdResolverInterface} (deprecated since 1.15, removed in 2.0). A service implementing both is
 * called through this interface.
 */
interface DeviceIdProviderInterface
{
    /**
     * Device id (at most 64 characters; longer ids are cut), or null when unknown.
     */
    public function deviceIdFor(DeviceContext $context): ?string;
}
