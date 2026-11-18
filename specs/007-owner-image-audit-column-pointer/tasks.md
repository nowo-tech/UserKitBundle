# Tasks: owner-image AuditKit bridge and column pointer store

- [x] T001 Configuration keys and validation (`OwnerImagesConfiguration`)
- [x] T002 Build-time checks (`ColumnPointers`) and loader wiring
- [x] T003 `ColumnPointer` + `ColumnOwnerImagePointerStore` (identity map, locked DBAL write, exact match)
- [x] T004 `OwnerImageAuditListener` + `OwnerImageAuditPass` (AuditKit 1.15+ id recording, `user_id_field`)
- [x] T005 Fixtures and tests (SQLite locally; MySQL / MariaDB / PostgreSQL in CI and in the local DB containers)
- [x] T006 CHANGELOG `[Unreleased]`, UPGRADING, USAGE, CONFIGURATION, code inventory
