<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Doctrine;

use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\PointerCollationListener;
use Nowo\UserKitBundle\Sessions\Doctrine\OwnerCollationListener;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;

/**
 * The bundle's own declaration of the identity / access-key columns of its tables (REQ-SEC-006).
 *
 * The owner of a session is the pair (`user_type`, `user_id`), and the subject of an owner image is the pair
 * (`kind`, `subject_id`): both halves are identity, and both are compared with `=`. On a folding collation `Alice`,
 * `alice` and `álice` are one owner and `alice ` is too, so an owner lookup in SQL alone reached the sessions, the
 * remember-me epoch and the image of a *look-alike* owner — the class of defect behind GHSA-2vm3-p5vj-f364. The
 * bundle already keeps these four tables' owner columns on the server's binary NO PAD collation when it builds the
 * schema ({@see OwnerCollationListener},
 * {@see PointerCollationListener}) and re-checks every row with `===`.
 *
 * What was missing is the *assertion*. Only a host's hand-kept list knew that `user_kit_session.user_id` is an
 * identity key, so a table created before the collation listener, restored from a dump or imported by hand kept the
 * host's folding default and nothing said so — and `sessions.doctrine.exact_owner_collation: true`, which drops the
 * candidate scan precisely because the column is exact, had nothing to check it against. This declaration is what
 * `nowo:migrations:check-key-collations` asserts; MigrationsKit discovers it by autoconfiguration, so a host
 * registers **nothing** and can delete the `nowo_migrations_kit.collation_check.ignore_columns` entries it kept for
 * these columns.
 *
 * Deliberately **not** declared, because a declaration is a claim about identity and an untrue one is worse than
 * none: `browser`, `os`, `ip` and `device_id` (what the client looked like, shown in the session list),
 * `revoked_reason` (a state word the bundle writes itself) and the integer surrogate `id` columns, which carry no
 * collation at all. `public_id` and `session_hash` *are* access keys, but the bundle does not give them an exact
 * collation today, and a declaration whose column was never collated reports a failure on every host: raising those
 * two is a schema decision (mapping plus an `ALTER` for existing tables) for its own release, not something a
 * declaration can do by itself.
 *
 * The session tables' names are resolved from `sessions.doctrine.table_prefix`, never literals. The owner-image
 * pointer table has no prefix of any kind (the prefix listener covers the session entities only) and the host cannot
 * rename it, so its name is the store's own constant. The columns of a feature that is off are declared **inactive**
 * (reported as skipped with the setting that gates them, never as a missing table): the session registry, the
 * remember-me epoch and the owner images are each opt-in, and a host that puts its image pointers in a column of the
 * owner's own row has no pointer table either.
 *
 * Stateless and database-free (REQ-RUNTIME-002): the list is rebuilt on every call, so the service is safe to keep
 * between requests in a FrankenPHP worker with `kernel.reset: false`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class UserKitExactKeyColumns implements ExactKeyColumnsInterface
{
    /** The package that owns the tables, as reported by the aggregate run. */
    public const OWNER = 'nowo-tech/user-kit-bundle';

    /** Condition reported for the session registry columns when the feature is off. */
    public const SESSIONS_CONDITION = 'nowo_user_kit.sessions.enabled: true';

    /** Condition reported for the epoch columns when the remember-me epoch is off. */
    public const EPOCH_CONDITION = 'nowo_user_kit.sessions.remember_me_epoch.enabled: true';

    /** Condition reported for the pointer table when the owner images are off or kept in a column. */
    public const OWNER_IMAGE_CONDITION = 'nowo_user_kit.owner_images.enabled: true with the default pointer table (no owner_images.pointer_store)';

    /**
     * @param string $tablePrefix nowo_user_kit.sessions.doctrine.table_prefix
     * @param bool $sessions nowo_user_kit.sessions.enabled (the session registry table exists)
     * @param bool $epoch nowo_user_kit.sessions.remember_me_epoch.enabled (the epoch table exists)
     * @param bool $ownerImagePointerTable the default owner-image pointer table is mapped
     */
    public function __construct(
        private string $tablePrefix = '',
        private bool $sessions = false,
        private bool $epoch = false,
        private bool $ownerImagePointerTable = false,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function exactKeyColumns(): array
    {
        $session = $this->tablePrefix . UserSession::TABLE;
        $epoch   = $this->tablePrefix . UserSessionEpoch::TABLE;
        $image   = DbalOwnerImagePointerStore::TABLE;

        $sessionCondition = $this->sessions ? null : self::SESSIONS_CONDITION;
        // The epoch table also needs the feature itself: without the registry nothing registers the epoch store.
        $epochActive    = $this->sessions && $this->epoch;
        $epochCondition = $epochActive ? null : ($this->sessions ? self::EPOCH_CONDITION : self::SESSIONS_CONDITION);
        $imageCondition = $this->ownerImagePointerTable ? null : self::OWNER_IMAGE_CONDITION;

        return [
            new ExactKeyColumn($session, 'user_type', self::OWNER, 'session owner (class half of the identity), compared with =', $sessionCondition, $this->sessions),
            new ExactKeyColumn($session, 'user_id', self::OWNER, 'session owner (id half of the identity), compared with =', $sessionCondition, $this->sessions),
            new ExactKeyColumn($epoch, 'user_type', self::OWNER, 'remember-me epoch owner (class half of the identity)', $epochCondition, $epochActive),
            new ExactKeyColumn($epoch, 'user_id', self::OWNER, 'remember-me epoch owner (id half of the identity)', $epochCondition, $epochActive),
            new ExactKeyColumn($image, 'kind', self::OWNER, 'owner-image subject (kind half of the identity)', $imageCondition, $this->ownerImagePointerTable),
            new ExactKeyColumn($image, 'subject_id', self::OWNER, 'owner-image subject (id half): the key the image of a person is read by', $imageCondition, $this->ownerImagePointerTable),
        ];
    }
}
