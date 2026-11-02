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
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;
use Symfony\Contracts\Service\ResetInterface;

use function hash_equals;
use function hash_hmac;
use function is_string;

/**
 * `sessions.remember_me_epoch`: revokeAll / revokeOthers move the user's epoch to "now", and every remember-me cookie
 * issued before it is refused by {@see EpochRememberMeHandler} (wrapped around the built-in handler of each firewall).
 * So a browser whose registry row is gone (purged, garbage-collected) cannot sign back in with an old cookie.
 *
 * The session that was kept (revokeOthers, or revokeAll with `except`) gets a fresh remember-me cookie on the same
 * response (`kernel.response` priority {@see self::REISSUE_PRIORITY}, before Symfony's remember-me ResponseListener),
 * when its request carries the firewall's remember-me cookie; it stays signed in on its next visit.
 *
 * Remembers the kept sessions of the current request only (`kernel.reset`, worker mode).
 */
final class RememberMeEpoch implements ResetInterface
{
    /** Before Symfony's remember-me ResponseListener (0), which writes the cookie set by the handler. */
    public const REISSUE_PRIORITY = 16;

    /** @var array<string, string> owner key => registry hash of the session kept by this request's revocation */
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
     * Moves the epoch of the user to now; `$keep` is the registry hash of the session that stays signed in.
     */
    public function bump(SessionUser $owner, ?string $keep = null): void
    {
        $this->store->bump($owner, DateTimeImmutable::createFromInterface($this->clock->now()));
        if ($keep !== null) {
            $this->kept[$owner->key()] = $keep;
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
     * Deletes the epoch of the user (account deleted or erased).
     */
    public function forget(SessionUser $owner): void
    {
        $this->store->forget($owner);
        unset($this->kept[$owner->key()]);
    }

    /**
     * Re-issues the remember-me cookie of the session kept by a revocation of this request.
     */
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($this->kept === [] || !$event->isMainRequest() || !$request->hasSession()
            || $request->attributes->get(SessionRegistryListener::SIGNED_OUT_ATTRIBUTE) === true
            || $request->attributes->has(ResponseListener::COOKIE_ATTR_NAME)) {
            return;
        }
        $firewall = $this->firewalls?->getFirewallConfig($request)?->getName();
        $cookie   = is_string($firewall) ? ($this->cookies[$firewall] ?? null) : null;
        $user     = $this->tokens?->getToken()?->getUser();
        if (!is_string($firewall) || $cookie === null || !$request->cookies->has($cookie) || !$user instanceof UserInterface || $this->handlers?->has($firewall) !== true) {
            return;
        }
        $owner = $this->owners->resolve($user);
        $kept  = $owner instanceof SessionUser ? ($this->kept[$owner->key()] ?? null) : null;
        if ($kept === null || $request->getSession()->get(UserSessionManager::ATTRIBUTE) === null
            || !hash_equals($kept, hash_hmac('sha256', $request->getSession()->getId(), $this->secret))) {
            return;
        }
        $handler = $this->handlers->get($firewall);
        if ($handler instanceof RememberMeHandlerInterface) {
            $handler->createRememberMeCookie($user);
        }
    }

    /**
     * Drops the kept sessions between requests (worker mode).
     */
    public function reset(): void
    {
        $this->kept = [];
    }
}
