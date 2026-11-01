<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Revocation\RevocationStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function array_column;
use function count;
use function hash_hmac;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Public API of the session registry (the hook point for AuthKit and host code): list a user's sessions, sign one
 * out, sign out the others, sign out everywhere, sign out a device. Revoking marks the registry rows and the
 * {@see RevocationStoreInterface}; each revoked session is signed out on its **next request** by
 * {@see EventListener\SessionRegistryListener}, whatever the session handler. Every revocation dispatches
 * {@see UserSessionsRevokedEvent} after the rows are written (audit).
 *
 * Holds no state between requests (worker mode).
 */
final readonly class UserSessionManager
{
    /**
     * Session attribute: one marker per firewall context, `context => {h: registry hash, u: owner key, s: last touch}`
     * (a session signed in on two firewalls with separate contexts has two markers and two rows).
     */
    public const ATTRIBUTE = '_nowo_user_kit_session';

    /**
     * @param string $secret HMAC key of the session ids (`sessions.hash_secret`, default `%kernel.secret%`)
     * @param int $idleLifetime `sessions.idle_lifetime` (seconds)
     * @param int $touchInterval `sessions.touch_interval` (seconds)
     */
    public function __construct(
        private UserSessionStore $store,
        private RevocationStoreInterface $revocations,
        private SessionUserResolver $owners,
        private ClockInterface $clock,
        private string $secret,
        private int $idleLifetime = 86400,
        private int $touchInterval = 300,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Registry key of a session id: an HMAC, so the id itself is never stored.
     */
    public function hash(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, $this->secret);
    }

    /**
     * Registry hash of the session (null before its first registered request).
     */
    public function currentHash(Request|SessionInterface|null $session): ?string
    {
        $session = $this->session($session);

        return $session instanceof SessionInterface && $this->markers($session) !== [] ? $this->hash($session->getId()) : null;
    }

    /**
     * The well-formed markers of the session, by firewall context.
     *
     * @return array<string, array{h: string, u: string, s: int}>
     */
    public function markers(Request|SessionInterface|null $session): array
    {
        $all     = $this->session($session)?->get(self::ATTRIBUTE);
        $markers = [];
        foreach (is_array($all) ? $all : [] as $context => $marker) {
            if (is_string($context) && is_array($marker) && is_string($marker['h'] ?? null) && is_string($marker['u'] ?? null) && is_int($marker['s'] ?? null)) {
                $markers[$context] = ['h' => $marker['h'], 'u' => $marker['u'], 's' => $marker['s']];
            }
        }

        return $markers;
    }

    private function session(Request|SessionInterface|null $session): ?SessionInterface
    {
        if ($session instanceof Request) {
            return $session->hasSession() ? $session->getSession() : null;
        }

        return $session;
    }

    /**
     * Current time from the clock.
     */
    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    /**
     * Sessions idle since before this are expired.
     */
    public function aliveSince(): DateTimeImmutable
    {
        return $this->now()->modify(sprintf('-%d seconds', $this->idleLifetime));
    }

    /**
     * `sessions.idle_lifetime` in seconds.
     */
    public function idleLifetime(): int
    {
        return $this->idleLifetime;
    }

    /**
     * `sessions.touch_interval` in seconds.
     */
    public function touchInterval(): int
    {
        return $this->touchInterval;
    }

    /**
     * Owner reference of a user (null when the user cannot be registered).
     */
    public function owner(object $user): ?SessionUser
    {
        return $this->owners->resolve($user);
    }

    /**
     * The live sessions of a user, the most recently active first (at most {@see UserSessionStore::MAX_LIST}).
     *
     * @return list<UserSession>
     */
    public function listFor(object $user): array
    {
        $owner = $this->owner($user);

        return $owner instanceof SessionUser ? $this->store->findActive($owner, $this->aliveSince()) : [];
    }

    /**
     * Signs one session of the user out (its owner, or another user as `$actor`: reason `admin`).
     *
     * @return bool false when the user has no live session with that public id
     */
    public function revoke(object $user, string $publicId, ?object $actor = null): bool
    {
        $owner = $this->owner($user);
        if (!$owner instanceof SessionUser) {
            return false;
        }
        $reason = $this->byOther($owner, $actor) ? RevocationReason::Admin : RevocationReason::SignedOut;

        return $this->revokeRows($owner, $user, $actor ?? $user, $reason, publicIds: [$publicId]) !== [];
    }

    /**
     * Signs out every session of the user except `$currentHash` (the caller's own).
     *
     * @return int signed-out sessions
     */
    public function revokeOthers(object $user, ?string $currentHash, ?object $actor = null): int
    {
        $owner = $this->owner($user);

        return $owner instanceof SessionUser ? count($this->revokeRows($owner, $user, $actor ?? $user, RevocationReason::Others, except: $currentHash, audit: true)) : 0;
    }

    /**
     * Signs out every session of the user (an administrator's "sign out everywhere", account disabled, password
     * changed…), `$except` left out.
     *
     * @return int signed-out sessions
     */
    public function revokeAll(object $user, ?object $actor = null, RevocationReason $reason = RevocationReason::Everywhere, ?string $except = null): int
    {
        $owner = $this->owner($user);
        if (!$owner instanceof SessionUser) {
            return 0;
        }
        if ($reason === RevocationReason::Everywhere && $this->byOther($owner, $actor)) {
            $reason = RevocationReason::Admin;
        }

        return count($this->revokeRows($owner, $user, $actor, $reason, except: $except, audit: true));
    }

    /**
     * Signs out the sessions of one device of the user (e.g. when the device loses its trust), `$except` left out.
     *
     * @return int signed-out sessions
     */
    public function revokeDevice(object $user, string $deviceId, ?string $except = null, ?object $actor = null): int
    {
        $owner = $this->owner($user);

        return $owner instanceof SessionUser ? count($this->revokeRows($owner, $user, $actor, RevocationReason::Device, deviceId: $deviceId, except: $except)) : 0;
    }

    /**
     * Revokes the given sessions of an owner (used by the concurrent-session limit).
     *
     * @param list<string> $publicIds
     *
     * @return list<string> revoked public ids
     */
    public function revokeSessions(SessionUser $owner, ?object $user, array $publicIds, RevocationReason $reason): array
    {
        return $this->revokeRows($owner, $user, null, $reason, publicIds: $publicIds);
    }

    /**
     * Whether `$actor` is another user than the owner.
     */
    public function byOther(SessionUser $owner, ?object $actor): bool
    {
        if ($actor === null) {
            return false;
        }
        $actorRef = $this->owner($actor);

        return !$actorRef instanceof SessionUser || $actorRef->key() !== $owner->key();
    }

    /**
     * @param list<string>|null $publicIds
     *
     * @return list<string> revoked public ids
     */
    private function revokeRows(SessionUser $owner, ?object $user, ?object $actor, RevocationReason $reason, ?array $publicIds = null, ?string $deviceId = null, ?string $except = null, bool $audit = false): array
    {
        $rows = $this->store->revoke($owner, $reason, $this->now(), $publicIds, $deviceId, $except);
        if ($rows !== []) {
            $this->revocations->mark(array_column($rows, 'hash'), $reason->value);
        }
        $ids = array_column($rows, 'public_id');
        if ($ids !== [] || $audit) {
            $this->dispatcher?->dispatch(new UserSessionsRevokedEvent($owner, $user, $actor, $reason, $ids));
        }

        return $ids;
    }
}
