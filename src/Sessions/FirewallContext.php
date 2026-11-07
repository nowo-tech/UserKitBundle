<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions;

use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which firewall context a request belongs to, and whether its session is registered at all. One marker and one
 * registry row exist per context, so the listener and {@see UserSessionManager::adopt()} must agree on both answers.
 */
final class FirewallContext
{
    /** Marker key of requests outside any firewall context. */
    public const DEFAULT_CONTEXT = 'main';

    /**
     * Firewall context of the request.
     */
    public static function of(?FirewallMap $firewalls, Request $request): string
    {
        $config = $firewalls?->getFirewallConfig($request);

        return $config?->getContext() ?? $config?->getName() ?? self::DEFAULT_CONTEXT;
    }

    /**
     * False on `_stateless` routes and stateless firewalls: their sessions are never registered.
     */
    public static function stateful(?FirewallMap $firewalls, Request $request): bool
    {
        if ($request->attributes->getBoolean('_stateless')) {
            return false;
        }
        $config = $firewalls?->getFirewallConfig($request);

        return !$config instanceof FirewallConfig || !$config->isStateless();
    }
}
