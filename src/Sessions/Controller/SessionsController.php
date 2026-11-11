<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\UnknownProfileException;
use Nowo\UserKitBundle\Sessions\Security\AccessGuard;
use Nowo\UserKitBundle\Sessions\Ui\SessionsPages;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_object;

/**
 * Session pages. Routes come only from the `nowo_user_kit_sessions` route loader (REQ-SF-004 rule 7): the "My
 * sessions" routes under `web_ui.path_prefix`, the administration routes (`*_admin*`) under
 * `web_ui.admin_path_prefix`.
 *
 * - `GET  ''`, `POST /{id}/revoke`, `POST /revoke-others`: the signed-in user's sessions (access:
 *   {@see AccessGuard::canAccessOwn()}).
 * - `GET  /{profile}/{user}`, `POST /{profile}/{user}/{id}/revoke`, `POST /{profile}/{user}/revoke-all`: one user's
 *   sessions for an administrator (access: {@see AccessGuard::canManage()} on that user; the user is loaded through
 *   the UserKit profile's user class).
 *
 * Every POST checks access **and** a FormKit CSRF-only token (fail closed: invalid or missing → 403), then revokes
 * through {@see UserSessionManager} (which dispatches the audit event) and redirects back with a flash. Responses are
 * `private, no-store`.
 */
final class SessionsController extends AbstractController
{
    /** Requirement of the session public ids. */
    public const ID_REQUIREMENT = '[0-9a-f]{32}';

    /** Requirement of the profile names. */
    public const PROFILE_REQUIREMENT = '[A-Za-z0-9_.-]{1,64}';

    /** Requirement of the user ids (one path segment). */
    public const USER_REQUIREMENT = '[^/]{1,191}';

    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly AccessGuard $guard,
        private readonly SessionsPages $pages,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly ProfileRegistry $profiles,
        private readonly ?ManagerRegistry $doctrine = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * "My sessions".
     */
    #[Route(path: '', name: SessionsPages::ROUTE_INDEX, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->ownUser();

        return $this->noStore($this->render($this->pages->template('index'), [
            'page' => $this->pages->ownPage($this->sessions->listFor($user), $this->sessions->sessionHash($this->sessions->sessionRef($request))),
        ]));
    }

    /**
     * Signs one of the user's own sessions out.
     */
    #[Route(path: '/{id}/revoke', name: SessionsPages::ROUTE_REVOKE, requirements: ['id' => self::ID_REQUIREMENT], methods: ['POST'])]
    public function revoke(Request $request, string $id): RedirectResponse
    {
        $user = $this->ownUser();
        $this->requireCsrf($request, SessionsPages::revokeTokenId($id));
        if (!$this->sessions->revoke($user, $id)) {
            throw new NotFoundHttpException('Unknown session.');
        }
        $this->flash('sessions.flash.revoked');

        return $this->back(SessionsPages::ROUTE_INDEX);
    }

    /**
     * Signs out every session of the user but the current one.
     */
    #[Route(path: '/revoke-others', name: SessionsPages::ROUTE_REVOKE_OTHERS, methods: ['POST'])]
    public function revokeOthers(Request $request): RedirectResponse
    {
        $user = $this->ownUser();
        $this->requireCsrf($request, SessionsPages::CSRF_REVOKE_OTHERS);
        $count = $this->sessions->revokeOthers($user, $this->sessions->sessionHash($this->sessions->sessionRef($request)));
        $this->flash($count === 0 ? 'sessions.flash.no_others' : 'sessions.flash.revoked_others', ['%count%' => $count]);

        return $this->back(SessionsPages::ROUTE_INDEX);
    }

    /**
     * Administration: one user's sessions.
     */
    #[Route(path: '/{profile}/{user}', name: SessionsPages::ROUTE_ADMIN, requirements: ['profile' => self::PROFILE_REQUIREMENT, 'user' => self::USER_REQUIREMENT], methods: ['GET'])]
    public function admin(string $profile, string $user): Response
    {
        $subject = $this->managedUser($profile, $user);

        return $this->noStore($this->render($this->pages->template('admin'), [
            'page'    => $this->pages->adminPage($this->sessions->listFor($subject), $profile, $user, self::displayName($subject)),
            'subject' => $subject,
        ]));
    }

    /**
     * Administration: signs one session of the user out.
     */
    #[Route(path: '/{profile}/{user}/{id}/revoke', name: SessionsPages::ROUTE_ADMIN_REVOKE, requirements: ['profile' => self::PROFILE_REQUIREMENT, 'user' => self::USER_REQUIREMENT, 'id' => self::ID_REQUIREMENT], methods: ['POST'])]
    public function adminRevoke(Request $request, string $profile, string $user, string $id): RedirectResponse
    {
        $subject = $this->managedUser($profile, $user);
        $this->requireCsrf($request, SessionsPages::adminRevokeTokenId($profile, $user, $id));
        if (!$this->sessions->revoke($subject, $id, $this->guard->user())) {
            throw new NotFoundHttpException('Unknown session.');
        }
        $this->flash('sessions.flash.revoked');

        return $this->back(SessionsPages::ROUTE_ADMIN, ['profile' => $profile, 'user' => $user]);
    }

    /**
     * Administration: signs out every session of the user.
     */
    #[Route(path: '/{profile}/{user}/revoke-all', name: SessionsPages::ROUTE_ADMIN_REVOKE_ALL, requirements: ['profile' => self::PROFILE_REQUIREMENT, 'user' => self::USER_REQUIREMENT], methods: ['POST'])]
    public function adminRevokeAll(Request $request, string $profile, string $user): RedirectResponse
    {
        $subject = $this->managedUser($profile, $user);
        $this->requireCsrf($request, SessionsPages::adminRevokeAllTokenId($profile, $user));
        $count = $this->sessions->revokeAll($subject, $this->guard->user());
        $this->flash('sessions.flash.revoked_all', ['%count%' => $count]);

        return $this->back(SessionsPages::ROUTE_ADMIN, ['profile' => $profile, 'user' => $user]);
    }

    /**
     * Display name of a user on the administration page.
     */
    public static function displayName(object $user): string
    {
        return $user instanceof UserInterface ? $user->getUserIdentifier() : $user::class;
    }

    private function ownUser(): object
    {
        $user = $this->guard->user();
        if ($user === null || !$this->guard->canAccessOwn()) {
            $this->guard->deny('Access denied to the session list.');
        }

        return $user;
    }

    private function managedUser(string $profile, string $id): object
    {
        // Anonymous first: nothing about the users is revealed before a sign-in.
        if ($this->guard->user() === null) {
            $this->guard->deny('Access denied to the sessions of this user.');
        }
        try {
            $class = $this->profiles->getByName($profile)->userClass;
        } catch (UnknownProfileException) {
            throw new NotFoundHttpException('Unknown user.');
        }
        $subject = $this->doctrine?->getManagerForClass($class)?->find($class, $id);
        // Strict id: "2abc" is not user 2 (no lax cast of the route value).
        if (!is_object($subject) || $this->sessions->owner($subject)?->id !== $id) {
            throw new NotFoundHttpException('Unknown user.');
        }
        // A user the checker does not let the actor manage is "unknown" too (no probing of other tenants' ids).
        if (!$this->guard->canManage($subject)) {
            throw new NotFoundHttpException('Unknown user.');
        }

        return $subject;
    }

    /**
     * Fail closed: the CSRF-only form must be submitted with a valid token.
     */
    private function requireCsrf(Request $request, string $tokenId): void
    {
        $form = $this->csrfForms->createNamed($request->getPathInfo(), $tokenId);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    private function flash(string $key, array $params = []): void
    {
        $this->addFlash('success', $this->translator?->trans($key, $params, NowoUserKitBundle::TRANSLATION_DOMAIN) ?? $key);
    }

    /**
     * @param array<string, string> $params
     */
    private function back(string $route, array $params = []): RedirectResponse
    {
        $response = $this->redirectToRoute($route, $params);
        $this->noStore($response);

        return $response;
    }

    private function noStore(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
