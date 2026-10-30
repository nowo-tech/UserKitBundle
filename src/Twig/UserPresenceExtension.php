<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Twig;

use Nowo\UserKitBundle\Presence\UserPresenceResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig function `user_is_online(user, profile = null)` backed by {@see UserPresenceResolver}.
 */
final class UserPresenceExtension extends AbstractExtension
{
    /**
     * Receives the presence resolver.
     */
    public function __construct(
        private readonly UserPresenceResolver $presenceResolver,
    ) {
    }

    /**
     * Registers the `user_is_online()` Twig function.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_is_online', $this->isOnline(...)),
        ];
    }

    /**
     * Whether the user was active within the online threshold of its profile.
     */
    public function isOnline(object $user, ?string $profile = null): bool
    {
        return $this->presenceResolver->isOnline($user, $profile);
    }
}
