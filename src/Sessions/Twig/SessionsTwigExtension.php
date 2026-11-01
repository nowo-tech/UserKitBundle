<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Twig;

use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Security\AccessGuard;
use Nowo\UserKitBundle\Sessions\Ui\SessionsPages;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `user_kit_sessions_admin_path(user)`: URL of the administration page of a user's sessions (for a link on the
 * host's user page), or null when the administration page is off, the user is not a profile user, or the current
 * user may not manage that user's sessions (the link is UX only; the controller checks again).
 */
final class SessionsTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly ProfileRegistry $profiles,
        private readonly UrlGeneratorInterface $urls,
        private readonly AccessGuard $guard,
        private readonly bool $admin = true,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('user_kit_sessions_admin_path', $this->adminPath(...))];
    }

    /**
     * Administration URL of the user's sessions, or null.
     */
    public function adminPath(?object $user): ?string
    {
        if (!$this->admin || $user === null) {
            return null;
        }
        $profile = $this->profiles->resolveForObject($user);
        $owner   = $this->sessions->owner($user);
        if (!$profile instanceof ProfileSettings || !$owner instanceof SessionUser || !$this->guard->canManage($user)) {
            return null;
        }

        return $this->urls->generate(SessionsPages::ROUTE_ADMIN, ['profile' => $profile->name, 'user' => $owner->id]);
    }
}
