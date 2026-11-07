<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Client\SessionClientFactory;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\RememberMeEpoch;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsDeletedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionStartedEvent;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\Limit\SessionLimitResolverInterface;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionLimit;
use Nowo\UserKitBundle\Sessions\Model\SessionState;
use Nowo\UserKitBundle\Sessions\Model\SessionStatus;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Revocation\RevocationStoreInterface;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function array_column;
use function array_filter;
use function array_values;
use function count;
use function hash_hmac;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Public API of the session registry (the hook point for AuthKit and host code): list a user's sessions, sign one
 * out, sign out the others, sign out everywhere, sign out a device. Revoking marks the registry rows and the
 * {@see RevocationStoreInterface}; each revoked session is signed out on its **next request** by
 * {@see SessionRegistryListener}, whatever the session handler. Every revocation dispatches
 * {@see UserSessionsRevokedEvent} after the rows are written (audit). Inside a database transaction, the mark in a cache
 * store, and the event of a password change, wait for the outermost commit and are dropped on rollback (HP-L2,
 * {@see CommitQueue}).
 *
 * Host hooks: every revoke method takes an optional host-defined `customReason` (stored instead of the built-in
 * reason, e.g. `link`; see {@see RevocationReason::isValidCustom()}) and a free-form `context` carried by the event.
 * With `sessions.remember_me_epoch.enabled`, revokeAll / revokeOthers also invalidate the remember-me cookies of the
 * user ({@see RememberMeEpoch}).
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
     * Request attribute: what the sign-in of this request deleted, `context => owner key => {h: registry hash, r: why
     * that session was already ended, or null}`. Lives as long as the request, for {@see self::adopt()}.
     */
    public const DISPLACED_ATTRIBUTE = '_nowo_user_kit_session_displaced';

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
        private ?SessionLimitResolverInterface $limits = null,
        private ?RememberMeEpoch $epoch = null,
        private ?FirewallMap $firewalls = null,
        private ?SessionClientFactory $clients = null,
        private ?CommitQueue $commits = null,
    ) {
    }

    /**
     * Limits of the user from the configured resolver (`sessions.limit.*`): the concurrent-session limit the bundle
     * enforces, plus `maxTrustedDevices` and `extra` for the host.
     */
    public function limitFor(object $user): SessionLimit
    {
        return $this->limits?->resolve($user) ?? SessionLimit::unlimited();
    }

    /**
     * Whether revokeAll / revokeOthers invalidate remember-me cookies (`sessions.remember_me_epoch.enabled`).
     */
    public function invalidatesRememberMe(): bool
    {
        return $this->epoch instanceof RememberMeEpoch;
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
     * `$invalidateRememberMe` (with `sessions.remember_me_epoch.enabled`; no-op otherwise) also bumps the user's
     * remember-me epoch, so the revoked device cannot sign back in with its remember-me cookie alone ("sign out this
     * device" that sticks). The cookies of the user's **other** devices are voided too: they keep their sessions, but
     * must sign in again once those expire. The caller's own current session (when the caller is the user) gets a
     * fresh cookie.
     *
     * @param array<string, mixed> $context host-defined data for {@see UserSessionsRevokedEvent}
     *
     * @return bool false when the user has no live session with that public id
     */
    public function revoke(object $user, string $publicId, ?object $actor = null, ?string $customReason = null, array $context = [], bool $invalidateRememberMe = false): bool
    {
        $owner = $this->owner($user);
        if (!$owner instanceof SessionUser) {
            return false;
        }
        $reason = $this->byOther($owner, $actor) ? RevocationReason::Admin : RevocationReason::SignedOut;

        return $this->revokeRows($owner, $user, $actor ?? $user, $reason, publicIds: [$publicId], customReason: $customReason, context: $context, epoch: $invalidateRememberMe, keepCurrent: !$this->byOther($owner, $actor)) !== [];
    }

    /**
     * Signs out every session of the user except `$currentHash` (the caller's own). With the remember-me epoch, the
     * remember-me cookies of the user are invalidated too; the kept session gets a fresh one on this response.
     *
     * @param array<string, mixed> $context host-defined data for {@see UserSessionsRevokedEvent}
     *
     * @return int signed-out sessions
     */
    public function revokeOthers(object $user, ?string $currentHash, ?object $actor = null, ?string $customReason = null, array $context = []): int
    {
        $owner = $this->owner($user);

        return $owner instanceof SessionUser ? count($this->revokeRows($owner, $user, $actor ?? $user, RevocationReason::Others, except: $currentHash, audit: true, customReason: $customReason, context: $context, epoch: true)) : 0;
    }

    /**
     * Signs out every session of the user (an administrator's "sign out everywhere", account disabled, password
     * changed…), `$except` left out. With the remember-me epoch, the remember-me cookies of the user are invalidated
     * too (the `$except` session gets a fresh one on this response).
     *
     * @param array<string, mixed> $context host-defined data for {@see UserSessionsRevokedEvent}
     *
     * @return int signed-out sessions
     */
    public function revokeAll(object $user, ?object $actor = null, RevocationReason $reason = RevocationReason::Everywhere, ?string $except = null, ?string $customReason = null, array $context = []): int
    {
        $owner = $this->owner($user);
        if (!$owner instanceof SessionUser) {
            return 0;
        }
        if ($reason === RevocationReason::Everywhere && $this->byOther($owner, $actor)) {
            $reason = RevocationReason::Admin;
        }

        return count($this->revokeRows($owner, $user, $actor, $reason, except: $except, audit: true, customReason: $customReason, context: $context, epoch: true));
    }

    /**
     * Signs out the sessions of one device of the user (e.g. when the device loses its trust), `$except` left out.
     * `$invalidateRememberMe`: as for {@see self::revoke()} (`$except`, else the caller's current session, keeps a
     * fresh cookie).
     *
     * @param array<string, mixed> $context host-defined data for {@see UserSessionsRevokedEvent}
     *
     * @return int signed-out sessions
     */
    public function revokeDevice(object $user, string $deviceId, ?string $except = null, ?object $actor = null, ?string $customReason = null, array $context = [], bool $invalidateRememberMe = false): int
    {
        $owner = $this->owner($user);

        return $owner instanceof SessionUser ? count($this->revokeRows($owner, $user, $actor, RevocationReason::Device, deviceId: $deviceId, except: $except, customReason: $customReason, context: $context, epoch: $invalidateRememberMe, keepCurrent: !$this->byOther($owner, $actor))) : 0;
    }

    /**
     * Deletes every registry row of the user, after revoking the live ones so their browsers are signed out on their
     * next request; with the remember-me epoch, the epoch is bumped (not deleted: old cookies of a user who still exists
     * must stay refused — {@see self::forgetEpoch()} once the user is gone). For hosts that delete users (the table has no foreign key);
     * `sessions.user_removal.delete_sessions` calls {@see self::forgetOwner()} after a Doctrine removal.
     *
     * @return int deleted rows
     */
    public function forgetUser(object $user): int
    {
        $owner = $this->owner($user);

        return $owner instanceof SessionUser ? $this->forgetOwner($owner, $user) : 0;
    }

    /**
     * {@see self::forgetUser()} by owner reference (the user entity may already be gone).
     *
     * @return int deleted rows
     */
    public function forgetOwner(SessionUser $owner, ?object $user = null): int
    {
        $this->revokeRows($owner, $user, null, RevocationReason::AccountChanged, epoch: true);
        $deleted = $this->store->deleteFor($owner);
        $this->dispatcher?->dispatch(new UserSessionsDeletedEvent($owner, $user, $deleted));

        return $deleted;
    }

    /**
     * Deletes the remember-me epoch of the user — only once the user is gone (old cookies would work again); no-op
     * without the epoch. `sessions.user_removal.delete_sessions` calls it after the removing flush.
     */
    public function forgetEpoch(SessionUser $owner): void
    {
        $this->epoch?->forget($owner);
    }

    /**
     * State of a session of `$user` that is not the request's own (e.g. an account parked by AuthKit's account
     * switcher): live, revoked (with its reason), expired (idle longer than `idle_lifetime` + `touch_interval`, as the
     * early check of its next request would decide) or unknown. Since 1.11.1 the revocation store is consulted too: with the
     * database store a session without a row is `revoked` (`signed_out`); `unknown` remains for an invalid id or user, and
     * for a missing row without a marker with the cache store.
     */
    public function sessionState(string $sessionId, object $user): SessionState
    {
        return $this->sessionStates([[$sessionId, $user]])[0];
    }

    /**
     * {@see self::sessionState()} for several sessions: one query per owner. Keys follow the input.
     *
     * @param list<array{0: string, 1: object}> $sessions `[session id, user]` pairs
     *
     * @return list<SessionState>
     */
    public function sessionStates(array $sessions): array
    {
        $owners = [];
        $hashes = [];
        foreach ($sessions as [$sessionId, $user]) {
            $owner = $this->owner($user);
            if ($owner instanceof SessionUser && $sessionId !== '') {
                $owners[$owner->key()]   = $owner;
                $hashes[$owner->key()][] = $this->hash($sessionId);
            }
        }
        $rows = [];
        foreach ($owners as $key => $owner) {
            $rows[$key] = $this->store->statesOf($owner, $hashes[$key]);
        }
        $now    = $this->now()->getTimestamp();
        $states = [];
        foreach ($sessions as [$sessionId, $user]) {
            $owner = $this->owner($user);
            if (!$owner instanceof SessionUser || $sessionId === '') {
                $states[] = SessionState::unknown();

                continue;
            }
            $hash = $this->hash($sessionId);
            $row  = $rows[$owner->key()][$hash] ?? null;
            // 1.11.1 (audit DE-M1): the revocation store decides too, as the early check of that session's next request
            // would: with the database store a missing row is revoked (fail closed, e.g. a revoked row the retention
            // purge already deleted), with the cache store a marker outlives its row.
            $marked = $row === null || !$row['revoked'] ? $this->revocations->reason($hash, $owner) : null;
            if ($row !== null && $row['revoked']) {
                $states[] = new SessionState(SessionStatus::Revoked, $row['reason'], $row['last_seen_at']);
            } elseif ($marked !== null) {
                $states[] = new SessionState(SessionStatus::Revoked, $marked, $row['last_seen_at'] ?? null);
            } elseif ($row === null) {
                $states[] = SessionState::unknown();
            } elseif ($now - $row['last_seen_at']->getTimestamp() >= $this->idleLifetime + $this->touchInterval) {
                $states[] = new SessionState(SessionStatus::Expired, null, $row['last_seen_at']);
            } else {
                $states[] = new SessionState(SessionStatus::Live, null, $row['last_seen_at']);
            }
        }

        return $states;
    }

    /**
     * Signs out a session of `$user` that is not the request's own, like a logout does for the current one: the
     * owner's row is deleted (no event, as on logout). The caller destroys the session data itself.
     */
    public function endSession(string $sessionId, object $user): void
    {
        $owner = $this->owner($user);
        if ($owner instanceof SessionUser && $sessionId !== '') {
            $this->store->deleteRow($this->hash($sessionId), $owner);
        }
    }

    /**
     * The session's id changed outside a sign-in (e.g. `migrate()` after AuthKit switched to a parked account): the
     * rows of its markers follow the new id at once, instead of on the next registered request.
     */
    public function rekey(SessionInterface $session, string $previousId): void
    {
        $from = $this->hash($previousId);
        $to   = $this->hash($session->getId());
        if ($from === $to) {
            return;
        }
        $markers = $this->markers($session);
        $changed = false;
        foreach ($markers as $context => $marker) {
            $owner = SessionUser::fromKey($marker['u']);
            if ($marker['h'] !== $from || !$owner instanceof SessionUser) {
                continue;
            }
            $this->store->rekey($from, $to, $owner);
            $markers[$context]['h'] = $to;
            $changed                = true;
        }
        if ($changed) {
            // @igor-ignore - The caller's session (not an injected service): the markers belong to this session.
            $session->set(self::ATTRIBUTE, $markers);
        }
    }

    /**
     * Registers the request's session at once for `$user`, in the request's firewall context: for a sign-in that failed
     * and handed the session back to the account it had before (GY-L7, HP-I2), so a revocation or idle expiry reaches it
     * before its next stateful request. Not a sign-in: no limit, no eviction and no `atLogin: true` event.
     *
     * The session that sign-in replaced decides:
     *
     * - revoked before the sign-in deleted its row, or still revoked after a rollback brought the row back: the session
     *   is registered **as revoked** with that reason, and its next request signs it out;
     * - row back after a rollback: the row follows the new session id (same public id and creation time);
     * - row deleted by the sign-in, still live at that moment: a new row;
     * - a marker of `$user` whose row is gone, and no sign-in of this request deleted it: treated as revoked, as the
     *   early check would.
     *
     * A row deleted by the sign-in cannot be revoked meanwhile, so a revocation landing **after** that delete and before
     * this call misses the session when no transaction holds the row (see docs/SECURITY.md).
     *
     * No-op without a session, on a stateless route or firewall, for a user the registry cannot own, when the session
     * already has its marker, and while a sign-in of this context is pending (it registers when the token is final).
     * Call it with the request the sign-in ran on, once the earlier token and session attributes are back and any
     * transaction of that sign-in has ended.
     */
    public function adopt(Request $request, object $user): void
    {
        $owner = $this->owner($user);
        if (!$owner instanceof SessionUser || !$request->hasSession() || !FirewallContext::stateful($this->firewalls, $request)) {
            return;
        }
        $session = $request->getSession();
        $context = FirewallContext::of($this->firewalls, $request);
        $marker  = $this->markers($session)[$context] ?? null;
        $hash    = $this->hash($session->getId());
        $pending = $session->get(SessionRegistryListener::PENDING_ATTRIBUTE);
        if (($marker !== null && $marker['u'] === $owner->key() && $marker['h'] === $hash) || (is_array($pending) && isset($pending[$context]))) {
            return;
        }
        $displaced = $this->displaced($request, $context, $owner);
        $earlier   = $marker !== null && $marker['u'] === $owner->key() ? $marker['h'] : $displaced['h'] ?? null;
        $reason    = $displaced['r'] ?? null;
        if ($marker !== null && $marker['u'] !== $owner->key()) {
            $previous = SessionUser::fromKey($marker['u']);
            if ($previous instanceof SessionUser) {
                $this->store->deleteRow($marker['h'], $previous);
            }
        }
        $state = $earlier !== null ? $this->store->state($earlier, $owner) : null;
        if ($state !== null) {
            $this->store->rekey($earlier, $hash, $owner);
            // Read again after the move: a revocation waiting on this row lands before the rekey gets the lock, and its
            // mark in a cache store is under the old hash, maybe not even written yet (HU-L3). Not through the revocation
            // store: with the database store the old hash has no row now, which would read as revoked.
            $reason ??= ($this->store->state($hash, $owner) ?? $state)['reason'];
        } elseif ($earlier !== null && $displaced === null) {
            $reason = RevocationReason::SignedOut->value;
        }
        $now      = $this->now();
        $client   = $this->clients?->fromRequest($request) ?? new SessionClient();
        $inserted = $state === null && $this->store->insert($owner, $hash, $client, $now);
        if ($reason !== null) {
            $this->store->markRevoked($hash, $owner, $reason, $now);
            $this->revocations->mark([$hash], $reason);
        } elseif ($state !== null) {
            $this->store->touch($hash, $owner, $now, $client->deviceId);
        } elseif ($inserted) {
            $this->dispatcher?->dispatch(new UserSessionStartedEvent($user, $owner, $client, false));
        }
        $markers           = $this->markers($session);
        $markers[$context] = ['h' => $hash, 'u' => $owner->key(), 's' => $now->getTimestamp()];
        // @igor-ignore - The request's own session (not an injected service): the markers belong to this session.
        $session->set(self::ATTRIBUTE, $markers);
    }

    /**
     * What the sign-in of this request deleted for this owner and context.
     *
     * @return array{h: string, r: string|null}|null
     */
    private function displaced(Request $request, string $context, SessionUser $owner): ?array
    {
        /** @var array<string, array<string, array{h: string, r: string|null}>> $displaced */
        $displaced = $request->attributes->get(self::DISPLACED_ATTRIBUTE, []);

        return $displaced[$context][$owner->key()] ?? null;
    }

    /**
     * For {@see EventListener\PasswordChangeListener}, not an after-commit API: on the registry's connection `$effect`
     * runs **now**, also in an open transaction, because the registry's writes follow that transaction and only what they
     * cannot undo needs to wait ({@see self::revokeRows()}). On another connection with a transaction open it is held
     * until that commit, because the registry's writes could not follow its rollback.
     *
     * @internal
     *
     * @param Closure(): void $effect
     */
    public function whenCommitted(Connection $connection, Closure $effect): void
    {
        if (!$this->commits instanceof CommitQueue || $connection === $this->store->connection() || !$this->commits->hold($connection, $effect)) {
            $effect();
        }
    }

    /**
     * Applies now what committed transactions left waiting: marks in the cache store and `password_changed` events. The
     * bundle does it after a flush, and at the end of the request, command or Messenger message; call this from a job or
     * standalone script with no such boundary, or to have them right after your commit. While any transaction is open
     * nothing runs: a listener's writes must not depend on that later transaction.
     */
    public function releaseHeldRevocations(): void
    {
        $this->commits?->release();
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
     * @param array<string, mixed> $context
     * @param bool $epoch bump the remember-me epoch (also when no row matched: a browser whose row is gone must not sign
     *                    back in); bumped **before** the rows, so a failing epoch store revokes nothing
     * @param bool $keepCurrent the caller's current session (unless revoked here) gets a fresh cookie, besides `$except`
     *
     * @return list<string> revoked public ids
     */
    private function revokeRows(SessionUser $owner, ?object $user, ?object $actor, RevocationReason $reason, ?array $publicIds = null, ?string $deviceId = null, ?string $except = null, bool $audit = false, ?string $customReason = null, array $context = [], bool $epoch = false, bool $keepCurrent = false): array
    {
        if ($customReason !== null && !RevocationReason::isValidCustom($customReason)) {
            throw new InvalidArgumentException(sprintf('A custom revocation reason must match %s and not be a built-in reason, got "%s".', RevocationReason::CUSTOM_PATTERN, $customReason));
        }
        $bumped = $epoch && $this->epoch instanceof RememberMeEpoch ? $this->epoch->bump($owner) : null;
        $code   = $customReason ?? $reason->value;
        $rows   = $this->store->revoke($owner, $code, $this->now(), $publicIds, $deviceId, $except);
        $ids    = array_column($rows, 'public_id');
        $hashes = array_column($rows, 'hash');
        // Only a password change raises its event after the fact, from a flush the caller may not know is nested. Any
        // other reason comes from a call the host made inside its own transaction: the listeners stay in it, and their
        // writes follow it (an erasure's vault and device cleanup must, while the user still has its id).
        $passwordChange = $reason === RevocationReason::PasswordChanged;
        // @igor-ignore - Held until the transaction ends, and the queue is reset between requests.
        $held = $this->commits instanceof CommitQueue && $this->commits->hold($this->store->connection(), function () use ($owner, $user, $actor, $reason, $audit, $customReason, $context, $bumped, $code, $except, $keepCurrent, $rows, $hashes, $passwordChange): void {
            // A driver sees no savepoint: a revocation undone by one rolled back shows as a live row by now.
            $live = $hashes !== [] ? $this->store->liveAmong($hashes) : [];
            $kept = array_values(array_filter($rows, static fn (array $row): bool => !in_array($row['hash'], $live, true)));
            if ($rows !== [] && $kept === []) {
                return;
            }
            $this->markRevoked($owner, array_column($kept, 'hash'), $code, $bumped, $except, $keepCurrent);
            if ($passwordChange) {
                $this->tellRevoked($owner, $user, $actor, $reason, array_column($kept, 'public_id'), $audit, $customReason, $context, $bumped !== null);
            }
        });
        if (!$held) {
            $this->markRevoked($owner, $hashes, $code, $bumped, $except, $keepCurrent);
        }
        if (!$held || !$passwordChange) {
            $this->tellRevoked($owner, $user, $actor, $reason, $ids, $audit, $customReason, $context, $bumped !== null);
        }

        return $ids;
    }

    /**
     * What a rollback cannot undo besides the event: the mark in a cache store and the session kept by this revocation.
     *
     * @param list<string> $hashes
     */
    private function markRevoked(SessionUser $owner, array $hashes, string $code, ?int $bumped, ?string $except, bool $keepCurrent): void
    {
        if ($hashes !== []) {
            $this->revocations->mark($hashes, $code);
        }
        if ($bumped !== null) {
            $this->epoch?->keep($owner, $bumped, $except, $keepCurrent && $except === null, $hashes);
        }
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $context
     */
    private function tellRevoked(SessionUser $owner, ?object $user, ?object $actor, RevocationReason $reason, array $ids, bool $audit, ?string $customReason, array $context, bool $invalidated): void
    {
        if ($ids !== [] || $audit) {
            $this->dispatcher?->dispatch(new UserSessionsRevokedEvent($owner, $user, $actor, $reason, $ids, $customReason, $context, $invalidated));
        }
    }
}
