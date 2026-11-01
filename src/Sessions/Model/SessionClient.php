<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use Nowo\UserKitBundle\Sessions\Client\SessionClientFactory;

/**
 * What the registry keeps about the client of a session (data minimisation): browser and OS names (no versions, no raw
 * user agent), an optional device id from the host, and the IP as configured (`sessions.client.ip`: a truncated
 * network, a keyed hash, or nothing). Built by {@see SessionClientFactory}.
 */
final readonly class SessionClient
{
    public function __construct(
        public string $browser = '',
        public string $os = '',
        public ?string $ip = null,
        public ?string $deviceId = null,
    ) {
    }
}
