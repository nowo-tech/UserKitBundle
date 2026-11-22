<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Access;

use Nowo\UserKitBundle\OwnerImage\Event\OwnerImageIdAccessCheckEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_filter;
use function array_unique;
use function array_values;
use function in_array;

/**
 * Since 1.23.0: {@see OwnerImageAccessCheckerInterface} on the configured kinds and access service. Stateless.
 *
 * @internal Hosts depend on the interface
 */
final readonly class OwnerImageAccessChecker implements OwnerImageAccessCheckerInterface
{
    public function __construct(
        private OwnerImageKinds $kinds,
        private OwnerImageAccessInterface $access,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function canSee(?string $viewerId, string $kind, string $ownerId): bool
    {
        return $this->visibleOwners($viewerId, $kind, [$ownerId]) === [$ownerId];
    }

    public function visibleOwners(?string $viewerId, string $kind, array $ownerIds): array
    {
        $type = $this->kind($kind);
        if (!$type instanceof OwnerImageKind || ($viewerId !== null && !OwnerImageKinds::isSubjectId($viewerId))) {
            return [];
        }
        $asked = array_values(array_unique(array_filter($ownerIds, OwnerImageKinds::isSubjectId(...))));
        if ($asked === []) {
            return [];
        }
        try {
            $access = $this->idAccess();
            if (!$access instanceof OwnerImageIdAccessInterface) {
                return [];
            }
            $answer  = $access->readableById($viewerId, $type, $asked);
            $granted = array_values(array_filter($asked, static fn (string $id): bool => in_array($id, $answer, true)));

            return $this->narrowed($viewerId, $type, OwnerImageIdAccessCheckEvent::ACTION_VIEW, $granted);
        } catch (Throwable $exception) {
            $this->logger?->warning('NowoUserKitBundle: an owner image access check by id failed; answered as a refusal.', ['action' => 'view', 'exception' => $exception::class]);

            return [];
        }
    }

    public function canChange(string $actorId, string $kind, string $ownerId): bool
    {
        $type = $this->kind($kind);
        if (!$type instanceof OwnerImageKind || !OwnerImageKinds::isSubjectId($actorId) || !OwnerImageKinds::isSubjectId($ownerId)) {
            return false;
        }
        try {
            $access = $this->idAccess();
            if (!$access instanceof OwnerImageIdAccessInterface || !$access->writableTargetById($actorId, $type, $ownerId) instanceof OwnerImageTarget) {
                return false;
            }

            return $this->narrowed($actorId, $type, OwnerImageIdAccessCheckEvent::ACTION_CHANGE, [$ownerId]) === [$ownerId];
        } catch (Throwable $exception) {
            $this->logger?->warning('NowoUserKitBundle: an owner image access check by id failed; answered as a refusal.', ['action' => 'change', 'exception' => $exception::class]);

            return false;
        }
    }

    /**
     * The configured kind of exactly that name, or null.
     */
    private function kind(string $name): ?OwnerImageKind
    {
        $type = $this->kinds->get($name);

        return $type instanceof OwnerImageKind && $type->name === $name ? $type : null;
    }

    /**
     * The access service as its id twin; null (logged) when it does not implement it.
     */
    private function idAccess(): ?OwnerImageIdAccessInterface
    {
        if ($this->access instanceof OwnerImageIdAccessInterface) {
            return $this->access;
        }
        $this->logger?->warning('NowoUserKitBundle: the owner image access service {access} does not implement OwnerImageIdAccessInterface; owner image access by id is refused.', ['access' => $this->access::class]);

        return null;
    }

    /**
     * What is left of `$granted` after the listeners (only ids of `$granted`, compared with `===`).
     *
     * @param list<string> $granted
     *
     * @return list<string>
     */
    private function narrowed(?string $userId, OwnerImageKind $type, string $action, array $granted): array
    {
        if ($granted === [] || !$this->dispatcher instanceof EventDispatcherInterface) {
            return $granted;
        }
        $event = new OwnerImageIdAccessCheckEvent($userId, $type->name, $action, $granted);
        $this->dispatcher->dispatch($event);
        $left = $event->granted();

        return array_values(array_filter($granted, static fn (string $id): bool => in_array($id, $left, true)));
    }
}
