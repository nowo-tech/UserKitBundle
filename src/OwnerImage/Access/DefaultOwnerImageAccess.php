<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Access;

use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;

/**
 * Default access (1.18): a `user` kind may be changed by that user only (their subject id equals the subject, byte for
 * byte), into the application bucket, counted against their own id; an `other` kind by nobody (configure your own
 * {@see OwnerImageAccessInterface}). Reading follows the kind's `read`: `public` = everyone, `authenticated` = any
 * signed-in viewer, `host` = nobody (your own access service decides). Stateless.
 */
final readonly class DefaultOwnerImageAccess implements OwnerImageAccessInterface
{
    public function __construct(
        private OwnerImageSubjects $subjects,
    ) {
    }

    /**
     * The actor's own image only, for `user` kinds.
     */
    public function writableTarget(object $actor, OwnerImageKind $kind, string $subjectId): ?OwnerImageTarget
    {
        return $kind->isUserKind() && $this->subjects->idOf($actor) === $subjectId ? new OwnerImageTarget(null, $subjectId) : null;
    }

    /**
     * By the kind's `read` setting.
     */
    public function readable(?object $viewer, OwnerImageKind $kind, array $subjectIds): array
    {
        return match ($kind->read) {
            OwnerImageKind::READ_PUBLIC        => $subjectIds,
            OwnerImageKind::READ_AUTHENTICATED => $viewer === null ? [] : $subjectIds,
            default                            => [],
        };
    }
}
