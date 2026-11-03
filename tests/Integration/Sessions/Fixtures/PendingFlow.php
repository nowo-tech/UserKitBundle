<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * Test second factor: a sign-in posted with `_pending=interface|attribute` gets a pending token; `/complete` makes it
 * final.
 */
final readonly class PendingFlow
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private RequestStack $requests,
    ) {
    }

    public function onTokenCreated(AuthenticationTokenCreatedEvent $event): void
    {
        $mode = $this->requests->getMainRequest()?->request->get('_pending');
        if ($mode === 'interface') {
            $event->setAuthenticatedToken(new PendingToken($event->getAuthenticatedToken()));
        } elseif ($mode === 'attribute') {
            $event->setAuthenticatedToken(new AttributePendingToken($event->getAuthenticatedToken()));
        }
    }

    public function complete(): Response
    {
        $token = $this->tokens->getToken();
        if ($token instanceof WrappingToken) {
            $this->tokens->setToken($token->inner());
        }

        return new Response('completed');
    }
}
