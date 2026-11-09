<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Epoch;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\TokenProviderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

use function count;
use function explode;

/**
 * Wraps the built-in remember-me handler of a firewall (`sessions.remember_me_epoch.enabled`): a cookie issued before
 * the epoch of its user ({@see RememberMeEpoch}) is refused with an authentication failure, so Symfony clears it.
 *
 * Issue time: signature cookies — the signed expiry minus the firewall's `lifetime` (checked after the inner handler
 * verified the signature); an issue time more than {@see self::MAX_SKEW} seconds in the future means the cookie was
 * signed under a longer lifetime, so its issue time is unknown and it is stale whenever the user has an epoch; persistent cookies (`token_provider`) — the server-side `lastUsed` of the token series,
 * read before the inner handler refreshes it (the expiry in such a cookie is not signed).
 */
final readonly class EpochRememberMeHandler implements RememberMeHandlerInterface
{
    /**
     * Seconds a derived issue time may lie in the future (clock skew). Beyond it the issue time is unknown — a cookie
     * signed under a longer `lifetime` than the current one — and the cookie is stale whenever the user has an epoch.
     */
    public const MAX_SKEW = 60;

    /**
     * @param int $lifetime the firewall's `remember_me.lifetime` (seconds)
     * @param TokenProviderInterface|null $tokenProvider the token provider of a persistent handler
     */
    public function __construct(
        private RememberMeHandlerInterface $inner,
        private RememberMeEpoch $epoch,
        private SessionOwnerResolverInterface $owners,
        private int $lifetime,
        private ?TokenProviderInterface $tokenProvider = null,
    ) {
    }

    /**
     * Delegates to the inner handler.
     */
    public function createRememberMeCookie(UserInterface $user): void
    {
        $this->inner->createRememberMeCookie($user);
    }

    /**
     * Inner check, then the epoch check.
     */
    public function consumeRememberMeCookie(RememberMeDetails $rememberMeDetails): UserInterface
    {
        $issuedAt = $this->issuedAt($rememberMeDetails);
        $user     = $this->inner->consumeRememberMeCookie($rememberMeDetails);
        $owner    = $this->owners->resolve($user);
        if ($issuedAt !== null && $owner instanceof SessionUser && ($this->epoch->isStale($owner, $issuedAt)
            || ($issuedAt > $this->epoch->now() + self::MAX_SKEW && $this->epoch->hasEpoch($owner)))) {
            throw new AuthenticationException('The remember-me cookie was issued before the sessions of the account were signed out.');
        }

        return $user;
    }

    /**
     * Delegates to the inner handler.
     */
    public function clearRememberMeCookie(): void
    {
        $this->inner->clearRememberMeCookie();
    }

    private function issuedAt(RememberMeDetails $details): ?int
    {
        if (!$this->tokenProvider instanceof TokenProviderInterface) {
            return $details->getExpires() - $this->lifetime;
        }
        $parts = explode(':', $details->getValue(), 2);

        // A malformed persistent cookie is refused by the inner handler.
        return count($parts) === 2 ? $this->tokenProvider->loadTokenBySeries($parts[0])->getLastUsed()->getTimestamp() : null;
    }
}
