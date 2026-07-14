# Spec-driven development — UserKitBundle

**Status:** Implemented (baseline audited 2026-07-14)

This repository follows the Nowo bundle **spec-driven development** model in three layers:

1. **Baseline spec** — [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
2. **Integrator docs** — `docs/INSTALLATION.md`, `CONFIGURATION.md`, `USAGE.md`.
3. **Mechanical proof** — PHPUnit 100% coverage, PHPStan, CI.

---

## Bundle functional scope

**Goal:** User account state (enable/disable blocking login) and optional last-activity / online presence.

### In scope

- `UserChecker` for disabled accounts (blocks login even with valid password).
- Configurable `enabled` field on the user entity.
- Optional `lastActivityAt` tracking with write throttle.
- `online_threshold` and `UserPresenceResolver`.
- Optional session invalidation when disabling an account.
- Traits and interfaces for application entities.
- Coexistence documentation with **AuthKitBundle** (same `user_class`, no hard dependency).
- Translation domain `NowoUserKitBundle` with seven required locales.

### Explicit non-goals

- Login/register/reset UI or routes → **AuthKitBundle**.
- Login rate limiting → **LoginThrottleBundle**.
- User CRUD admin UI.

---

## Workflow

| Phase | Artifact | Command / location |
| ----- | -------- | ------------------ |
| Baseline | `specs/001-baseline/spec.md` | Product requirements |
| Inventory | `specs/001-baseline/code-inventory.md` | Traceability matrix |
| Implement | `src/` + tests | `make test-coverage-100` |
| Release | `docs/CHANGELOG.md`, `docs/UPGRADING.md` | `make release-check` |

New features should add a numbered folder under `specs/` (e.g. `002-feature-name/`) with `spec.md`, optional `plan.md` and `tasks.md`, following [docs/SPEC-KIT.md](SPEC-KIT.md).

---

## Validation commands

```bash
make test-coverage-100
make phpstan
make validate-translations
make release-check
```

---

## Reference anchors

- [REQ-SPECKIT-001](https://github.com/nowo-tech/bundles/blob/main/BUNDLES_FULL_SPECS_DETAILS.md#REQ-SPECKIT-001) — baseline spec + code inventory
- [REQ-DOCS-013](https://github.com/nowo-tech/bundles/blob/main/BUNDLES_FULL_SPECS_DETAILS.md#REQ-DOCS-013) — spec-driven development pattern
