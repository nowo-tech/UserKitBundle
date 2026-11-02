<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\PersonalData;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;

/**
 * Personal data eraser `sessions` (GDPR art. 17): signs every session of the user out and deletes the registry rows.
 * Runs inside the eraser transaction (DBAL statements on the same connection, no flush).
 */
final readonly class SessionsEraser implements PersonalDataEraserInterface
{
    public function __construct(
        private UserSessionManager $sessions,
        private UserSessionStore $store,
    ) {
    }

    /**
     * Eraser key.
     */
    public function getKey(): string
    {
        return 'sessions';
    }

    /**
     * Users the registry can reference.
     */
    public function supports(object $user): bool
    {
        return $this->sessions->owner($user) instanceof SessionUser;
    }

    /**
     * Revokes (so the browsers are signed out) then deletes the rows and the remember-me epoch.
     */
    public function erase(ErasureRequest $request): void
    {
        $owner = $this->sessions->owner($request->user);
        if (!$owner instanceof SessionUser) {
            return;
        }
        $this->sessions->revokeAll($request->user, $request->actor, RevocationReason::AccountChanged);
        $this->store->deleteFor($owner);
        $this->sessions->forgetEpoch($owner);
    }
}
