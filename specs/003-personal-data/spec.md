# Feature Specification: Personal data export and erasure SPI (GDPR self-service)

**Feature Branch**: `003-personal-data`  
**Created**: 2026-10-13  
**Status**: Implemented (v1.5.0)

**Package**: `nowo-tech/user-kit-bundle`  
**Configuration root**: `nowo_user_kit.personal_data`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md), [`../002-ui-preferences/spec.md`](../002-ui-preferences/spec.md)  
**Origin**: Symfony Beacon spec `138-kit-medium-extractions` FR-031 (KIT-GDPR) — the orchestration of Beacon's `AccountDataExporter` / `AccountAnonymizer` moves into the kit; controllers and domain guards (last admin, sole owner) stay in the host. AnonymizeBundle is dev-only and refuses production, so it is not the home for this.

---

## Summary

| Unit | Purpose |
| ---- | ------- |
| `PersonalData\PersonalDataExportProviderInterface` | One section (`getSectionKey()`, `supports()`, `export()`). |
| `PersonalData\PersonalDataEraserInterface` | One erasure step (`getKey()`, `supports()`, `erase(ErasureRequest)`). |
| `PersonalData\ErasureGuardInterface` | `check(ErasureRequest): ?ErasureVeto`. |
| `PersonalData\PersonalDataExporter` | Versioned document `{schema, version, exported_at, sections}`; `exportJson()`. |
| `PersonalData\PersonalDataEraser` | `check()` pre-flight; `erase()` = guards, then erasers in one transaction. |
| `PersonalData\ErasureRequest`, `ErasureVeto`, `ErasureVetoedException` | Value objects / veto exception. |
| `PersonalData\Provider\*`, `PersonalData\Eraser\*` | Built-ins for the UserKit models. |

Opt-in: `nowo_user_kit.personal_data.enabled: false` by default; nothing is registered or autoconfigured until enabled.

---

## User Scenarios

### US-01 — Export my data (P1)

**Given** the SPI is enabled and the host registers an `account` provider,  
**When** the privacy controller calls `PersonalDataExporter::export($user)`,  
**Then** the document has the configured `schema` and `version`, an ATOM `exported_at`, and `sections` in priority order: host sections first, then `account_status`, `last_activity`, `ui_preferences`, `product_tour` (for the models the user has).

### US-02 — Erase my data (P1)

**Given** host erasers (e.g. scrub identifiers, delete push subscriptions) and the built-ins,  
**When** `PersonalDataEraser::erase($user, $user)` is called and no guard vetoes,  
**Then** every supporting eraser runs once, in priority order, inside one Doctrine transaction, flushed once, and the keys of the erasers that ran are returned.

### US-03 — Veto (P1)

**Given** a host guard returning `new ErasureVeto('last_admin')` for the last administrator,  
**When** that user asks for erasure,  
**Then** `ErasureVetoedException` (with every veto) is thrown before any eraser runs; `check()` returns the same vetoes for the privacy page.

### US-04 — Atomicity (P1)

**Given** an eraser that throws after a previous eraser changed data (ORM or DBAL on the same connection),  
**When** `erase()` runs,  
**Then** the transaction is rolled back and the exception propagates; nothing is persisted.

---

## Functional requirements

- **FR-001**: Tags `nowo_user_kit.personal_data.export_provider`, `nowo_user_kit.personal_data.eraser`, `nowo_user_kit.personal_data.erasure_guard`, autoconfigured from the interfaces when enabled; ordered by tag priority (higher first).
- **FR-002**: Configuration `personal_data.{enabled: false, schema: 'nowo-user-kit/personal-data', version: 1, builtin_providers: true, builtin_erasers: true}`.
- **FR-003**: Exporter normalises nested `DateTimeInterface` (ATOM), `BackedEnum` (value) and `UnitEnum` (name); duplicate section keys throw `LogicException`; unsupported providers are skipped.
- **FR-004**: Eraser uses the entity manager of the user class (`ManagerRegistry::getManagerForClass()`; `LogicException` when none) and `wrapInTransaction()`; erasers must not flush.
- **FR-005**: Built-in sections — `account_status` `{enabled}`, `last_activity` `{last_activity_at}`, `ui_preferences` (nine `preferred_*` keys), `product_tour` `{seen_at, seen_pages}`; priorities `-100` … `-130`.
- **FR-006**: Built-in erasers — `last_activity` (`clearLastActivityAt()`, new on `LastActivityTrait`), `ui_preferences` (`resetUiPreferences()`), `product_tour` (`clearProductTourSeen()`); priorities `-100` … `-120`. The kit never disables or anonymises the account itself.

## Tests

- `tests/Integration/PersonalDataKernelTest.php` — kernel with SQLite: document shape and order, priority order of erasers, persisted erasure, guard veto, rollback after a failing eraser.
- `tests/Unit/PersonalData/*` — exporter normalisation / duplicates / JSON, eraser vetoes / skipping / missing entity manager, built-in providers (profile field and interface paths).
- `tests/Unit/DependencyInjection/NowoUserKitExtensionPersonalDataTest.php` — disabled by default, autoconfiguration, built-in toggles.

## Host adoption (Beacon)

Split `AccountDataExporter` into providers: `account` (identity fields, roles, timestamps; priority 100), `project_memberships`, `group_memberships`, `security_activity`, `password_history`, `social_accounts`, `listing_preferences`, `push_subscriptions` (count), `notes`; the `preferred_*` and `last_activity_at` keys come from the kit sections. Schema `beacon-account-export`, version `2` (the document moves from flat keys to `sections`).

Split `AccountAnonymizer` into guards `SoleOwnerGuard` (`sole_owner`, details = projects) and `LastAdminGuard` (`last_admin`), plus an `AlreadyAnonymizedGuard`; erasers `request_and_consent_logs` (DBAL deletes), `account_identity` (email / name / phone / Slack / roles / password / reset token / `anonymizedAt`, disable), `password_history`, `push_subscriptions`, `social_accounts`, host preference columns (issue panels, push, alerts), and `audit` (records `UserAnonymized`, lowest priority); the kit erasers reset the UI preferences and tours. `AccountPrivacyController` keeps its routes and maps `ErasureVetoedException::getReasons()` to its flash messages; `can_anonymize` becomes `[] === $eraser->check($user)`.
