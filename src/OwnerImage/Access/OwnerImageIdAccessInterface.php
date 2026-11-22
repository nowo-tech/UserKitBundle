<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Access;

use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;

/**
 * Since 1.23.0 (DDD-host checklist B8): the ids-only twin of {@see OwnerImageAccessInterface}, which
 * {@see OwnerImageAccessCheckerInterface} asks. Implement it on the service you set as `owner_images.access` (next to the
 * object methods, with the same rules) so your host can check owner-image access by user id alone. The default access
 * implements it. A configured access service without it makes every id check answer no (fail closed).
 *
 * The actor and viewer ids are subject ids (the user's {@see OwnerImageSubjects::idOf()},
 * {@see OwnerImageKinds::SUBJECT_ID_PATTERN}), already checked when you are asked.
 * Compare them byte for byte; the bundle compares your answer with `===`. Keep no request, user or tenant state
 * (FrankenPHP worker mode, kernel reset off).
 */
interface OwnerImageIdAccessInterface
{
    /**
     * Where the image of `$subjectId` goes when the user `$actorId` may change it; null = may not.
     */
    public function writableTargetById(string $actorId, OwnerImageKind $kind, string $subjectId): ?OwnerImageTarget;

    /**
     * The subject ids whose image the user `$viewerId` may see (null = anonymous); one query at most.
     *
     * @param list<string> $subjectIds
     *
     * @return list<string>
     */
    public function readableById(?string $viewerId, OwnerImageKind $kind, array $subjectIds): array;
}
