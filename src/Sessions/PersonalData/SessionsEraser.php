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

    /** Key of the revoke context holding {@see ErasureRequest::identifier()} (1.12). */
    public const CONTEXT_ORIGINAL_IDENTIFIER = 'original_identifier';

    /**
     * Revokes (so the browsers are signed out) then deletes the rows. The revocation event carries the identifier the
     * user had when the erasure started (`context['original_identifier']`, 1.12), so listeners keyed by it (AuthKit's
     * account switcher) still find the person after an earlier eraser anonymised the e-mail. The remember-me epoch row (owner type, id and a
     * timestamp) is kept while the user exists, so old remember-me cookies stay refused; it goes with the user.
     */
    public function erase(ErasureRequest $request): void
    {
        $owner = $this->sessions->owner($request->user);
        if (!$owner instanceof SessionUser) {
            return;
        }
        $identifier = $request->identifier();
        $this->sessions->revokeAll($request->user, $request->actor, RevocationReason::AccountChanged, context: $identifier === null ? [] : [self::CONTEXT_ORIGINAL_IDENTIFIER => $identifier]);
        $this->store->deleteFor($owner);
    }
}
