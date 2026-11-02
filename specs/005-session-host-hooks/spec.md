# Feature Specification: Session host hooks (trusted-device limit, remember-me epoch, host reasons, JSON 401, retention)

**Feature Branch**: `feat/1.10.0`  
**Created**: 2026-11-02  
**Status**: Implemented (Unreleased, target v1.10.0)

**Package**: `nowo-tech/user-kit-bundle`  
**Configuration root**: `nowo_user_kit.sessions`  
**Baseline**: [`../004-sessions/spec.md`](../004-sessions/spec.md)  
**Origin**: a host application (its own session UI, trusted devices, a `link` revocation reason) moves its sessions onto
the kit (kit extraction, follow-up of KIT-41). Every addition is opt-in or keeps the 1.9 default (BC).

---

## Summary

| Unit | Purpose |
| ---- | ------- |
| `Sessions\Model\SessionLimit` (`maxTrustedDevices`, `extra`) | One resolver serves the session and the trusted-device limits; the latter is carried for the host. |
| `Sessions\Epoch\SessionEpochStoreInterface`, `DoctrineSessionEpochStore`, `Epoch\Entity\UserSessionEpoch` | Per-user remember-me epoch (table `user_kit_session_epoch`, or a host store). |
| `Sessions\Epoch\RememberMeEpoch` | Bumps the epoch on revokeAll / revokeOthers; re-issues the kept session's remember-me cookie. |
| `Sessions\Epoch\EpochRememberMeHandler` | Wraps the built-in remember-me handler of every firewall; refuses cookies issued before the epoch. |
| `UserSessionManager` (`limitFor`, `invalidatesRememberMe`, `forgetUser`, `forgetOwner`, `forgetEpoch`, `customReason` / `context` parameters) | Public API additions. |
| `Sessions\Event\UserSessionsRevokedEvent` (`customReason`, `context`, `rememberMeInvalidated`, `reasonCode()`), `UserSessionsDeletedEvent`, `UserSessionsPurgedEvent::$revokedBefore` | Audit. |
| `Sessions\EventListener\SessionRegistryListener` (`early_check.json_response`), `UserRemovalListener` | Early JSON 401; rows of removed users. |
| `Sessions\Command\PurgeSessionsCommand` (`purge.revoked_retention`) | Separate retention of revoked rows. |

---

## Requirements

- **FR-001** `SessionLimit` takes an optional `maxTrustedDevices` (null = unlimited, ≥ 0) and `extra` (string-keyed,
  untouched); `limit.max_trusted_devices` feeds the configured resolver; `UserSessionManager::limitFor($user)` returns
  the resolved limit. The bundle keeps no trusted devices and does not enforce the value (documented for the host).
- **FR-002** `sessions.remember_me_epoch.enabled` (default false): `revokeAll()` and `revokeOthers()` move the user's
  epoch to now, even when no row matched; a remember-me cookie issued before the epoch is refused (signature cookies:
  signed expiry − lifetime, after signature verification; persistent cookies: server-side `lastUsed`, read before the
  refresh). The session kept by the call gets a fresh cookie on the same response when its request carries the
  firewall's cookie. Custom `remember_me.service` handlers are not wrapped. Store: bundle table or
  `remember_me_epoch.store`. The M2 cookie stripping / clearing is unchanged.
- **FR-003** Optional `customReason` (`^[a-z][a-z0-9_]{0,31}$`, not a built-in value, else `InvalidArgumentException`)
  and `context` on `revoke`, `revokeOthers`, `revokeAll`, `revokeDevice`: the reason is stored in `revoked_reason` and
  marks the cache store; the flash is `sessions.signed_out.<reason>` when translated, else the generic message;
  `UserSessionsRevokedEvent` carries `customReason`, `context`, `rememberMeInvalidated`, `reasonCode()`.
- **FR-004** `sessions.early_check.json_response` (default false): an XHR / JSON request of a revoked, missing or idle
  session gets `401 {"error":"session_signed_out"}` from the early check; other requests are unchanged.
- **FR-005** `sessions.purge.revoked_retention` (seconds, default null = unchanged): revoked rows are deleted once
  revoked for that long, live rows keep the idle rule. `UserSessionManager::forgetUser()` / `forgetOwner()` revoke,
  then delete every row and the epoch of a user (`UserSessionsDeletedEvent`); `sessions.user_removal.delete_sessions`
  (default false) calls it after the Doctrine flush that removed a profile user. The eraser also deletes the epoch.
- **FR-006** BC: new constructor and method parameters are optional and appended; new keys default to the 1.9
  behaviour; the epoch table is mapped only when the epoch is enabled with the default store.

## Success criteria

- **SC-001** A copied remember-me cookie signs back in by default and is refused after revokeAll with the epoch on
  (signature and persistent handlers), also when the user's rows were already gone (`SessionHostHooksTest`).
- **SC-002** revokeOthers from the pages and a password change re-issue a working cookie for the kept session only.
- **SC-003** Host reasons are stored, flashed (translated or generic), carried by the event; invalid ones are refused.
- **SC-004** JSON 401 only when enabled and only for JSON / XHR requests.
- **SC-005** Revoked-row retention shorter and longer than the idle rule; user removal deletes rows and epoch only after
  the removing flush; off by default.
- **SC-006** 100% line coverage, PHPStan, Rector, Igor, CS, translations parity green; the 1.9 regression suites
  (`SessionSecurityRegressionTest`, `SessionRecheckRegressionTest`) unchanged and green.

## Out of scope

- Enforcing the trusted-device limit (no trusted-device store in the kit), wrapping custom `remember_me.service`
  handlers, voiding the remember-me cookie of a single revoked session.
