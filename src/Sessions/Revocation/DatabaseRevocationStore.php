<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Revocation;

use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Reads the revocation from the registry row itself (the row is marked by {@see UserSessionStore::revoke()}): one
 * query per signed-in request, no extra infrastructure. A registered session whose row is gone (erased, purged,
 * deleted by the host) is ended too: the registry is the source of truth.
 */
final readonly class DatabaseRevocationStore implements RevocationStoreInterface
{
    public function __construct(
        private UserSessionStore $store,
    ) {
    }

    /**
     * Nothing to do: the rows are already marked.
     */
    public function mark(array $hashes, string $reason): void
    {
    }

    /**
     * Revocation reason stored on the row (`signed_out` when the row is gone).
     */
    public function reason(string $hash, SessionUser $owner): ?string
    {
        $state = $this->store->state($hash, $owner);
        if ($state === null) {
            return RevocationReason::SignedOut->value;
        }

        return $state['revoked'] ? $state['reason'] : null;
    }
}
