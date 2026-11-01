<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Support;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * CSRF token manager with one fixed value per token id (kernel-less page fixtures: the UiKit contract compares the
 * rendered hidden field of every `csrf_only` form with its FormView; Symfony's manager masks each token differently).
 */
final class FixedCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function getToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, 'fixture-token');
    }

    public function refreshToken(string $tokenId): CsrfToken
    {
        return $this->getToken($tokenId);
    }

    public function removeToken(string $tokenId): ?string
    {
        return null;
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        return $token->getValue() === 'fixture-token';
    }
}
