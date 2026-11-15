<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures;

use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;

use function in_array;

/**
 * A host's access to owner images, by id only (DDD host): users change their own avatar; organization managers change
 * the organization's logos, into the organization's scope; every signed-in user sees avatars, logos are public.
 */
final class Organizations implements OwnerImageAccessInterface
{
    /** @var array<string, list<string>> organization id => manager ids */
    public array $managers = ['org-1' => ['alice'], 'org-2' => ['bob'], 'org-bad' => ['alice']];

    public function __construct(
        private readonly OwnerImageSubjects $subjects,
    ) {
    }

    public function writableTarget(object $actor, OwnerImageKind $kind, string $subjectId): ?OwnerImageTarget
    {
        $actorId = $this->subjects->idOf($actor);
        if ($kind->isUserKind()) {
            return $actorId === $subjectId ? new OwnerImageTarget(null, $subjectId) : null;
        }

        if (!in_array($actorId, $this->managers[$subjectId] ?? [], true)) {
            return null;
        }

        // org-bad: a scope key StorageKit refuses (a host bug): nothing is stored, the storage is "not ready".
        return new OwnerImageTarget($subjectId === 'org-bad' ? 'Not A Scope!' : null, $subjectId);
    }

    public function readable(?object $viewer, OwnerImageKind $kind, array $subjectIds): array
    {
        return $kind->isUserKind() && $viewer === null ? [] : $subjectIds;
    }
}
