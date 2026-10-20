# Spec-driven development — UserKitBundle

**Status:** Implemented (baseline audited 2026-10-20)

This repository follows the Nowo bundle **spec-driven development** model in three layers:

1. **Baseline spec** — [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
2. **Integrator docs** — `docs/INSTALLATION.md`, `CONFIGURATION.md`, `USAGE.md`.
3. **Mechanical proof** — PHPUnit 100% coverage, PHPStan, CI.

---

## Table of contents

- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
  - [In scope](#in-scope)
  - [Explicit non-goals](#explicit-non-goals)
- [Workflow](#workflow)
- [Validation commands](#validation-commands)
- [Relationship to Engram](#relationship-to-engram)
- [Reference anchors](#reference-anchors)

## User stories

| ID | Story | Functional scope | Integrator docs |
| --- | --- | --- | --- |
| US-01 / US-02 | Disabled users cannot log in (even with a valid password); enabled users log in normally | FR-SEC-001, FR-CONTRACT-001, FR-TRAIT-001 | [USAGE.md](USAGE.md) |
| US-03 | Integrator configures the `enabled` field name per profile | FR-CFG-001, FR-CFG-002 | [CONFIGURATION.md](CONFIGURATION.md) |
| US-04 / US-05 | `lastActivityAt` is tracked on authenticated requests with a write throttle | FR-PRES-001, FR-TRAIT-002 | [USAGE.md](USAGE.md) |
| US-06 / US-09 | Online status via `online_threshold`, `UserPresenceResolver` and the Twig helper | FR-PRES-002 | [USAGE.md](USAGE.md) |
| US-07 | Sessions are invalidated when an account is disabled | FR-SES-001, FR-SES-002 | [CONFIGURATION.md](CONFIGURATION.md) |
| US-08 | Optional AuthKitBundle integration on the same `user_class` | FR-CFG-001 | [INSTALLATION.md](INSTALLATION.md) |
| 002 US-01 … US-05 | UI preferences and product-tour progress without a host migration | [`002-ui-preferences`](../specs/002-ui-preferences/spec.md) FR-001 … FR-007 | [CONFIGURATION.md](CONFIGURATION.md), [USAGE.md](USAGE.md) |
| 003 US-01 … US-04 | Personal data export / erasure (GDPR self-service) with host vetoes, atomic | [`003-personal-data`](../specs/003-personal-data/spec.md) FR-001 … FR-006 | [CONFIGURATION.md](CONFIGURATION.md), [USAGE.md](USAGE.md) |

Acceptance criteria (Given/When/Then) live in the specs under [`specs/`](../specs/).

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
- Optional UI preferences (`UiPreferencesTrait` / embeddable, preference enums, `user_ui_html_attributes()` Twig function) and product-tour progress ([`002-ui-preferences`](../specs/002-ui-preferences/spec.md)).
- Optional personal data SPI: export providers, erasers and erasure guards with built-in sections for the kit columns ([`003-personal-data`](../specs/003-personal-data/spec.md)). The kit never disables or anonymises the account itself.

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

## Relationship to Engram

[`ENGRAM.md`](ENGRAM.md) is the org-wide checklist / memory plane (MCP server in `.cursor/mcp.json`, persisted decisions). It does not define product behavior: this document and the Spec Kit specs state what the bundle guarantees and how it is proven, while Engram points back here for local `REQ-*` traceability.

---

## Reference anchors

- [REQ-SPECKIT-001](https://github.com/nowo-tech/bundles/blob/main/BUNDLES_FULL_SPECS_DETAILS.md#REQ-SPECKIT-001) — baseline spec + code inventory
- [REQ-DOCS-013](https://github.com/nowo-tech/bundles/blob/main/BUNDLES_FULL_SPECS_DETAILS.md#REQ-DOCS-013) — spec-driven development pattern
