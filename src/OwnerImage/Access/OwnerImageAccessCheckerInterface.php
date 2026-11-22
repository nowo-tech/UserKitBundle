<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Access;

use Nowo\UserKitBundle\OwnerImage\Event\OwnerImageIdAccessCheckEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;

/**
 * Since 1.23.0 (DDD-host checklist B8): whether a user may see, or change, the owner image of a subject, asked by ids.
 * A host without the security user at hand (a private entity manager, an API, a message handler) checks owner-image
 * access from the user's subject id alone.
 *
 * Ids are subject ids ({@see OwnerImageSubjects::idOf()} of the user; the owner's id for the subject), matched byte for
 * byte ({@see OwnerImageKinds::SUBJECT_ID_PATTERN}: nothing trimmed or folded). The rules are the configured access
 * service's ({@see OwnerImageIdAccessInterface}); then the listeners of {@see OwnerImageIdAccessCheckEvent} may refuse,
 * never grant. Every answer is filtered against the ids asked with `===`.
 *
 * An unknown kind, a malformed or look-alike id and a refusal give the same answer (false / left out). A failure of the
 * access service or a listener is logged (its class only) and answered as a refusal. Nothing is kept between calls
 * (FrankenPHP worker mode, kernel reset off); the answer holds as of the check only (REQ-RUNTIME-003): the upload and
 * removal ask the access service again.
 */
interface OwnerImageAccessCheckerInterface
{
    /**
     * Whether the user `$viewerId` (null = anonymous) may see the `$kind` image of `$ownerId`.
     */
    public function canSee(?string $viewerId, string $kind, string $ownerId): bool;

    /**
     * The owner ids among `$ownerIds` whose `$kind` image the user `$viewerId` (null = anonymous) may see, in the order
     * asked, without duplicates.
     *
     * @param list<string> $ownerIds
     *
     * @return list<string>
     */
    public function visibleOwners(?string $viewerId, string $kind, array $ownerIds): array;

    /**
     * Whether the user `$actorId` may change (upload or remove) the `$kind` image of `$ownerId`.
     */
    public function canChange(string $actorId, string $kind, string $ownerId): bool;
}
