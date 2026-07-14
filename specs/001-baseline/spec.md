# Feature Specification: UserKitBundle baseline

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-14  
**Status**: Implemented

**Package**: `nowo-tech/user-kit-bundle`  
**Configuration root**: `nowo_user_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

Symfony bundle for **user account state and presence**:

1. **Enable / disable** accounts via a configurable entity field; a registered **`UserChecker`** blocks authentication (including after password validation) when the account is disabled.
2. **`lastActivityAt`** updated on authenticated HTTP requests with a configurable write throttle.
3. **`online_threshold`** (seconds) to determine whether a user is still “connected” based on last activity.
4. Optional **session invalidation** when an account transitions from enabled to disabled.

Complements **AuthKitBundle** (login/register/reset UI and routes) without requiring it. Complements **LoginThrottleBundle** (rate limiting) — different concern: account status vs. brute-force throttling.

---

## User Scenarios

### US-01 — Block login for disabled user (P1)

**Given** `nowo_user_kit.account_status.enabled: true` and a user with `enabled: false`,  
**When** they submit valid credentials on a `form_login` firewall,  
**Then** Symfony `UserChecker` throws `DisabledException` (or custom exception implementing the same contract), login fails, and no authenticated token is created.

### US-02 — Allow login for enabled user (P1)

**Given** the same configuration and a user with `enabled: true`,  
**When** credentials are valid,  
**Then** authentication proceeds normally (no interference from UserKit beyond the checker).

### US-03 — Configure enabled field name (P1)

**Given** `nowo_user_kit.account_status.field: isActive` on an entity exposing `isActive(): bool`,  
**When** the checker runs,  
**Then** it reads the configured property/method instead of assuming `enabled`.

### US-04 — Track last activity (P1)

**Given** `nowo_user_kit.last_activity.enabled: true` and an authenticated user on an HTTP request,  
**When** the request is handled and the throttle interval has elapsed since the last DB write,  
**Then** `lastActivityAt` (or configured field) is persisted with the current timestamp.

### US-05 — Throttle activity writes (P2)

**Given** `nowo_user_kit.last_activity.update_throttle: 30` (seconds),  
**When** multiple requests arrive within 30 seconds,  
**Then** at most one DB update for last activity occurs in that window.

### US-06 — Detect online status (P1)

**Given** `nowo_user_kit.last_activity.online_threshold: 60`,  
**When** `UserPresenceResolver::isOnline($user)` is called and `now - lastActivityAt <= 60`,  
**Then** it returns `true`; otherwise `false`. Null/missing last activity returns `false`.

### US-07 — Invalidate sessions on disable (P2)

**Given** `nowo_user_kit.account_status.invalidate_sessions_on_disable: true`,  
**When** a user is persisted with `enabled` changing from `true` to `false`,  
**Then** active sessions for that user are invalidated (Symfony session handler and/or remember-me tokens per documented strategy).

### US-08 — Optional AuthKit integration (P2)

**Given** both AuthKit and UserKit are installed,  
**When** an integrator follows the installation guide,  
**Then** no duplicate login logic is required; UserKit checker applies to the same `user_class` configured in `nowo_auth_kit.user_class`.

### US-09 — Twig presence helper (P3)

**Given** Twig integration is enabled,  
**When** `user_is_online(user)` is used in a template,  
**Then** it delegates to `UserPresenceResolver` with configured threshold.

---

## Requirements

### Bundle & configuration

- **FR-BUNDLE-001**: `NowoUserKitBundle` with extension alias `nowo_user_kit`.
- **FR-CFG-001**: `Configuration` tree:
  - `user_class` — FQCN (required when bundle enabled; may default from `nowo_auth_kit.user_class` if that parameter exists — optional bridge, not a Composer dependency).
  - `account_status`:
    - `enabled` (bool, default `true`) — master switch for UserChecker.
    - `field` (string, default `enabled`) — entity property for account status.
    - `invalidate_sessions_on_disable` (bool, default `false`).
  - `last_activity`:
    - `enabled` (bool, default `false`).
    - `field` (string, default `lastActivityAt`).
    - `online_threshold` (int seconds, default `300`, min `1`).
    - `update_throttle` (int seconds, default `30`, min `0`).
- **FR-CFG-002**: Default YAML template under `Resources/config/packages/nowo_user_kit.yaml`.

### Contracts (application entity)

- **FR-CONTRACT-001**: `AccountStatusInterface` — `isEnabled(): bool` (optional; bundle may use PropertyAccessor when interface absent).
- **FR-CONTRACT-002**: `LastActivityInterface` — `getLastActivityAt(): ?\DateTimeInterface`, `setLastActivityAt(\DateTimeInterface): void` (optional with configurable field).
- **FR-TRAIT-001**: `EnabledUserTrait` — Doctrine column + accessors for `enabled` (default `true`).
- **FR-TRAIT-002**: `LastActivityTrait` — nullable `lastActivityAt` column + accessors.

### Security

- **FR-SEC-001**: `AccountStatusUserChecker` implements Symfony `UserCheckerInterface`:
  - `checkPreAuth` — no-op or lightweight checks.
  - `checkPostAuth` — if account disabled, throw `DisabledException` with translatable message key in domain `NowoUserKitBundle`.
- **FR-SEC-002**: Auto-register checker tag `security.user_checker` when `account_status.enabled: true`.
- **FR-SEC-003**: Document firewall requirement: integrator must not disable user checkers on the firewall (Symfony default includes them).

### Presence

- **FR-PRES-001**: `LastActivitySubscriber` on `KernelEvents::REQUEST` (priority documented) — only main requests, only fully authenticated users, respects throttle.
- **FR-PRES-002**: `UserPresenceResolver` — `isOnline(object $user): bool` using configured threshold and field.

### Session invalidation

- **FR-SES-001**: `AccountDisabledListener` on Doctrine `onFlush` or `postUpdate` — detect `enabled` true→false transition.
- **FR-SES-002**: Strategy interface `SessionInvalidatorInterface` with default implementation documented for Symfony session storage; extensible for Redis/database sessions.

### Integration & i18n

- **FR-I18N-001**: Translation domain `NowoUserKitBundle` — disabled account message (minimum `en`, `es`; align with AuthKit locale set when implemented).
- **FR-DOC-001**: `docs/INSTALLATION.md`, `CONFIGURATION.md`, `USAGE.md` — AuthKit coexistence section.

### CLI (optional v1.1)

- **FR-CLI-001** *(deferred)*: `nowo:user-kit:configure-security` — verify firewall has user checker enabled (informational).

---

## Success Criteria

- **SC-001**: All production files listed in `code-inventory.md` implemented and mapped.
- **SC-002**: Disabled user cannot authenticate with valid password (functional test).
- **SC-003**: Enabled user authenticates without regression (functional test).
- **SC-004**: Last activity updates respect throttle (unit test).
- **SC-005**: `isOnline()` respects threshold (unit test).
- **SC-006**: 100% PHPUnit line coverage on `src/` (Nowo bundle standard).
- **SC-007**: PHPStan max level passes.
- **SC-008**: Demo Symfony 7.4 + 8.1 apps with AuthKit + UserKit integration.

---

## Explicit non-goals

- Login, registration, password reset forms or routes (AuthKitBundle).
- Brute-force / login throttling (LoginThrottleBundle).
- `createdAt`, `updatedAt`, `createdBy`, `updatedBy` on entities (AuditKitBundle).
- Admin UI to enable/disable users (application responsibility).
- Real-time WebSocket presence (only last-activity heuristic).
- Mandatory coupling to AuthKit in `composer.json`.
- Multi-factor authentication.

---

## Validation

When implemented:

- `composer qa`, `make test-coverage-100`, PHPStan.
- Demo: disable user in DB/admin, confirm login blocked; enable user, confirm login works.
- Inventory row audit vs. `find src -type f`.

---

## Version roadmap (informative)

| Version | Scope |
| ------- | ----- |
| **v1.0.0** | Account status + UserChecker + traits + i18n + docs + demo |
| **v1.1.0** | Last activity + online threshold + `UserPresenceResolver` |
| **v1.2.0** | Session invalidation on disable + optional Twig helper |

*Exact split may change during implementation; user stories US-01–US-06 are the v1.0 MVP bar.*
