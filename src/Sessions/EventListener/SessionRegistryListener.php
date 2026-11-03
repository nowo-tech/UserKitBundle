<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\EventListener;

use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\Security\PendingAuthentication;
use Nowo\UserKitBundle\Security\PendingAuthenticationTokenInterface;
use Nowo\UserKitBundle\Sessions\Client\SessionClientFactory;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Event\SessionLimitReachedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionStartedEvent;
use Nowo\UserKitBundle\Sessions\Limit\SessionLimitResolverInterface;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionLimitPolicy;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Revocation\RevocationStoreInterface;
use Nowo\UserKitBundle\Sessions\Security\SessionLimitReachedException;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function ltrim;
use function str_contains;

use const PHP_INT_MIN;

/**
 * Keeps the session registry in step with the signed-in (stateful) sessions. Works with any session handler: the
 * registry is the source of truth, the session only carries one marker per firewall context
 * `{h: hash, u: owner, s: last touch}` (and the registry one row per session and owner).
 *
 * - `kernel.request` priority 9 (**before the firewall**, so a revoked session is never refreshed — `_stateless`
 *   routes included, since the firewall's ContextListener reads the session there too): a session with any marker that
 *   is revoked ({@see RevocationStoreInterface}), whose row is gone (database store) or that stayed idle longer than
 *   `idle_lifetime` is signed out as a whole — session invalidated, token dropped, remember-me cookies of every
 *   firewall removed from the request (no auto-login) and cleared on the response with their configured name, path,
 *   domain, secure and SameSite, flash `sessions.signed_out.<reason>`. The request then goes on anonymous, so the
 *   firewall answers as for any guest (login redirect, 401…) — or, with `sessions.early_check.json_response`, an
 *   XHR / JSON request gets `401 {"error":"session_signed_out"}` right away.
 * - `kernel.request` priority 6 (after the firewall, stateful routes only): a signed-in session without a marker for
 *   this firewall context is registered; then `last_seen_at` is written at most once per `touch_interval` (the time
 *   of the last write lives in the marker, so requests in between cost no write); a touch that finds the row revoked
 *   (a lost cache marker) or gone signs the session out. `_stateless` routes never write the session, so activity
 *   there does not refresh `last_seen_at`.
 * - `CheckPassportEvent` priority -128 (after the credentials check): `reject_new` refuses a sign-in that would
 *   exceed the concurrent-session limit ({@see SessionLimitReachedException}).
 * - `LoginSuccessEvent` priority -256 (after the session id migration and the remember-me cookie): the previous row
 *   of this firewall context goes, `evict_oldest` revokes the least recently active sessions over the limit, the new
 *   session is registered. A pending sign-in (second factor still to come,
 *   {@see PendingAuthenticationTokenInterface}) is only flagged: it is registered as
 *   a sign-in (limit included) on the first request after its token became final, and nothing is touched meanwhile.
 * - `LogoutEvent` priority 64 (before the session is invalidated): the row of this firewall context goes.
 *
 * Stateless firewalls are ignored. Holds no state between requests (worker mode).
 */
final readonly class SessionRegistryListener
{
    /** Request attribute set when this request signed its session out (the response clears the cookies). */
    public const SIGNED_OUT_ATTRIBUTE = '_nowo_user_kit_session_signed_out';

    /**
     * Session attribute: firewall contexts whose sign-in is pending
     * ({@see PendingAuthenticationTokenInterface}), to be
     * registered as a sign-in once the token is final.
     */
    public const PENDING_ATTRIBUTE = '_nowo_user_kit_session_pending';

    /** Marker key of requests outside any firewall context. */
    public const DEFAULT_CONTEXT = 'main';

    /**
     * `$rememberMeCookies`: the remember-me cookies of every firewall (read from the security configuration by
     * SessionsPass) plus `sessions.remember_me_cookies`; `path: null` means "only strip it from the request" (its
     * custom handler clears it). `$rememberMeHandlers`: the custom handlers (`remember_me.service`), which clear their
     * own cookie with their own path and domain.
     *
     * @param list<array{name: string, path: string|null, domain: string|null, secure: bool|string|null, httponly: bool, samesite: string|null}> $rememberMeCookies
     * @param bool $flash `sessions.flash`: add the signed-out message to the new session
     * @param iterable<RememberMeHandlerInterface> $rememberMeHandlers
     * @param bool $jsonResponse `sessions.early_check.json_response`: XHR / JSON requests of a signed-out session get
     *                           a 401 JSON response from the early check instead of going on as a guest
     */
    public function __construct(
        private UserSessionManager $sessions,
        private UserSessionStore $store,
        private RevocationStoreInterface $revocations,
        private SessionClientFactory $clients,
        private SessionLimitResolverInterface $limits,
        private TokenStorageInterface $tokens,
        private ?FirewallMap $firewalls = null,
        private ?RequestStack $requestStack = null,
        private ?TranslatorInterface $translator = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private array $rememberMeCookies = [],
        private bool $flash = true,
        private iterable $rememberMeHandlers = [],
        private bool $jsonResponse = false,
    ) {
    }

    /**
     * Before the firewall: signs out a revoked, missing or expired session (`_stateless` routes included).
     */
    public function onRequestEarly(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        $session = $request->getSession();
        // Like the firewall's ContextListener, reading (and ending) the session of a `_stateless` route must not count
        // as a use: the usage index is parked below zero meanwhile, then restored.
        $tracked = $request->attributes->getBoolean('_stateless') && $session instanceof Session;
        if ($tracked) {
            $index = &$session->getUsageIndex();
            $saved = $index;
            $index = PHP_INT_MIN;
        }
        $markers = $this->sessions->markers($session);
        $reason  = $this->revocationOf($markers);
        if ($reason !== null) {
            foreach ($markers as $marker) {
                $this->store->deleteByHash($marker['h']);
            }
            $this->signOut($request, $session, $reason);
        }
        if ($tracked) {
            $index = $saved;
        }
        if ($reason !== null && $this->jsonResponse && $this->wantsJson($request)) {
            $event->setResponse($this->signedOutJson());
        }
    }

    /**
     * After the firewall: registers or touches the session of the signed-in user (stateful routes).
     */
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession() || !$this->stateful($request)) {
            return;
        }
        $session = $request->getSession();
        if (PendingAuthentication::isPending($this->tokens->getToken())) {
            // The sign-in is not complete (second factor): nothing is registered or touched yet.
            return;
        }
        $user  = $this->currentUser();
        $owner = $user !== null ? $this->sessions->owner($user) : null;
        if ($user === null || !$owner instanceof SessionUser) {
            return;
        }
        $context = $this->context($request);
        if ($this->takePending($session, $context)) {
            // The pending sign-in of this context became final: register it as a sign-in (limit included). Parallel
            // pending sign-ins registered nothing at the first factor, so `reject_new` is checked again here (DC-M1).
            $rejection = $this->rejection($user, $owner, null);
            if ($rejection !== null) {
                $this->signOut($request, $session, RevocationReason::SignedOut->value, $rejection);
                $event->setResponse($this->afterSignOut($request));

                return;
            }
            $this->signIn($request, $session, $context, $user, $owner);

            return;
        }
        $markers = $this->sessions->markers($session);
        $marker  = $markers[$context] ?? null;
        if ($marker === null || $marker['u'] !== $owner->key()) {
            $previous = $marker !== null ? SessionUser::fromKey($marker['u']) : null;
            if ($marker !== null && $previous instanceof SessionUser) {
                $this->store->deleteRow($marker['h'], $previous);
            }
            $this->register($request, $session, $context, $user, $owner, false);

            return;
        }
        $now  = $this->sessions->now();
        $hash = $this->sessions->hash($session->getId());
        if ($hash !== $marker['h']) {
            $this->store->rekey($marker['h'], $hash, $owner);
            $this->setMarker($session, $context, ['h' => $hash, 'u' => $marker['u'], 's' => $marker['s']]);
        }
        if ($now->getTimestamp() - $marker['s'] < $this->sessions->touchInterval()) {
            return;
        }
        if ($this->store->touch($hash, $owner, $now, $this->clients->deviceId($request))) {
            $this->setMarker($session, $context, ['h' => $hash, 'u' => $marker['u'], 's' => $now->getTimestamp()]);

            return;
        }
        // Revoked (its cache marker was lost) or gone (erased, purged, deleted by the host): the session ends.
        $state = $this->store->state($hash, $owner);
        $this->store->deleteByHash($hash);
        $this->signOut($request, $session, $state['reason'] ?? RevocationReason::SignedOut->value);
        $event->setResponse($this->afterSignOut($request));
    }

    /**
     * `reject_new`: refuses a sign-in over the concurrent-session limit (after the credentials were checked).
     */
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requestStack?->getMainRequest();
        if (!$request instanceof Request || !$this->stateful($request)) {
            return;
        }
        $user      = $event->getPassport()->getUser();
        $owner     = $this->sessions->owner($user);
        $rejection = $owner instanceof SessionUser ? $this->rejection($user, $owner, $this->sessions->currentHash($request)) : null;
        if ($rejection !== null) {
            throw new SessionLimitReachedException($rejection);
        }
    }

    /**
     * `reject_new`: the message refusing one more session (the {@see SessionLimitReachedEvent} is dispatched), or
     * null when the user is under the limit. `$except`: the session being signed in again (not counted).
     */
    private function rejection(object $user, SessionUser $owner, ?string $except): ?string
    {
        $limit = $this->limits->resolve($user);
        if ($limit->policy !== SessionLimitPolicy::RejectNew || $limit->maxSessions === null) {
            return null;
        }
        $active = $this->store->countActive($owner, $this->sessions->aliveSince(), $except);
        if ($active < $limit->maxSessions) {
            return null;
        }
        $reached = new SessionLimitReachedEvent($user, $owner, $limit, $active);
        $this->dispatcher?->dispatch($reached);

        return $reached->getMessage() ?? $this->trans('sessions.limit.rejected', ['%count%' => $active]);
    }

    /**
     * Registers the new session; `evict_oldest` signs the oldest ones out first.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession() || !$this->stateful($request)) {
            return;
        }
        $session = $request->getSession();
        $context = $this->context($request);
        $this->forget($session, $context);
        if (PendingAuthentication::isPending($event->getAuthenticatedToken())) {
            // Registered (limit included) on the first request after the token became final.
            $pending           = $this->pendingContexts($session);
            $pending[$context] = true;
            // @igor-ignore - The request's own session (not an injected service): the flag belongs to this session.
            $session->set(self::PENDING_ATTRIBUTE, $pending);

            return;
        }
        $this->takePending($session, $context);
        $user  = $event->getUser();
        $owner = $this->sessions->owner($user);
        if (!$owner instanceof SessionUser) {
            return;
        }
        $this->signIn($request, $session, $context, $user, $owner);
    }

    /**
     * A sign-in: `evict_oldest` signs the oldest sessions out first, then the session is registered.
     */
    private function signIn(Request $request, SessionInterface $session, string $context, object $user, SessionUser $owner): void
    {
        $limit = $this->limits->resolve($user);
        if ($limit->policy === SessionLimitPolicy::EvictOldest && $limit->maxSessions !== null) {
            // This session is never counted nor evicted (a second registration of it must not evict another, DC-I1).
            $current = $this->sessions->hash($session->getId());
            $since   = $this->sessions->aliveSince();
            $active  = $this->store->countActive($owner, $since, $current);
            $over    = $active - $limit->maxSessions + 1;
            if ($over > 0) {
                $evicted = $this->sessions->revokeSessions($owner, $user, $this->store->oldestActive($owner, $since, $current, $over), RevocationReason::Evicted);
                $this->dispatcher?->dispatch(new SessionLimitReachedEvent($user, $owner, $limit, $active, $evicted));
            }
        }
        // forget() read the session, so it is started and its id is the migrated one.
        $this->register($request, $session, $context, $user, $owner, true);
    }

    /**
     * @return array<string, true>
     */
    private function pendingContexts(SessionInterface $session): array
    {
        $pending = $session->get(self::PENDING_ATTRIBUTE);
        $flags   = [];
        foreach (is_array($pending) ? $pending : [] as $context => $flag) {
            if (is_string($context) && $flag === true) {
                $flags[$context] = true;
            }
        }

        return $flags;
    }

    /**
     * Removes the pending flag of a context; true when it was set.
     */
    private function takePending(SessionInterface $session, string $context): bool
    {
        $pending = $this->pendingContexts($session);
        if (!isset($pending[$context])) {
            return false;
        }
        unset($pending[$context]);
        // @igor-ignore - The request's own session (not an injected service): the flag belongs to this session.
        $pending === [] ? $session->remove(self::PENDING_ATTRIBUTE) : $session->set(self::PENDING_ATTRIBUTE, $pending);

        return true;
    }

    /**
     * Signing out deletes the row of this firewall context (before the session is invalidated).
     */
    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $this->forget($request->getSession(), $this->context($request));
        }
    }

    /**
     * Clears the remember-me cookies of a session signed out by this request.
     */
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->get(self::SIGNED_OUT_ATTRIBUTE) !== true) {
            return;
        }
        foreach ($this->rememberMeCookies as $cookie) {
            if ($cookie['path'] === null) {
                // Cleared by its custom handler (clearRememberMeCookie()), which knows its path and domain.
                continue;
            }
            $secure = $cookie['secure'] === null || $cookie['secure'] === 'auto' ? $request->isSecure() : (bool) $cookie['secure'];
            $event->getResponse()->headers->clearCookie($cookie['name'], $cookie['path'], $cookie['domain'], $secure, $cookie['httponly'], $cookie['samesite']);
        }
    }

    /**
     * Reason to end the session: the first marker that is idle, revoked or (database store) without a row.
     *
     * @param array<string, array{h: string, u: string, s: int}> $markers
     */
    private function revocationOf(array $markers): ?string
    {
        $now = $this->sessions->now()->getTimestamp();
        foreach ($markers as $marker) {
            if ($now - $marker['s'] >= $this->sessions->idleLifetime() + $this->sessions->touchInterval()) {
                return RevocationReason::Expired->value;
            }
            $owner  = SessionUser::fromKey($marker['u']);
            $reason = $owner instanceof SessionUser ? $this->revocations->reason($marker['h'], $owner) : RevocationReason::SignedOut->value;
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    private function register(Request $request, SessionInterface $session, string $context, object $user, SessionUser $owner, bool $atLogin): void
    {
        $now      = $this->sessions->now();
        $hash     = $this->sessions->hash($session->getId());
        $client   = $this->clients->fromRequest($request);
        $inserted = $this->store->insert($owner, $hash, $client, $now);
        $this->setMarker($session, $context, ['h' => $hash, 'u' => $owner->key(), 's' => $now->getTimestamp()]);
        if ($inserted) {
            $this->dispatcher?->dispatch(new UserSessionStartedEvent($user, $owner, $client, $atLogin));
        }
    }

    private function forget(SessionInterface $session, string $context): void
    {
        $markers = $this->sessions->markers($session);
        $marker  = $markers[$context] ?? null;
        if ($marker === null) {
            return;
        }
        $owner = SessionUser::fromKey($marker['u']);
        if ($owner instanceof SessionUser) {
            $this->store->deleteRow($marker['h'], $owner);
        }
        unset($markers[$context]);
        // @igor-ignore - The request's own session (not an injected service): the markers belong to this session.
        $session->set(UserSessionManager::ATTRIBUTE, $markers);
    }

    /**
     * @param array{h: string, u: string, s: int} $marker
     */
    private function setMarker(SessionInterface $session, string $context, array $marker): void
    {
        $markers           = $this->sessions->markers($session);
        $markers[$context] = $marker;
        // @igor-ignore - The request's own session (not an injected service): the markers belong to this session.
        $session->set(UserSessionManager::ATTRIBUTE, $markers);
    }

    /**
     * Ends the session: token dropped, session invalidated, remember-me cookies out of the request (and cleared on the
     * response), flash.
     */
    private function signOut(Request $request, SessionInterface $session, string $reason, ?string $message = null): void
    {
        $this->tokens->setToken(null);
        $session->invalidate();
        foreach ($this->rememberMeCookies as $cookie) {
            $request->cookies->remove($cookie['name']);
        }
        foreach ($this->rememberMeHandlers as $handler) {
            $handler->clearRememberMeCookie();
        }
        $request->attributes->set(self::SIGNED_OUT_ATTRIBUTE, true);
        if ($this->flash && $session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $message ?? $this->signedOutMessage($reason));
        }
    }

    /**
     * Flash of a stored reason: the built-in message, or `sessions.signed_out.<custom>` for a host-defined reason when
     * the host translated it (else the generic `signed_out` message).
     */
    private function signedOutMessage(string $reason): string
    {
        $case = RevocationReason::tryFrom($reason);
        if ($case instanceof RevocationReason) {
            return $this->trans($case->messageKey());
        }
        if (RevocationReason::isValidCustom($reason)) {
            $key     = 'sessions.signed_out.' . $reason;
            $message = $this->trans($key);
            if ($message !== $key) {
                return $message;
            }
        }

        return $this->trans(RevocationReason::SignedOut->messageKey());
    }

    private function signedOutJson(): JsonResponse
    {
        $response = new JsonResponse(['error' => 'session_signed_out'], Response::HTTP_UNAUTHORIZED);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * After the firewall the request was already authenticated: answer with a redirect to the same path (GET / HEAD)
     * or a 401, so nothing runs as the signed-out user. The target is the base URL + `/` + the path without leading
     * slashes / backslashes + the query: never the Host header, never a protocol-relative `//host` URL.
     */
    private function afterSignOut(Request $request): Response
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && !$this->wantsJson($request)) {
            $query = $request->getQueryString();

            return new RedirectResponse($request->getBaseUrl() . '/' . ltrim($request->getPathInfo(), '/\\') . ($query !== null ? '?' . $query : ''));
        }

        return $this->signedOutJson();
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json' || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    private function stateful(Request $request): bool
    {
        if ($request->attributes->getBoolean('_stateless')) {
            return false;
        }
        $config = $this->firewalls?->getFirewallConfig($request);

        return !$config instanceof FirewallConfig || !$config->isStateless();
    }

    /**
     * Firewall context of the request (one marker and one row per context).
     */
    private function context(Request $request): string
    {
        $config = $this->firewalls?->getFirewallConfig($request);

        return $config?->getContext() ?? $config?->getName() ?? self::DEFAULT_CONTEXT;
    }

    /**
     * Security user of the request (the original user while impersonating).
     */
    private function currentUser(): ?object
    {
        $token = $this->tokens->getToken();
        if ($token instanceof SwitchUserToken) {
            $token = $token->getOriginalToken();
        }
        $user = $token?->getUser();

        return is_object($user) ? $user : null;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters, NowoUserKitBundle::TRANSLATION_DOMAIN) ?? $key;
    }
}
