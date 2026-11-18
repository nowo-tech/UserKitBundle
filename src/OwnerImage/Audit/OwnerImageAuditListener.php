<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\Pointer\ColumnPointer;

use function count;
use function sprintf;

/**
 * 1.20.0 (`owner_images.audit`, needs nowo-tech/audit-kit-bundle with `nowo_audit_kit.action_log`): one AuditKit action
 * log row per {@see OwnerImageChangedEvent} — `uploaded` (no previous image), `replaced` or `removed` — recorded by ids
 * through {@see ActionLogIdRecorderInterface::recordIdsAndFlush()}, never with entities: nothing the listener passes is
 * an entity of the application's unit of work, so a write unit that clears its entity manager between attempts
 * (transaction-doctrine 1.4) never meets a detached reference, and a replayed attempt records its own row.
 *
 * Actor: the actor's subject id. Subject: the subject id of a `user` kind; an `other` kind (an organization's logo) is
 * no user, so its subject id is only in the context. The context holds `kind`, `subject_id`, `actor_id`, `file` and
 * `previous_file` (ids only).
 *
 * With an action log of user relations (`AbstractActionLog`) AuditKit turns the ids into references to the user entity,
 * so they must be its identifier: with `owner_images.audit.user_id_field` (users whose subject id is another field, such
 * as a public UUID) the identifier is looked up by that field, as a scalar (no entity is loaded) and matched byte for
 * byte; a subject id that matches no user, or several, is recorded without that user link.
 *
 * Stateless (worker mode, kernel reset off).
 */
final readonly class OwnerImageAuditListener
{
    public const UPLOADED = 'uploaded';

    public const REPLACED = 'replaced';

    public const REMOVED = 'removed';

    /** Placeholder of the kind name in an action. */
    public const KIND_PLACEHOLDER = '{kind}';

    /**
     * @param array<string, array{uploaded: string, replaced: string, removed: string}> $actions kind => actions
     *                                                                                           (`{kind}` replaced)
     * @param string|null $userIdField user field that holds the subject id (user-relation action log only)
     * @param class-string|null $logClass the action log entity class (with `$userIdField`)
     */
    public function __construct(
        private ActionLogIdRecorderInterface $recorder,
        private OwnerImageKinds $kinds,
        private array $actions,
        private ?ManagerRegistry $doctrine = null,
        private ?string $userIdField = null,
        private ?string $logClass = null,
    ) {
    }

    public function __invoke(OwnerImageChangedEvent $event): void
    {
        $kind    = $this->kinds->get($event->kind);
        $actions = $this->actions[$event->kind] ?? null;
        if (!$kind instanceof OwnerImageKind || $actions === null) {
            return;
        }
        $change = match (true) {
            $event->fileId === null         => self::REMOVED,
            $event->previousFileId === null => self::UPLOADED,
            default                         => self::REPLACED,
        };
        $this->recorder->recordIdsAndFlush(
            $actions[$change],
            $this->userId($event->actorId),
            $kind->isUserKind() ? $this->userId($event->subjectId) : null,
            [
                'kind'          => $kind->name,
                'subject_id'    => $event->subjectId,
                'actor_id'      => $event->actorId,
                'file'          => $event->fileId,
                'previous_file' => $event->previousFileId,
            ],
        );
    }

    /**
     * The id AuditKit stores (or references) for a user's subject id.
     */
    private function userId(?string $subjectId): ?string
    {
        if ($subjectId === null || $this->userIdField === null || $this->logClass === null || !$this->doctrine instanceof ManagerRegistry) {
            return $subjectId;
        }
        $manager = $this->doctrine->getManagerForClass($this->logClass);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine entity manager maps the action log class "%s".', $this->logClass));
        }
        $target   = $manager->getClassMetadata($this->logClass)->getAssociationTargetClass('actor');
        $metadata = $manager->getClassMetadata($target);
        if (!$metadata->hasField($this->userIdField)) {
            throw new LogicException(sprintf('owner_images.audit.user_id_field: "%s" is not a mapped field of "%s".', $this->userIdField, $target));
        }
        $rows = $manager->createQueryBuilder()
            ->select(sprintf('u.%s AS id', $metadata->getSingleIdentifierFieldName()), sprintf('u.%s AS subject', $this->userIdField))
            ->from($target, 'u')
            ->where(sprintf('u.%s = :subject', $this->userIdField))
            ->setParameter('subject', $subjectId, $metadata->getTypeOfField($this->userIdField))
            ->getQuery()
            ->getArrayResult();
        $ids = [];
        foreach ($rows as $row) {
            if (ColumnPointer::text($row['subject'] ?? null) === $subjectId) {
                $ids[] = ColumnPointer::text($row['id'] ?? null);
            }
        }

        return count($ids) === 1 ? $ids[0] : null;
    }
}
