<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\EventListener;

use Nowo\UserKitBundle\NowoUserKitBundle;
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
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function is_object;
use function str_contains;

/**
 * Keeps the session registry in step with the signed-in (stateful) sessions. Works with any session handler: the
 * registry is the source of truth, the session only carries a marker {h: hash, u: owner, s: last touch}.
 *
 * - `kernel.request` priority 9 (**before the firewall**, so a revoked session is never refreshed): a session whose
 *   marker is revoked ({@see RevocationStoreInterface}) or idle longer than `idle_lifetime` is signed out — session
 *   invalidated, token dropped, remember-me cookies removed from the request (no auto-login) and cleared on the
 *   response, flash `sessions.signed_out.<reason>`. The request then goes on anonymous, so the firewall answers as for
 *   any guest (login redirect, 401…).
 * - `kernel.request` priority 6 (after the firewall): a signed-in session without marker is registered; then
 *   `last_seen_at` is written at most once per `touch_interval` (the time of the last write lives in the marker, so
 *   requests in between cost no write); a touch that finds the row revoked (a lost cache marker) or gone
 *   (erased, purged, deleted by the host) signs the session out: the registry is the source of truth.
 * - `CheckPassportEvent` priority -128 (after the credentials check): `reject_new` refuses a sign-in that would
 *   exceed the concurrent-session limit ({@see SessionLimitReachedException}).
 * - `LoginSuccessEvent` priority -256 (after the session id migration and the remember-me cookie): the previous row
 *   of the session goes, `evict_oldest` revokes the least recently active sessions over the limit, the new session is
 *   registered.
 * - `LogoutEvent` priority 64 (before the session is invalidated): the row goes.
 *
 * Stateless firewalls and `_stateless` routes are ignored. Holds no state between requests (worker mode).
 */
final readonly class SessionRegistryListener
{
    /** Request attribute set when this request signed its session out (the response clears the cookies). */
    public const SIGNED_OUT_ATTRIBUTE = '_nowo_user_kit_session_signed_out';

    /**
     * @param list<string> $rememberMeCookies `sessions.remember_me_cookies`
     * @param bool $flash `sessions.flash`: add the signed-out message to the new session
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
        private array $rememberMeCookies = ['REMEMBERME'],
        private bool $flash = true,
    ) {
    }

    /**
     * Before the firewall: signs out a revoked or expired session.
     */
    public function onRequestEarly(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $session = $this->previousSession($event);
        $marker  = $this->sessions->marker($session);
        if (!$session instanceof SessionInterface || $marker === null) {
            return;
        }
        if ($this->sessions->now()->getTimestamp() - $marker['s'] > $this->sessions->idleLifetime() + $this->sessions->touchInterval()) {
            $this->store->deleteByHash($marker['h']);
            $this->signOut($request, $session, RevocationReason::Expired->value);

            return;
        }
        $reason = $this->revocations->reason($marker['h']);
        if ($reason !== null) {
            $this->revocations->forget($marker['h']);
            $this->store->deleteByHash($marker['h']);
            $this->signOut($request, $session, $reason);
        }
    }

    /**
     * After the firewall: registers or touches the session of the signed-in user.
     */
    public function onRequest(RequestEvent $event): void
    {
        $session = $this->previousSession($event);
        if (!$session instanceof SessionInterface || !$this->stateful($event->getRequest())) {
            return;
        }
        $user  = $this->currentUser();
        $owner = $user !== null ? $this->sessions->owner($user) : null;
        if ($user === null || !$owner instanceof SessionUser) {
            return;
        }
        $request = $event->getRequest();
        $marker  = $this->sessions->marker($session);
        if ($marker === null || $marker['u'] !== $owner->key()) {
            if ($marker !== null) {
                $this->store->deleteByHash($marker['h']);
            }
            $this->register($request, $session, $user, $owner, false);

            return;
        }
        $now  = $this->sessions->now();
        $hash = $this->sessions->hash($session->getId());
        if ($hash !== $marker['h']) {
            $this->store->rekey($marker['h'], $hash);
            // @igor-ignore - The request's own session (not an injected service): the marker belongs to this session.
            $session->set(UserSessionManager::ATTRIBUTE, ['h' => $hash, 'u' => $marker['u'], 's' => $marker['s']]);
        }
        if ($now->getTimestamp() - $marker['s'] < $this->sessions->touchInterval()) {
            return;
        }
        if ($this->store->touch($hash, $now, $this->clients->deviceId($request))) {
            // @igor-ignore - The request's own session (not an injected service): the marker belongs to this session.
            $session->set(UserSessionManager::ATTRIBUTE, ['h' => $hash, 'u' => $marker['u'], 's' => $now->getTimestamp()]);

            return;
        }
        // Revoked (its cache marker was lost) or gone (erased, purged, deleted by the host): the session ends.
        $state = $this->store->state($hash);
        $this->revocations->forget($hash);
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
        $user  = $event->getPassport()->getUser();
        $limit = $this->limits->resolve($user);
        $owner = $this->sessions->owner($user);
        if ($limit->policy !== SessionLimitPolicy::RejectNew || $limit->maxSessions === null || !$owner instanceof SessionUser) {
            return;
        }
        $active = $this->store->countActive($owner, $this->sessions->aliveSince(), $this->sessions->currentHash($request));
        if ($active < $limit->maxSessions) {
            return;
        }
        $reached = new SessionLimitReachedEvent($user, $owner, $limit, $active);
        $this->dispatcher?->dispatch($reached);

        throw new SessionLimitReachedException($reached->getMessage() ?? $this->trans('sessions.limit.rejected', ['%count%' => $active]));
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
        $this->forget($session);
        $user  = $event->getUser();
        $owner = $this->sessions->owner($user);
        if (!$owner instanceof SessionUser) {
            return;
        }
        $limit = $this->limits->resolve($user);
        if ($limit->policy === SessionLimitPolicy::EvictOldest && $limit->maxSessions !== null) {
            $since  = $this->sessions->aliveSince();
            $active = $this->store->countActive($owner, $since);
            $over   = $active - $limit->maxSessions + 1;
            if ($over > 0) {
                $evicted = $this->sessions->revokeSessions($owner, $user, $this->store->oldestActive($owner, $since, null, $over), RevocationReason::Evicted);
                $this->dispatcher?->dispatch(new SessionLimitReachedEvent($user, $owner, $limit, $active, $evicted));
            }
        }
        // forget() read the session, so it is started and its id is the migrated one.
        $this->register($request, $session, $user, $owner, true);
    }

    /**
     * Signing out deletes the row (before the session is invalidated).
     */
    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $this->forget($request->getSession());
        }
    }

    /**
     * Clears the remember-me cookies of a session signed out by this request.
     */
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->attributes->get(self::SIGNED_OUT_ATTRIBUTE) !== true) {
            return;
        }
        foreach ($this->rememberMeCookies as $cookie) {
            $event->getResponse()->headers->clearCookie($cookie, '/');
        }
    }

    private function register(Request $request, SessionInterface $session, object $user, SessionUser $owner, bool $atLogin): void
    {
        $now    = $this->sessions->now();
        $hash   = $this->sessions->hash($session->getId());
        $client = $this->clients->fromRequest($request);
        $this->revocations->forget($hash);
        $inserted = $this->store->insert($owner, $hash, $client, $now);
        $session->set(UserSessionManager::ATTRIBUTE, ['h' => $hash, 'u' => $owner->key(), 's' => $now->getTimestamp()]);
        if ($inserted) {
            $this->dispatcher?->dispatch(new UserSessionStartedEvent($user, $owner, $client, $atLogin));
        }
    }

    private function forget(SessionInterface $session): void
    {
        $hash = $this->sessions->currentHash($session);
        if ($hash !== null) {
            $this->store->deleteByHash($hash);
            $this->revocations->forget($hash);
            $session->remove(UserSessionManager::ATTRIBUTE);
        }
    }

    /**
     * Ends the session: token dropped, session invalidated, remember-me cookies out of the request (and cleared on the
     * response), flash.
     */
    private function signOut(Request $request, SessionInterface $session, string $reason): void
    {
        $this->tokens->setToken(null);
        $session->invalidate();
        foreach ($this->rememberMeCookies as $cookie) {
            $request->cookies->remove($cookie);
        }
        $request->attributes->set(self::SIGNED_OUT_ATTRIBUTE, true);
        if ($this->flash && $session instanceof FlashBagAwareSessionInterface) {
            $case = RevocationReason::tryFrom($reason) ?? RevocationReason::SignedOut;
            $session->getFlashBag()->add('warning', $this->trans($case->messageKey()));
        }
    }

    /**
     * After the firewall the request was already authenticated: answer with a redirect to the same page (GET / HEAD)
     * or a 401, so nothing runs as the signed-out user.
     */
    private function afterSignOut(Request $request): Response
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && !$this->wantsJson($request)) {
            return new RedirectResponse($request->getUri());
        }

        return new JsonResponse(['error' => 'session_signed_out'], Response::HTTP_UNAUTHORIZED);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json' || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    private function previousSession(RequestEvent $event): ?SessionInterface
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->getBoolean('_stateless') || !$request->hasPreviousSession()) {
            return null;
        }

        return $request->getSession();
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
