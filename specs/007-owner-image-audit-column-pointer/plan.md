# Plan: owner-image AuditKit bridge and column pointer store

1. Configuration (`OwnerImagesConfiguration`): `kinds.*.pointer`, `audit` (`canBeEnabled`), and `pointer_store: column`
   as a reserved value.
2. Loader: `ColumnPointers::fromConfig()` (build-time checks), registration of `ColumnOwnerImagePointerStore`,
   expansion of the audit actions (`auditActions()`), and the `nowo_user_kit.owner_images.audit` parameter.
3. `OwnerImageAuditPass` (after every extension is loaded): checks AuditKit, the log class mode and `user_id_field`,
   then registers `OwnerImageAuditListener`.
4. Store: `ColumnPointer::resolve()` on every call; the identity map first, then chunked DBAL reads; a locked
   select-then-update by identifier and subject inside `DoctrineTransactionRunner::forConnection()` driven by
   `UserKitTransactionRunner::statement()`; the managed entities are synced after the write.
5. Tests: unit tests (configuration, build-time checks, pass) and integration tests (`ColumnPointerStoreTest`,
   `ColumnPointerMappingTest`, `OwnerImageAuditBridgeTest`). CI runs the store and audit tests on MySQL, MariaDB and
   PostgreSQL.
6. Docs: CHANGELOG `[Unreleased]`, UPGRADING, USAGE, CONFIGURATION, and the code inventory (FR-IMG-004 / FR-IMG-005).
