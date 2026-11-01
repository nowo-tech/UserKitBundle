<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_object;

/**
 * Applies the {@see SessionsAccessCheckerInterface} to the current user. The token is trusted only when a firewall
 * matched the main request.
 */
final readonly class AccessGuard
{
    public function __construct(
        private SessionsAccessCheckerInterface $checker,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Whether the current user may open "My sessions".
     */
    public function canAccessOwn(): bool
    {
        return $this->checker->canAccessOwn($this->user());
    }

    /**
     * Whether the current user may manage the sessions of `$subject`.
     */
    public function canManage(object $subject): bool
    {
        return $this->checker->canManage($this->user(), $subject);
    }

    /**
     * Current security user behind a firewall, or null.
     *
     * @phpstan-impure
     */
    public function user(): ?object
    {
        $mainRequest = $this->requestStack?->getMainRequest();
        if (!$mainRequest instanceof Request || !$mainRequest->attributes->has('_firewall_context')) {
            return null;
        }
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }

    /**
     * 403 (or the firewall entry point for anonymous users behind a firewall).
     */
    public function deny(string $message): never
    {
        $mainRequest = $this->requestStack?->getMainRequest();
        if ($mainRequest instanceof Request && $mainRequest->attributes->has('_firewall_context')) {
            throw new AccessDeniedException($message);
        }

        throw new AccessDeniedHttpException($message);
    }
}
