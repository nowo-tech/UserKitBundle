<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

/**
 * Optional (1.18): a user (or organization) object that says its owner image subject id and initials itself. Without
 * it, {@see OwnerImageSubjects} uses the Doctrine identifier (else the user identifier) and the first letter of the user
 * identifier. Never required: implement it only when those defaults do not fit (a UUID column that is not the primary
 * key, initials from a display name).
 */
interface OwnerImageSubjectInterface
{
    /**
     * The subject id ({@see OwnerImageKinds::SUBJECT_ID_PATTERN}).
     */
    public function getOwnerImageSubjectId(): string;

    /**
     * One to three letters shown when there is no image.
     */
    public function getOwnerImageInitials(): string;
}
