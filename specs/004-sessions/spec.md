# Feature Specification: Session registry, revocation and concurrent-session limits

**Feature Branch**: `feat/sessions`  
**Created**: 2026-11-01  
**Status**: Implemented (Unreleased, target v1.9.0)

**Package**: `nowo-tech/user-kit-bundle`  
**Configuration root**: `nowo_user_kit.sessions`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md), [`../003-personal-data/spec.md`](../003-personal-data/spec.md)  
**Origin**: kit extraction KIT-41 — a host application's session registry (list, revoke, limits) generalised into the kit;
host-specific scopes (organizations, plan rules, notification inbox, app tokens) stay in the host behind the
`SessionLimitResolverInterface`, `DeviceIdResolverInterface` and the audit events.

---

## Summary

| Unit | Purpose |
| ---- | ------- |
| `Sessions\Entity\UserSession` | Read-only registry row (table `user_kit_session`, prefixable). |
| `Sessions\Doctrine\UserSessionStore` | DBAL writes (insert, touch, rekey, revoke, purge) and ORM lists. |
| `Sessions\UserSessionManager` | Public API: `listFor`, `revoke`, `revokeOthers`, `revokeAll`, `revokeDevice`, `currentHash`. |
| `Sessions\EventListener\SessionRegistryListener` | Request (before / after firewall), response, check-passport, login, logout. |
| `Sessions\Revocation\*` | `database` (row) or `cache` (shared PSR-6 pool) revocation lookup. |
| `Sessions\Limit\*` | `SessionLimitResolverInterface` + configured default; `evict_oldest` / `reject_new`. |
| `Sessions\EventListener\PasswordChangeListener`, `Sessions\RegistrySessionInvalidator` | Automatic sign-outs. |
| `Sessions\Controller\SessionsController`, `Sessions\Routing\SessionsRouteLoader`, `Sessions\Ui\SessionsPages` | Opt-in pages. |
| `Sessions\Command\PurgeSessionsCommand` | Retention purge. |
| `Sessions\PersonalData\*` | GDPR export section and eraser. |
| `Sessions\Event\*` | Audit events. |

Opt-in: `sessions.enabled: false` by default; the pages need `sessions.web_ui.enabled: true` **and** the route loader
import.

---

## Requirements

- **FR-001** One row per authenticated session of a stateful firewall: HMAC of the session id (never the id), owner
  (class + identifier), browser / OS names, IP per `client.ip` (truncate / hash / none), optional device id; created
  at sign-in (or on the next request of a session signed in before), `last_seen_at` at most once per `touch_interval`,
  deleted at logout, purged after `idle_lifetime` + `touch_interval`.
- **FR-002** A revoked, missing or idle session is signed out on its next request, before the firewall (`_stateless`
  routes of stateful firewalls included), with any session handler: session invalidated, token dropped, the
  remember-me cookie of every firewall removed and cleared with its configured options, reason flash. One marker per
  firewall context, one row per (session, owner).
- **FR-003** "My sessions": list, *This session*, sign out one, sign out all others. Administration: one user's
  sessions, sign out one, sign out everywhere. CSRF-only FormKit forms (token per action and row), UiKit list
  scaffold, confirm dialogs, `no-store`.
- **FR-004** Concurrent-session limit at sign-in: `evict_oldest` (oldest revoked, reason `evicted`) or `reject_new`
  (authentication failure after the credentials check); per-user limits through a resolver service.
- **FR-005** Automatic sign-outs: password field change of a profile user (other sessions), account disabled
  (`SessionInvalidatorInterface`), device (`revokeDevice`).
- **FR-006** Audit events after every mutation (`UserSessionStartedEvent`, `UserSessionsRevokedEvent`,
  `SessionLimitReachedEvent`, `UserSessionsPurgedEvent`).
- **FR-007** Routes opt-in (REQ-SF-004 rule 7), REQ-UI-002 access (`access_roles`, `manage_roles`, `access_checker`,
  `allow_unauthenticated` debug-only; empty role lists refused), CSRF fail-closed at compile time.
- **FR-008** GDPR: `sessions` export section and eraser when `personal_data.enabled`.

## Success criteria

- **SC-001** Two-browser test: a revoked session is signed out on its next request (database and cache stores).
- **SC-002** Both limit policies and a host resolver are tested.
- **SC-003** Requests between two touches write nothing; the `cache` store costs no query per request.
- **SC-004** A kernel whose routing is only `routing.controllers` has no bundle route.
- **SC-006** The REQ-SEC-004 Appendix CC findings have regression tests (`SessionSecurityRegressionTest`).
- **SC-005** 100% line coverage, PHPStan, Rector, Igor, CS, page contract test green.

## Out of scope

- Geolocation of sessions, risk-based step-up, passkeys management, API / Bearer tokens (stateless firewalls are
  ignored), voiding remember-me cookies copied to another device (host `signature_properties`).
