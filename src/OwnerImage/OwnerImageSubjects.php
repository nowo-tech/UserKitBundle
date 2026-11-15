<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function mb_strtoupper;
use function mb_substr;
use function trim;

/**
 * Subject id and initials of a user object (1.18): {@see OwnerImageSubjectInterface} when implemented, else the
 * Doctrine single identifier (else the user identifier) through {@see SessionUserResolver}; null when that is not a
 * usable subject id ({@see OwnerImageKinds::SUBJECT_ID_PATTERN}). Stateless: nothing is kept between calls.
 */
final readonly class OwnerImageSubjects
{
    public function __construct(
        private ?ManagerRegistry $doctrine = null,
    ) {
    }

    /**
     * The subject id of a user, or null.
     */
    public function idOf(object $user): ?string
    {
        $id = $user instanceof OwnerImageSubjectInterface
            ? $user->getOwnerImageSubjectId()
            : (new SessionUserResolver($this->doctrine))->resolve($user)?->id;

        return OwnerImageKinds::isSubjectId($id) ? $id : null;
    }

    /**
     * The initials shown without an image: {@see OwnerImageSubjectInterface::getOwnerImageInitials()}, else the first
     * letter of the user identifier, else `?`.
     */
    public function initialsOf(?object $user): string
    {
        $initials = match (true) {
            $user instanceof OwnerImageSubjectInterface => $user->getOwnerImageInitials(),
            $user instanceof UserInterface              => mb_strtoupper(mb_substr(trim($user->getUserIdentifier()), 0, 1)),
            default                                     => '',
        };

        return trim($initials) === '' ? '?' : $initials;
    }
}
