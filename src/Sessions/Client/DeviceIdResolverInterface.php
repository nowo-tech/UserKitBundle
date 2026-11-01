<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Client;

use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Optional host hook (`sessions.client.device_id_resolver`): the id of the device behind a request (for example a
 * device-fingerprinting bundle's device id), stored with the session so
 * {@see UserSessionManager::revokeDevice()} can end that device's sessions. A device id
 * is never a credential.
 */
interface DeviceIdResolverInterface
{
    /**
     * Device id (at most 64 characters), or null when unknown.
     */
    public function deviceId(Request $request): ?string;
}
