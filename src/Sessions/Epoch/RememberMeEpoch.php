<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Epoch;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;
use Symfony\Contracts\Service\ResetInterface;

use function hash_equals;
use function hash_hmac;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * `sessions.remember_me_epoch`: revokeAll / revokeOthers (and revoke / revokeDevice with `invalidateRememberMe`) move
 * the user's epoch forward, and every remember-me cookie issued before it is refused by {@see EpochRememberMeHandler}
 * (wrapped around the built-in handler of each firewall). So a copied cookie, or a browser whose registry row is gone
 * (purged, garbage-collected), cannot sign back in.
 *
 * The epoch is the **next whole second** after the revocation (cookies carry whole seconds), so a cookie issued earlier
 * in the same second is stale too. The kept session (revokeOthers / revokeAll `except`, or the caller's current
 * session for revoke / revokeDevice) gets its fresh remember-me cookie on its first response at or after the epoch:
 * the same response when that second has already passed, else its next request (a marker in that session). Only when
 * its request carries the firewall's remember-me cookie (`kernel.response` priority {@see self::REISSUE_PRIORITY},
 * before Symfony's remember-me ResponseListener); the marker is kept until the cookie is actually re-issued (1.10.1,
 * CQ N1), and a cookie issued while the epoch is still ahead (a sign-in in the revocation's second) is re-issued the
 * same way (CQ N2).
 *
 * Remembers the kept sessions of the current request only (`kernel.reset`, worker mode).
 */
final class RememberMeEpoch implements ResetInterface
{
    /** Before Symfony's remember-me ResponseListener (0), which writes the cookie set by the handler. */
    public const REISSUE_PRIORITY = 16;

    /** Session attribute of a re-issue deferred to the epoch second: `{u: owner key, e: epoch}`. */
    public const PENDING_ATTRIBUTE = '_nowo_user_kit_remember_me_reissue';

    /** @var array<string, array{hash: string|null, current: bool, excluded: list<string>, epoch: int}> owner key => session kept by this request's revocation */
    private array $kept = [];

    /**
     * @param string $secret `sessions.hash_secret` (registry hash of the current session)
     * @param ContainerInterface|null $handlers firewall name => its (wrapped) remember-me handler
     * @param array<string, string> $cookies firewall name => remember-me cookie name
     */
    public function __construct(
        private readonly SessionEpochStoreInterface $store,
        private readonly SessionUserResolver $owners,
        private readonly ClockInterface $clock,
        private readonly string $secret,
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?FirewallMap $firewalls = null,
        private readonly ?ContainerInterface $handlers = null,
        private readonly array $cookies = [],
    ) {
    }

    /**
     * Moves the epoch of the user to the next whole second.
     *
     * @return int the stored epoch (Unix timestamp)
     */
    public function bump(SessionUser $owner): int
    {
        $epoch = $this->now() + 1;
        $this->store->bump($owner, new DateTimeImmutable('@' . $epoch));

        return $epoch;
    }

    /**
     * The session kept by a revocation of this request gets a fresh cookie: `$hash` (its registry hash), or, with
     * `$current`, the current request's session unless it is one of `$excluded` (the revoked hashes).
     *
     * @param list<string> $excluded
     */
    public function keep(SessionUser $owner, int $epoch, ?string $hash, bool $current = false, array $excluded = []): void
    {
        if ($hash !== null || $current) {
            $this->kept[$owner->key()] = ['hash' => $hash, 'current' => $current, 'excluded' => $excluded, 'epoch' => $epoch];
        }
    }

    /**
     * Whether a remember-me cookie issued at `$issuedAt` (Unix timestamp) predates the epoch of its user.
     */
    public function isStale(SessionUser $owner, int $issuedAt): bool
    {
        $epoch = $this->store->epoch($owner);

        return $epoch !== null && $issuedAt < $epoch;
    }

    /**
     * Whether the user has an epoch at all.
     */
    public function hasEpoch(SessionUser $owner): bool
    {
        return $this->store->epoch($owner) !== null;
    }

    /**
     * Current Unix time of the clock.
     */
    public function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * Deletes the epoch of the user (only once the user itself is gone: old cookies would work again otherwise).
     */
    public function forget(SessionUser $owner): void
    {
        $this->store->forget($owner);
        unset($this->kept[$owner->key()]);
    }

    /**
     * Re-issues the remember-me cookie of the session kept by a revocation (of this request, or deferred to the epoch).
     */
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->getBoolean('_stateless')
            || $request->attributes->get(SessionRegistryListener::SIGNED_OUT_ATTRIBUTE) === true) {
            return;
        }
        $user  = $this->tokens?->getToken()?->getUser();
        $owner = $user instanceof UserInterface ? $this->owners->resolve($user) : null;
        if ($request->attributes->has(ResponseListener::COOKIE_ATTR_NAME)) {
            $this->deferEarlyCookie($request, $owner);

            return;
        }
        if (!$request->hasPreviousSession()) {
            return;
        }
        $session = $request->getSession();
        $epoch   = $owner instanceof SessionUser ? $this->keptEpoch($request, $owner) : null;
        if ($epoch === null) {
            $pending = $session->get(self::PENDING_ATTRIBUTE);
            if (!is_array($pending)) {
                return;
            }
            if (!is_int($pending['e'] ?? null) || !is_string($pending['u'] ?? null)) {
                $session->remove(self::PENDING_ATTRIBUTE);

                return;
            }
            // SEC-004 CQ N1: a request without the user's token (another firewall context, anonymous) keeps the marker;
            // only another user's token drops it.
            if (!$owner instanceof SessionUser) {
                return;
            }
            if ($pending['u'] !== $owner->key()) {
                $session->remove(self::PENDING_ATTRIBUTE);

                return;
            }
            $epoch = $pending['e'];
        }
        $firewall = $this->firewalls?->getFirewallConfig($request)?->getName();
        $cookie   = is_string($firewall) ? ($this->cookies[$firewall] ?? null) : null;
        if ($this->now() < $epoch || !is_string($firewall) || $cookie === null || !$request->cookies->has($cookie)
            || $this->handlers?->has($firewall) !== true) {
            // Too early, or a request that cannot carry the cookie (no remember-me on this firewall, outside its path):
            // keep the marker until a request can (CQ N1).
            // @igor-ignore - The request's own session (not an injected service): the marker belongs to this session.
            $session->set(self::PENDING_ATTRIBUTE, ['u' => $owner->key(), 'e' => $epoch]);

            return;
        }
        $handler = $this->handlers->get($firewall);
        if ($handler instanceof RememberMeHandlerInterface) {
            $handler->createRememberMeCookie($user);
            // Dropped only once the cookie is actually re-issued (CQ N1).
            $session->remove(self::PENDING_ATTRIBUTE);
        }
    }

    /**
     * SEC-004 CQ N2: a remember-me cookie issued on this response while the user's epoch is still ahead (a sign-in in
     * the same second as a revocation) is already stale; the session gets a fresh one on its first request at or after
     * the epoch (same marker as the kept session).
     */
    private function deferEarlyCookie(Request $request, ?SessionUser $owner): void
    {
        $issued = $request->attributes->get(ResponseListener::COOKIE_ATTR_NAME);
        if (!$owner instanceof SessionUser || !$issued instanceof Cookie || $issued->getValue() === null || !$request->hasSession()) {
            return;
        }
        $epoch = $this->store->epoch($owner);
        if ($epoch !== null && $this->now() < $epoch) {
            // @igor-ignore - The request's own session (not an injected service): the marker belongs to this session.
            $request->getSession()->set(self::PENDING_ATTRIBUTE, ['u' => $owner->key(), 'e' => $epoch]);
        }
    }

    /**
     * Drops the kept sessions between requests (worker mode).
     */
    public function reset(): void
    {
        $this->kept = [];
    }

    /**
     * Epoch of a revocation of this request that kept the current session of `$owner`, else null.
     */
    private function keptEpoch(Request $request, SessionUser $owner): ?int
    {
        $kept = $this->kept[$owner->key()] ?? null;
        if ($kept === null || $request->getSession()->get(UserSessionManager::ATTRIBUTE) === null) {
            return null;
        }
        $current = hash_hmac('sha256', $request->getSession()->getId(), $this->secret);
        $match   = ($kept['hash'] !== null && hash_equals($kept['hash'], $current))
            || ($kept['current'] && !in_array($current, $kept['excluded'], true));

        return $match ? $kept['epoch'] : null;
    }
}
