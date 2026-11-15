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
 * Subject id and initials of a user object (1.18): {@see OwnerImageSubjectInterface} when implemented, else — for an
 * instance of the one configured user class (`owner_images.user_class`) only — the Doctrine single identifier (else the
 * user identifier) through {@see SessionUserResolver}; null for any other object and when that is not a usable subject
 * id ({@see OwnerImageKinds::SUBJECT_ID_PATTERN}).
 *
 * The bare identifier says nothing about the class, so it is only used for one class (audit QJ-M1): two user classes
 * with the same id (`Admin#42` and `Customer#42`) never share one image, access, export or erasure. Hosts with several
 * user classes implement {@see OwnerImageSubjectInterface} with ids unique across them (e.g. `admin:42`).
 *
 * Stateless: nothing is kept between calls.
 */
final readonly class OwnerImageSubjects
{
    /**
     * @param string|null $userClass `owner_images.user_class`: the class (or interface) whose instances get their
     *                               identifier as subject id; null = only {@see OwnerImageSubjectInterface} users
     */
    public function __construct(
        private ?ManagerRegistry $doctrine = null,
        private ?string $userClass = null,
    ) {
    }

    /**
     * The subject id of a user, or null (another class than the configured one, or an unusable id).
     */
    public function idOf(object $user): ?string
    {
        $id = match (true) {
            $user instanceof OwnerImageSubjectInterface                    => $user->getOwnerImageSubjectId(),
            $this->userClass !== null && $user instanceof $this->userClass => (new SessionUserResolver($this->doctrine))->resolve($user)?->id,
            default                                                        => null,
        };

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
