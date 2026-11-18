# Feature Specification: Owner-image AuditKit bridge and column pointer store

**Feature Branch**: `feat/owner-image-audit-column-pointer`  
**Created**: 2026-11-18  
**Status**: Implemented (Unreleased, target v1.20.0)

**Package**: `nowo-tech/user-kit-bundle`  
**Configuration root**: `nowo_user_kit.owner_images` (new keys `pointer_store: column`, `kinds.*.pointer`, `audit`)  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md) FR-IMG-001 … FR-IMG-003 (1.18 owner images)  
**Origin**: symfony-beacon re-audit KIT-87. After UserKit 1.18, Beacon still has two classes for owner images: an audit
listener (`OwnerImageAuditListener`) and a pointer store (`BeaconOwnerImagePointers`) that keeps the image id in a
column of the user or organization row and checks the identity map. Both are generic, so the bundle now provides
them. The change is additive (BC).

---

## Summary

| Unit | Purpose |
| ---- | ------- |
| `OwnerImage\Pointer\ColumnOwnerImagePointerStore` | `pointer_store: column`: the pointer lives in a column of the owner's root entity, mapped per kind |
| `OwnerImage\Pointer\ColumnPointer` (internal) | One kind's column, resolved from the Doctrine metadata on every call |
| `DependencyInjection\ColumnPointers` | Compile-time checks of `kinds.*.pointer` |
| `OwnerImage\Audit\OwnerImageAuditListener` | AuditKit action log rows for uploaded, replaced and removed images, recorded by ids |
| `DependencyInjection\Compiler\OwnerImageAuditPass` | Registers the listener when `owner_images.audit` is on and AuditKit's action log exists |

---

## Requirements

- **FR-001** `pointer_store: column` and `kinds.<kind>.pointer: {entity, field, subject_field}`. `subject_field`
  defaults to the single identifier. When it is set, every kind needs a pointer. A pointer without it fails the
  build.
- **FR-002** Build-time checks: the class exists, it declares the properties (a private property of a parent class
  counts), and on an attribute-mapped entity each one is `#[ORM\Column]`. No two kinds share a column. Run-time
  checks against the Doctrine mapping: the entity is mapped and is the root entity, both fields are mapped, the
  pointer is neither the subject nor an identifier, a composite identifier names its `subject_field`, and there is no
  association identifier.
- **FR-003** Identity-map aware. A managed, initialised entity answers reads, and a lazy proxy is not loaded. Once a
  write succeeds, managed entities of that subject get the new value, also as their original value, so the next
  flush writes nothing for them. The application's entity manager is never flushed.
- **FR-004** Tenant safety. Writes are one DBAL `UPDATE` that names the identifier read under the row lock and the
  exact subject id. Rows are compared byte for byte after they are read (house rule LQ). Two rows of one subject id
  write nothing (`UserKitConflict`) and read as no pointer. A subject with no row is refused when an image is set, and
  is a no-op when the image is removed.
- **FR-005** Values are converted with the mapped DBAL types, so string, integer and UUID subject columns all work. A
  subject id the type cannot take, such as `abc` for an integer, has no row.
- **FR-006** `owner_images.audit` (`true` or `{enabled, user_id_field, actions, kinds}`) needs AuditKit 1.15+ with its
  action log enabled; without it, the build fails. Actions default to `owner_image.uploaded`, `owner_image.replaced`
  and `owner_image.removed`, can be set per kind, and `{kind}` is replaced by the kind name. Each one is checked
  against the 48-character action column at build time.
- **FR-007** Rows are recorded through `ActionLogIdRecorderInterface::recordIdsAndFlush()`, never with entities, so they
  are replay safe. The actor is the actor's subject id. The subject is the subject id for `user` kinds and none for
  other kinds. The context is `kind`, `subject_id`, `actor_id`, `file` and `previous_file`.
- **FR-008** With an action log of user relations, `user_id_field` maps subject ids to the user class's identifiers. The
  lookup is a scalar query with an exact match; no match or several matches gives no link. If the user class
  implements `OwnerImageSubjectInterface` (or there is no user class) and `user_id_field` is missing, the build fails.
- **FR-010** (audit TC) The bridge needs `flush_mode: isolated` and fails the build in `host` mode (M1). A row that
  fails is logged as a warning with the exception class only and not thrown (L4); a transient failure is rethrown (L5). Managed entities are synced only
  after a real write (L1). Inside the application's transaction their original value is left unchanged, so after a
  rollback they show up as dirty; reload or clear them (L2). The identity map answers only for a unique subject
  field (L3). Malformed guid / uuid and integer / bigint subject ids never reach the query (I1). The `UPDATE`
  bypasses lifecycle callbacks, listeners and `#[Version]` (I2, documented).
- **FR-009** Worker mode with kernel reset off: no service keeps state. The default pointer store, table
  `user_kit_owner_image`, is unchanged.

## Example: the host config that replaces Beacon's two classes

```yaml
nowo_user_kit:
    owner_images:
        enabled: true
        kinds:
            avatar:
                subject: user
                read: authenticated
                shape: circle
                pointer: { entity: App\Identity\Entity\User, field: avatarFileUuid, subject_field: uuid }
            org_logo:
                read: authenticated
                pointer: { entity: App\Billing\Entity\BillingAccount, field: logoFileUuid, subject_field: uuid }
            org_logo_dark:
                read: authenticated
                pointer: { entity: App\Billing\Entity\BillingAccount, field: logoDarkFileUuid, subject_field: uuid }
        user_class: App\Identity\Entity\User
        access: App\Files\OwnerImage\BeaconOwnerImageAccess
        pointer_store: column            # replaces App\Files\OwnerImage\BeaconOwnerImagePointers
        storage: App\Files\OwnerImage\BeaconOwnerImageStorage
        audit:                           # replaces App\Files\OwnerImage\OwnerImageAuditListener
            enabled: true
            user_id_field: uuid          # UserAction (AbstractActionLog) references users by their integer id
            kinds:
                avatar: { uploaded: account.avatar_uploaded, replaced: account.avatar_uploaded, removed: account.avatar_removed }
                org_logo: { uploaded: organization.logo_uploaded, replaced: organization.logo_uploaded, removed: organization.logo_removed }
                org_logo_dark: { uploaded: organization.logo_uploaded, replaced: organization.logo_uploaded, removed: organization.logo_removed }
```

Differences from Beacon's listener:

- The organization rows carry the organization's UUID as `context.subject_id`, not as `account`. There is no
  `account_name`, and the theme is the kind (`org_logo` or `org_logo_dark`). Beacon's activity templates read those
  keys from the context.
- An upload with no known actor is still recorded (actor null); Beacon's listener skipped it.

```yaml
nowo_audit_kit:
    action_log:
        entity_class: App\Identity\Entity\UserAction
        flush_mode: isolated             # required by owner_images.audit (audit TC-M1)
```

## Success criteria

- Beacon deletes `BeaconOwnerImagePointers` and `OwnerImageAuditListener` and keeps its own access and storage.
- The tests pass on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 17, with 100% line coverage, and PHPStan, CS and
  Igor are green.
