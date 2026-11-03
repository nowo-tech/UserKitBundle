# Feature Specification: Sessions that are not the request's own (parked sessions API)

**Feature Branch**: `feat/1.11.0-parked-sessions`  
**Created**: 2026-11-03  
**Status**: Implemented (Unreleased, target v1.11.0)

**Package**: `nowo-tech/user-kit-bundle`  
**Configuration root**: `nowo_user_kit.sessions` (no new key)  
**Baseline**: [`../005-session-host-hooks/spec.md`](../005-session-host-hooks/spec.md)  
**Origin**: AuthKitBundle 1.34 multi-account sign-in (symfony-beacon D-20261103-05): one browser keeps one session per
signed-in account and parks the inactive ones; AuthKit must ask the registry about, and sign out, sessions other than
the request's own. Additive API only (BC).

---

## Summary

| Unit | Purpose |
| ---- | ------- |
| `Sessions\Model\SessionState`, `Sessions\Model\SessionStatus` | State of one session seen from outside its requests (`live`, `revoked` + reason, `expired`, `unknown`, `lastSeenAt`); no hash |
| `UserSessionManager::sessionState()` / `sessionStates()` | State of one / several sessions (one query per owner) |
| `UserSessionManager::endSession()` | Sign a non-current session out like a logout (owner's row deleted, no event) |
| `UserSessionManager::rekey()` | Move the rows of a session's markers to its new id after a `migrate()` outside a sign-in |
| `Sessions\Doctrine\UserSessionStore::statesOf()` | One bound query for an owner's rows of several hashes |

---

## Requirements

- **FR-001** `sessionState($sessionId, $user)` returns `unknown` for an empty id, an unresolvable user or no row of
  that owner (another owner never sees the row); `revoked` with the stored reason (built-in or host-defined) for a
  revoked row; `expired` when `now − last_seen_at ≥ idle_lifetime + touch_interval` (the early check's rule); else
  `live`. `sessionStates()` answers in input order with one query per owner.
- **FR-002** `endSession($sessionId, $user)` deletes only that owner's row of that session (no-op for an empty id or
  an unresolvable user) and dispatches no event (as a logout); the browser holding that session is signed out on its
  next request (database store: missing row fails closed).
- **FR-003** `rekey($session, $previousId)` moves, for every marker of the session whose hash is the previous id's,
  the owner's row to the hash of the current id and updates the marker; other markers, malformed owner keys and an
  unchanged id are left alone.
- **FR-004** Session ids and hashes never leave the server through the new API (value objects carry neither).
- **FR-005** BC: additions only; no configuration, schema, event or behaviour change.

## Success criteria

- **SC-001** `ParkedSessionsTest`: live / revoked (reason) / expired at the exact boundary / unknown (other owner,
  unresolvable user, empty id); batched states in input order; `endSession()` signs that browser out and keeps the
  user's other session; `rekey()` moves only the matching markers.
- **SC-002** 100 % line coverage, PHPStan, Rector, Igor, CS green; existing suites unchanged.

## Out of scope

- Storing parked session ids (AuthKit seals them in its own vault), cross-firewall parking.
