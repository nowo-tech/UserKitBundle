<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Twig;

use Nowo\UserKitBundle\Presence\UserPresenceResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UserPresenceExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserPresenceResolver $presenceResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_is_online', $this->isOnline(...)),
        ];
    }

    public function isOnline(object $user, ?string $profile = null): bool
    {
        return $this->presenceResolver->isOnline($user, $profile);
    }
}
