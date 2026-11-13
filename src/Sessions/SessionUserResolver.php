<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface;
use Stringable;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;
use function is_int;
use function is_string;
use function reset;

/**
 * Owner reference of a session: Doctrine-mapped users by their root entity class and single identifier (stable when
 * the e-mail / username changes); other security users by their class and user identifier. Users without a usable
 * reference (composite or empty id, not persisted yet, too long, or with leading / trailing whitespace or control /
 * format characters, see {@see SessionUser::isUsable()}) are not registered.
 *
 * Default {@see SessionOwnerResolverInterface} (`sessions.owner.resolver` unset).
 */
final readonly class SessionUserResolver implements SessionOwnerResolverInterface
{
    public function __construct(
        private ?ManagerRegistry $doctrine = null,
    ) {
    }

    /**
     * Reference of the user, or null when it cannot be registered.
     */
    public function resolve(object $user): ?SessionUser
    {
        $manager = $this->doctrine?->getManagerForClass($user::class);
        if ($manager instanceof EntityManagerInterface && !$manager->getMetadataFactory()->isTransient($user::class)) {
            $metadata = $manager->getClassMetadata($user::class);
            $ids      = $metadata->getIdentifierValues($user);
            if (count($ids) !== 1) {
                return null;
            }
            $id = reset($ids);

            return $this->reference($metadata->rootEntityName, is_int($id) || is_string($id) || $id instanceof Stringable ? (string) $id : '');
        }

        return $user instanceof UserInterface ? $this->reference($user::class, $user->getUserIdentifier()) : null;
    }

    private function reference(string $type, string $id): ?SessionUser
    {
        if (!SessionUser::isUsable($type) || !SessionUser::isUsable($id)) {
            return null;
        }

        return new SessionUser($type, $id);
    }
}
