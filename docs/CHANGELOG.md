# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.16.0] - 2026-11-12](#1160---2026-11-12)
- [[1.15.0] - 2026-11-11](#1150---2026-11-11)
- [[1.14.2] - 2026-11-11](#1142---2026-11-11)
- [[1.14.1] - 2026-11-10](#1141---2026-11-10)
- [[1.14.0] - 2026-11-09](#1140---2026-11-09)
- [[1.13.2] - 2026-11-09](#1132---2026-11-09)
- [[1.13.1] - 2026-11-09](#1131---2026-11-09)
- [[1.13.0] - 2026-11-07](#1130---2026-11-07)
- [[1.12.0] - 2026-11-05](#1120---2026-11-05)
- [[1.11.1] - 2026-11-03](#1111---2026-11-03)
- [[1.11.0] - 2026-11-03](#1110---2026-11-03)
- [[1.10.1] - 2026-11-02](#1101---2026-11-02)
- [[1.10.0] - 2026-11-02](#1100---2026-11-02)
- [[1.9.1] - 2026-11-01](#191---2026-11-01)
- [[1.9.0] - 2026-11-01](#190---2026-11-01)
- [[1.8.1] - 2026-10-30](#181---2026-10-30)
- [[1.8.0] - 2026-10-29](#180---2026-10-29)
- [[1.7.0] - 2026-10-28](#170---2026-10-28)
- [[1.6.0] - 2026-10-28](#160---2026-10-28)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.0] - 2026-10-13](#130---2026-10-13)
- [[1.2.0] - 2026-10-13](#120---2026-10-13)
- [[1.1.13] - 2026-10-13](#1113---2026-10-13)
- [[1.1.12] - 2026-10-09](#1112---2026-10-09)
- [[1.1.11] - 2026-09-27](#1111---2026-09-27)
- [[1.1.10] - 2026-09-25](#1110---2026-09-25)
- [[1.1.9] - 2026-08-24](#119---2026-08-24)
- [[1.1.8] - 2026-08-19](#118---2026-08-19)
- [[1.1.7] - 2026-08-18](#117---2026-08-18)
- [[1.1.6] - 2026-08-12](#116---2026-08-12)
- [[1.1.5] - 2026-07-29](#115---2026-07-29)
- [[1.1.4] - 2026-07-22](#114---2026-07-22)
- [[1.1.3] - 2026-07-16](#113---2026-07-16)
- [[1.1.2] - 2026-07-15](#112---2026-07-15)
- [[1.1.1] - 2026-07-14](#111---2026-07-14)
- [[1.1.0] - 2026-07-14](#110---2026-07-14)
- [[1.0.3] - 2026-07-14](#103---2026-07-14)
- [[1.0.2] - 2026-07-14](#102---2026-07-14)
- [[1.0.1] - 2026-07-14](#101---2026-07-14)
- [[1.0.0] - 2026-07-14](#100---2026-07-14)

## [Unreleased]

Target **1.16.1** (patch; security fix, see UPGRADING for the optional column migration).

### Security

- Session registry and remember-me epoch: look-alike owners (house rule LQ). Every owner lookup was SQL only, on the
  host's default collation, which on MySQL / MariaDB folds case and accents (`Alice` = `alice` = `álice`) and, on the
  PAD SPACE collations (MariaDB defaults, `utf8mb4_unicode_ci`, `utf8mb4_general_ci`, `utf8mb4_bin`), ignores trailing
  spaces (`alice ` = `alice`). With string owner ids (a user without a Doctrine id, whose owner id is
  `getUserIdentifier()`, or a Doctrine string id) the session list, the count and the session limit, the eviction,
  revoke-all / revoke-others, the personal-data export and erasure, and the remember-me epoch (read, bump, forget) of
  one owner also reached its look-alikes: revoke-all or an epoch bump for `Alice` signed `alice` out, and the "My
  sessions" page and the export of `Alice` listed `alice`'s sessions (browser, OS, truncated IP, times). Affects
  1.9.0–1.16.0 on MySQL / MariaDB; PostgreSQL and SQLite compare exactly. Fixed in three layers:
  - every statement keyed by the owner adds a byte comparison on MySQL / MariaDB
    (`CAST(user_id AS BINARY) = CAST(:id AS BINARY)`, next to the indexed equality), and every row read back is
    re-checked with `===` (`UserSessionStore::isOwner()`); the session list loads its ids through DBAL, then the
    entities by id;
  - `SessionUser` refuses a type or id with leading or trailing whitespace (Unicode included) or a control, format or
    line / paragraph separator character, or invalid UTF-8 (`SessionUser::isUsable()`); such ids are never trimmed.
    The default `SessionUserResolver` returns null for them (the user is not registered); a custom resolver that builds
    such a `SessionUser` now gets an `InvalidArgumentException`;
  - the new `OwnerCollationListener` gives `user_type` / `user_id` of both tables a binary NO PAD collation in the
    generated schema: `utf8mb4_0900_bin` (MySQL 8.0+), `utf8mb4_nopad_bin` (MariaDB), `utf8mb4_bin` (MySQL 5.7, where
    the byte predicate and the re-check cover PAD SPACE). Existing tables need the `ALTER` in UPGRADING.
  - Until the epoch table is migrated, its unique key still folds look-alikes: the first bump of a look-alike owner
    now throws a `LogicException` instead of moving the other owner's epoch. The session rows are revoked before the
    exception is rethrown (and logged as an error), so a password change or "sign out everywhere" still ends every
    other session; only the remember-me cookies stay valid. **With `remember_me_epoch` enabled on MySQL / MariaDB the
    `ALTER` of the epoch table in UPGRADING is therefore required, not optional.**

### Tests

- `SessionLookalikeOwnerTest` (registry and epoch against look-alike owners, with the columns forced back to the
  host collations `0900_ai_ci` / `uca1400_ai_ci`, `unicode_ci`, `general_ci` and `utf8mb4_bin`; `revokeAll()` with an
  unmigrated epoch table and a look-alike epoch row still revokes every session of the exact owner), run on MySQL 8.4
  and MariaDB 11.4 in CI; unit tests for the id rule, the byte predicate, the re-check and the collation listener.

## [1.16.0] - 2026-11-12

Target **1.16.0** (minor; opt-in, no behaviour change for existing hosts).

### Added

- `sessions.client.context_attributes` (default `[]`): an explicit allowlist of request attribute names (exact,
  case-sensitive) whose **raw** value, objects included, a `DeviceIdProviderInterface` reads through the new
  `DeviceContext::attribute(string): mixed` / `hasAttribute(string): bool` (for example DeviceIntelligence's `_device`
  attribute). Nothing is copied unless the host names it. The values are kept outside the context's properties (an
  internal `WeakMap` keyed by the context), so VarDumper, `var_export()`, `(array)` and `json_encode()` never show
  them; a clone has none, and `DeviceContext` still refuses serialization. The context is built per call
  (REQ-RUNTIME-002). `SessionClientFactory::deviceContext()` takes the names as a new optional second argument. Entries
  must be strings: anything else fails the container build (an int name used to cause a `TypeError`, a 500 on sign-in;
  audit MU-U-I1). With the default empty list the context carries no attribute, as in 1.15.0.

### Security

- `DeviceContext` no longer keeps header and cookie values in private properties: VarDumper / `dump()`,
  `var_export()` and `(array)` showed them in 1.15 (including headers such as `X-Api-Key`), although `__debugInfo()`
  and the docs said names only. The values now live in the same internal `WeakMap` holder as the allowlisted
  attributes, and `__debugInfo()` builds the names from it (audit MU-U-L1). Behaviour change: a `clone` of a
  `DeviceContext` has no headers, cookies or attributes.
- The remember-me cookies (the cookie of every firewall's remember-me handler, read by `SessionsPass`, plus
  `sessions.remember_me_cookies`) are no longer copied into the `DeviceContext` given to a `DeviceIdProviderInterface`:
  they are credentials, never device data (audit MU-U-L2). `SessionClientFactory::deviceContext()` takes the names to
  leave out as a new optional third argument.

[1.16.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.16.0

## [1.15.0] - 2026-11-11

### Added

- **Framework-free session ports (REQ-ARCH-001 rule 3).** `Sessions\SessionHashesInterface::sessionHash(SessionRef|string|null)`
  (aliased to `UserSessionManager`) and the `Sessions\Model\SessionRef` value object, built at the HTTP edge by
  `UserSessionManager::sessionRef(Request|SessionInterface|null)`; `SessionRef` hides the session id from dumps and
  refuses serialization and unserialization. `sessionHash()` returns null for null and for an empty id, and its
  argument is a `#[SensitiveParameter]`. `Sessions\Client\DeviceIdProviderInterface::deviceIdFor(DeviceContext)`
  with the `Sessions\Model\DeviceContext` value object (client IP, user agent, headers, cookies; dumps show names
  only; never serialized), accepted by `sessions.client.device_id_resolver`. The context never carries the session
  cookie nor the `Authorization`, `Cookie` and `Proxy-Authorization` headers, and a numeric header name is accepted
  (audit MO).
- **XML mapping of the `UiPreferences` / `ProductTourProgress` embeddables (REQ-ARCH-001 rule 2)** in
  `src/Resources/config/doctrine/model`, opt-in for DDD / XML-mapped hosts, plus the field list of every trait for
  hosts that map them in XML (USAGE).
- USAGE "Standard Symfony host" and "DDD host" sections; a DDD-host kernel test (XML-mapped aggregate without
  attributes, base class or trait; non-entity security user; ports only).

### Changed

- The bundle's own listeners and session pages use `sessionHash(sessionRef(...))` instead of `currentHash()`; the
  hashes, the kept session of a password change and "sign out other sessions", and the revocation semantics are
  unchanged (old-vs-new equivalence tests: `SessionRefEquivalenceTest`, `ClientAndLimitTest`).

### Deprecated

- `UserSessionsInterface::currentHash(Request|SessionInterface|null)` / `UserSessionManager::currentHash()`: use
  `sessionHash(sessionRef($request))`. Removed in 2.0, where `UserSessionsInterface` extends `SessionHashesInterface`.
- `Sessions\Client\DeviceIdResolverInterface` (`deviceId(Request)`): implement `DeviceIdProviderInterface`. Removed
  in 2.0.

[1.15.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.15.0

## [1.14.2] - 2026-11-11

### Security

- **Sessions: with `sessions.remember_me_epoch.enabled`, a revocation's kept session leaked into later requests in
  FrankenPHP worker mode with the kernel kept alive and not reset (1.10.0 – 1.14.1).** `RememberMeEpoch` remembered
  the session kept by a revocation of the current request, and only `kernel.reset` emptied it. After "sign out this
  device" with `invalidateRememberMe`, a later request of another session of the same user in that worker got a fresh
  remember-me cookie, undoing the invalidation for that browser. The memo is now emptied at the end of the main
  `kernel.response` and at the main `kernel.request`, `kernel.terminate`, `console.terminate` and the Messenger worker
  events; `kernel.reset` still empties it (REQ-RUNTIME-002). Tests: `RememberMeEpochWorkerTest` (one kernel, no
  reset, alice and bob alternating, 1,000 requests, memory margin) and `HostHooksTest`. Worker audit: W-04 in
  `docs/FRANKENPHP-WORKER-AUDIT.md`.
- **Sessions: a password change whose flush failed signed the user out on a later flush (1.9.0 – 1.14.1).**
  `PasswordChangeListener` kept the user from `preUpdate` until `postFlush`, and only `kernel.reset` emptied it
  otherwise. When the flush failed, the next flush of any user (in a FrankenPHP worker with the kernel not reset, any
  later request) signed out every other session of that user although the new password was never stored. Pending
  changes are now kept per entity manager and unit of work, dropped by the `onFlush` of a new unit of work, `onClear`
  and the request / console / Messenger boundaries; a nested flush of the same unit of work keeps them (audit MD-U-M1,
  worker audit W-05).
- **Sessions: with `user_removal.delete_sessions` and several entity managers mapping the user class, a pending removal
  could delete the sessions of a user that was not removed (1.10.0 – 1.14.1).** `UserRemovalListener` now keeps
  pending removals per entity manager and unit of work, applies them only on that manager's `postFlush`, and clears
  them at the request / console / Messenger boundaries (audit MD-U-L1, worker audit W-06). Test:
  `SessionListenersWorkerTest`.

[1.14.2]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.14.2

## [1.14.1] - 2026-11-10

Last-activity tracking writes only the user's changed columns and no longer flushes the host's entity manager. See [UPGRADING](https://github.com/nowo-tech/UserKitBundle/blob/v1.14.1/docs/UPGRADING.md#to-1141).

### Security

- **Last activity no longer flushes the host's EntityManager (rule 6).** When the profile's `last_activity.field` was
  not a mapped column (a `LastActivityInterface` setter or property path that writes another property),
  `LastActivitySubscriber` fell back to a full `flush()` of the user's manager on `kernel.request`, which committed
  every pending change of the unit of work, including unrelated host entities and edits of the user itself. It now
  writes only the user's row: the mapped columns that the setter changed, with a DQL `UPDATE` by identifier, and marks
  them as the original values. A user not persisted yet, or a setter that changes no mapped column, is not written.
  Affects `>= 1.0.0, < 1.14.1` with `last_activity.enabled`: every configuration before 1.6.0 (every update was a
  full flush), and from 1.6.0 an unmapped field.

### Fixed

- A closed EntityManager is reset only when it is the manager of the user class; other closed managers of the
  registry are left alone.

[1.14.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.14.1

## [1.14.0] - 2026-11-09

Target 1.14.0.

### Added

- **Session owner port** (`Sessions\Owner\SessionOwnerResolverInterface`, config `sessions.owner.resolver`): maps a user
  object to the owner its registry rows and remember-me epoch are stored under. The default is `SessionUserResolver`,
  unchanged (Doctrine root class + id, else class + user identifier). A host whose security user is a non-entity adapter
  around a domain entity maps both to one owner, so `password_change`, `user_removal` and
  `invalidate_sessions_on_disable` reach the rows written at sign-in. Every internal consumer (`UserSessionManager`,
  `RememberMeEpoch`, `EpochRememberMeHandler`) uses the interface.
- **Revocations no longer miss silently** (`sessions.owner.on_unresolved: log|exception`, default `log`): `revoke()`,
  `revokeOthers()`, `revokeAll()`, `revokeDevice()`, `forgetUser()`, the password-change and user-removal listeners and the
  registry session invalidator log a warning when the user has no owner; with `exception` they throw
  `Sessions\Owner\UnresolvedSessionOwnerException` (the Doctrine listeners before the write). New
  `UserSessionManager::revocationOwner()`.
- **Ports for application services**, aliased to the existing final classes: `Sessions\UserSessionsInterface`
  (`UserSessionManager`), `PersonalData\PersonalDataExporterInterface`, `PersonalData\PersonalDataErasureInterface`
  (`PersonalDataEraser`) and `Presence\UserPresenceResolverInterface`.
- `UserSessionManager::listRowsFor()`: the live sessions as `SessionRow` DTOs (no ORM entity leaves the bundle).

### Changed

- `PersonalDataEraser::erase()` joins a transaction the host already opened on the user's entity manager (flush once;
  commit, rollback and closing the entity manager stay with the host) instead of nesting its own. Without an open
  transaction nothing changes. If an eraser fails there, the host transaction is marked rollback-only before the
  exception goes on, so a partial erasure cannot be committed.

### Documentation

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

[1.14.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.14.0

## [1.13.2] - 2026-11-09

Patch release (target 1.13.2).

### Security

- **IY-L1 (Low): the touch fallback read an old snapshot inside a host transaction on MySQL.** Since 1.13.1, when the
  registry touch's `UPDATE` affects 0 rows, a `SELECT` checks whether the live row exists. Under MySQL/MariaDB
  `REPEATABLE READ`, inside a transaction the host opened and already read in, that `SELECT` reused the old snapshot,
  so a session revoked after it read as live (until the next request, at most `touch_interval`). The fallback is now a
  locking read on MySQL (`FOR SHARE`) and MariaDB / MySQL 5.7 (`LOCK IN SHARE MODE`), chosen from the DBAL platform; it
  reads the latest committed version, as the `UPDATE` does. PostgreSQL and SQLite are unchanged. The auditor's PoC is a
  regression test (`SessionTouchSnapshotTest`) in the MySQL and PostgreSQL CI job. No configuration or schema change.

[1.13.2]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.13.2

## [1.13.1] - 2026-11-09

Patch release (target 1.13.1).

### Fixed

- **Parallel requests of one session signed the user out on MySQL.** The registry touch (`UPDATE … SET last_seen_at`
  every `touch_interval`) read 0 affected rows as "the session is gone", then deleted the row and signed the user out.
  MySQL reports 0 affected rows for an UPDATE that writes the values the row already holds (without
  `CLIENT_FOUND_ROWS`), so two requests of the same session touching in the same second ended it. On 0 affected rows
  `UserSessionStore::touch()` now looks the owner's live row up again with a `SELECT`: the session ends only when that
  row is really missing or revoked, so a deleted, purged or revoked session still signs out (no fail-open). SQLite and
  PostgreSQL count matched rows and were not affected. The `real-databases` CI job now runs the registry touch tests on
  MySQL (without `MYSQL_ATTR_FOUND_ROWS`) and PostgreSQL. The other affected-row reads of the bundle were checked:
  `deleteLiveRow()` counts deleted rows (exact on every platform), and the other writes (`markRevoked()`, `revoke()`,
  `rekey()`, the remember-me epoch `bump()`) do not read the count.

[1.13.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.13.1

## [1.13.0] - 2026-11-07

REQ-SEC-004: Pass (good) / Low, independent audit, Appendix HU (`2f9db59`). See [UPGRADING](UPGRADING.md#to-1130).

### Added

- **`UserSessionManager::adopt(Request $request, object $user)`** (GY-L7, HP-I2). Registers the request's session at
  once for `$user`, in the request's firewall context, for a sign-in that failed and handed the session back to the
  earlier account: no limit, no eviction, no `atLogin: true` event. A session revoked before the sign-in deleted its row
  comes back registered as revoked with that reason, and its next request signs it out; a row a rollback brought back
  follows the new session id instead of staying behind. Needs a matching call in AuthKit's
  `SocialHandlerSignInRollback::restore()`, which this release does not ship.
- **`UserSessionManager::releaseHeldRevocations()`.** Applies at once what committed transactions left waiting, for a
  job or script with no request, command or Messenger boundary. While a transaction is open nothing runs.

### Changed

- **Inside a database transaction, what a rollback cannot undo waits for the outermost commit (HP-L2).** The mark in
  the `cache` revocation store, for every reason, and `UserSessionsRevokedEvent` for `password_changed`. A rollback
  drops them, so a rolled-back password change no longer signs the user's other sessions out or tells listeners a
  password changed. Other reasons keep firing their event at once, inside the transaction: their listeners take part in
  it. With no transaction open nothing changes. A DBAL driver middleware (`doctrine.middleware`, every connection, only
  with `sessions.enabled`) watches the outermost transaction; it changes no statement or result.
- **A password-change listener can no longer be undone by a later transaction.** Effects of a committed transaction
  wait while another transaction is open, and run at the next boundary after it.
- With the registry on another connection than the users, a password change inside a transaction on the users'
  connection revokes after that transaction commits, rows included.

### Security

- A failed sign-in over an existing session can no longer leave that session outside the registry until its next
  stateful request, once AuthKit makes the call above (GY-L7).
  Open (Low): a revocation landing after the sign-in deleted the row and before `adopt()`, with no transaction holding
  that row, misses the session. It ends at the next sign-out everywhere or at the idle lifetime, not at the registry
  touch. `linkAndSignIn()` holds the row, so a revocation waits for it and is not missed. See
  [SECURITY](SECURITY.md#threat-model).
  Open (Low): a revocation that read the row before a session rekey and updates after it matches nothing, yet counts the
  session as ended. Never seen unforced; the next sign-out everywhere ends it.
- A rolled-back password change no longer ends the user's other sessions with `revocation.store: cache` (HP-L2).

[1.13.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.13.0

## [1.12.0] - 2026-11-05

Minor release. See [UPGRADING](UPGRADING.md#to-1120).

### Added

- **Original identifier on erasure (DATA-AUTHKIT-VAULT-ERASE).** `ErasureRequest::$originalIdentifier` (optional 4th
  constructor argument) and `ErasureRequest::identifier()`: `PersonalDataEraser::erase()` / `check()` capture
  `UserInterface::getUserIdentifier()` before any eraser runs, so erasers that run after an identity eraser
  anonymised the e-mail still find data keyed by it. The `sessions` eraser passes it on as
  `context['original_identifier']` (`SessionsEraser::CONTEXT_ORIGINAL_IDENTIFIER`) of the `UserSessionsRevokedEvent`
  it triggers, so AuthKit removes the person from every browser's account switcher.

[1.12.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.12.0

## [1.11.1] - 2026-11-03

Revoked sessions stay revoked after purge

### Fixed

- `UserSessionManager::sessionState()` / `sessionStates()` consult the revocation store as the early check does
  (audit DE-M1): with the database store a session whose row is gone (e.g. revoked, then deleted by the retention
  purge) is `revoked` (`signed_out`) instead of `unknown`, so a caller can never treat it as resumable; with the cache
  store a revocation marker that outlives its row still answers `revoked`. `unknown` remains for an empty id, a user
  that cannot be registered, or (cache store) a missing row without a marker.

[1.11.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.11.1

## [1.11.0] - 2026-11-03

Parked sessions API and pending sign-ins for MFA

### Added

- Sessions API for sessions that are not the request's own (spec 006, for AuthKitBundle 1.34 multi-account sign-in):
  `UserSessionManager::sessionState()` / `sessionStates()` (live, revoked with its reason, expired, unknown — one
  query per owner), `endSession()` (signs a parked session out like a logout: the owner's row is deleted) and
  `rekey()` (moves the rows of a session's markers to its new id after a `migrate()`); value objects
  `Sessions\Model\SessionState` / `SessionStatus`; `UserSessionStore::statesOf()`. No behaviour change, no schema
  change.
- Pending sign-ins (spec 006, for AuthKitBundle 1.34 MFA): a token implementing
  `Security\PendingAuthenticationTokenInterface`, or carrying its attribute `nowo.authentication_pending` set to
  `true` (`Security\PendingAuthentication::isPending()`), is not registered at `LoginSuccessEvent` and nothing is
  touched while it lasts; the session is registered as a sign-in (concurrent-session limit included,
  `UserSessionStartedEvent::$atLogin` true) on the first request after the token became final. `reject_new` is
  checked again at that point (parallel pending sign-ins cannot exceed the limit; the over-limit browser is signed
  out with the limit message and `SessionLimitReachedEvent`), the attribute counts only on a role-less token, and a
  sign-in never counts or evicts its own session (audit DC-M1, DC-L1, DC-I1).

### Fixed

- `UserSessionManager::listFor()` (and the session pages) no longer trigger doctrine/orm 3.7's deprecation of string
  sort directions: `SortDirection::Descending` is used where it exists (PHP 8.6 / symfony/polyfill-php86), `'DESC'`
  otherwise.

[1.11.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.11.0

## [1.10.1] - 2026-11-02

remember-me epoch re-issue hardening (CQ N1, N2, F4 docs)

### Security

- Remember-me epoch (audit CQ re-verification on 1.10.0):
  - N1 (Info): the kept session's pending re-issue marker is now dropped only once its cookie has actually been re-issued (or on another user's token). A first request on another firewall context, without the user's token, on a firewall without remember-me or outside the cookie's path no longer loses it.
  - N2 (Info): a sign-in in the same second as a revocation got a cookie that was already refused; such a cookie (issued while the user's epoch is still ahead) is now re-issued on the session's first request at or after the epoch second. Interactive sign-ins only: a cookie re-issued by a remember-me auto-login is never deferred, so a revoked cookie racing the revocation cannot obtain a fresh one.
- Docs: SECURITY.md states the exact window of the partial `lifetime` cut residual (CQ F4) and recommends rotating `remember_me.secret` after an incident.

[1.10.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.10.1

## [1.10.0] - 2026-11-02

Sessions host API: SessionLimit max_trusted_devices / extra, opt-in remember-me epoch (revokeAll / revokeOthers, and revoke / revokeDevice with invalidateRememberMe), host revocation reasons + context, early-check JSON 401, purge.revoked_retention, forgetUser / forgetOwner and user_removal.delete_sessions. See [UPGRADING](https://github.com/nowo-tech/UserKitBundle/blob/v1.10.0/docs/UPGRADING.md#to-1100).

Session host hooks for hosts with their own session UI (spec [005](../specs/005-session-host-hooks/spec.md)). Every
addition is opt-in or keeps the 1.9 default. See [UPGRADING](UPGRADING.md#unreleased).

### Added

- `SessionLimit::$maxTrustedDevices` (`limit.max_trusted_devices`) and `SessionLimit::$extra`, carried for the host
  (not enforced: UserKit keeps no trusted devices); `UserSessionManager::limitFor($user)`.
- Remember-me epoch (`sessions.remember_me_epoch.enabled`, default off): `revokeAll()` / `revokeOthers()` refuse every
  remember-me cookie issued before them, also for browsers whose registry row is already gone. The built-in signature
  and persistent handlers of every firewall are wrapped (`EpochRememberMeHandler`); the epoch is the next whole second,
  and the kept session gets a fresh cookie from then on. `revoke()` / `revokeDevice()` take an opt-in
  `invalidateRememberMe` (the revoked device's cookie-only replay is refused; the user's other devices sign in again
  when their sessions expire). Epoch store `SessionEpochStoreInterface` (default table `user_kit_session_epoch`, or
  `remember_me_epoch.store`). `UserSessionManager::invalidatesRememberMe()`.
- Host-defined revocation reasons: optional `customReason` (`^[a-z][a-z0-9_]{0,31}\z`, not a built-in value) and
  `context` on `revoke()`, `revokeOthers()`, `revokeAll()`, `revokeDevice()`; stored in `revoked_reason`, flash
  `sessions.signed_out.<custom>` when the host translates it. `UserSessionsRevokedEvent` gains `customReason`,
  `context`, `rememberMeInvalidated` and `reasonCode()`.
- `sessions.early_check.json_response` (default off): XHR / JSON requests of a revoked, missing or idle session get
  `401 {"error":"session_signed_out"}` from the early check.
- `sessions.purge.revoked_retention` (default null = unchanged): revoked rows follow their own retention in
  `nowo:user-kit:sessions:purge`; `UserSessionsPurgedEvent::$revokedBefore`.
- User deletion: `UserSessionManager::forgetUser()` / `forgetOwner()` (revoke, delete the rows, bump the epoch,
  `UserSessionsRevokedEvent` + `UserSessionsDeletedEvent`), `forgetEpoch()` once the user is gone, and `sessions.user_removal.delete_sessions` (default off) to call it after a Doctrine
  removal of a profile user (`UserRemovalListener`).

### Security

- Fixes of the REQ-SEC-004 delta audit (Appendix CQ) before the tag: F1 opt-in `invalidateRememberMe`, F2 the epoch of
  a live user is never deleted, F3 next-second epoch with deferred re-issue, F4 cookies signed under a longer lifetime
  are refused once an epoch exists, I1 no trailing newline in custom reasons, I2 epoch bumped before the rows, I3
  `forgetOwner()` dispatches the revoked event, I4 `no-store` on the JSON 401.

[1.10.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.10.0

## [1.9.1] - 2026-11-01

twig/extra-bundle and string-extra required, demo e2e and screenshots for sessions, Spec Kit inventory, PHPUnit 11.5 schema See [UPGRADING](UPGRADING.md#to-191).

### Changed

- `twig/extra-bundle` and `twig/string-extra` (`^3.12`) are declared in `require` (REQ-TWIG-004): the bundle ships Twig
  views since 1.9.0. Both were already installed through UiKit. New `make check-twig-extra` gate
  (`.scripts/check-twig-extra.sh`), part of `make release-check`; INSTALLATION documents the Twig Extra stack.
- `phpunit/phpunit` dev constraint raised to `^11.5`; `phpunit.xml.dist` uses the 11.5 schema and
  `ignoreIndirectDeprecations` (was `restrictDeprecations`), which removes the PHPUnit runner deprecation ("OK, but
  there were issues") reported in every CI cell.

### Added

- Demo Playwright e2e (REQ-DEMO-013): `demo/symfony8/e2e` — login, "My sessions", sign out the session of a second
  browser context (signed out on its next request). `make test-e2e` / `make demo-screenshots` (root, `demo/` and
  `demo/symfony8`), Playwright image pinned to `@playwright/test` 1.64.1; `test-e2e` closes `make release-check`.
- README screenshots `docs/images/demo/my-sessions.webp` and `sign-out-session.webp` (WebP, demo shell + profiler).
- `Demo smoke` workflow (`demo-smoke.yml`): HTTP 200 smoke and the Playwright e2e on tags, weekly and on demand;
  `make demo-smoke` now boots the demo and checks `/` (REQ-TEST-011).

### Fixed

- Demo: a fresh checkout started FrankenPHP worker mode without `vendor/` and the container exited; the entrypoint
  now runs `composer install` and the schema update first, and `make up` waits for the server.
- Demo: the session pages render in the demo shell (`nowo_ui_kit.layouts.admin` → `layouts/admin.html.twig`,
  Bootstrap 5 + Bootstrap Icons), so the row "Sign out" icon is visible; navbar link to "My sessions".
- UPGRADING: duplicate "To 1.9.0" heading removed.

### Documentation

- Spec Kit baseline (REQ-SPECKIT-003): `code-inventory.md` maps the 44 session-registry files under a new Sessions
  category (FR-SES-003 … FR-SES-010 = 004 FR-001 … FR-008); total 105 production sources, Last audited 2026-11-01.
  `spec.md` and `docs/SPEC-DRIVEN-DEVELOPMENT.md` describe the session registry scope.

[1.9.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-11-01

Session registry and limits (sessions feature, opt-in): list/revoke sessions and devices, concurrent-session limit, RGPD minimisation See [UPGRADING](UPGRADING.md#to-190).

### Added

- **Session registry and limits** (`nowo_user_kit.sessions`, opt-in, off by default): one `user_kit_session` row per
  signed-in (stateful) session — HMAC of the session id only, browser / OS names, truncated (or hashed, or no) IP,
  optional host device id (`DeviceIdResolverInterface`) — registered at sign-in, touched at most once per
  `touch_interval`, deleted at logout; `Sessions\UserSessionManager` API (`listFor`, `revoke`, `revokeOthers`,
  `revokeAll`, `revokeDevice`). A revoked, missing or idle session is signed out on its **next request** with any session
  handler (`revocation.store`: `database` or a shared `cache` pool): session invalidated, remember-me cookies dropped,
  "You were signed out because…" flash.
- Concurrent-session limit (`sessions.limit.max_sessions`, `policy: evict_oldest | reject_new`, or a host
  `SessionLimitResolverInterface`); `reject_new` fails the sign-in with `SessionLimitReachedException` after the
  credentials check.
- Automatic sign-outs: password change of a profile user (other sessions; `sessions.password_change`), account disabled
  (`RegistrySessionInvalidator` becomes the default `SessionInvalidatorInterface`).
- Pages (`sessions.web_ui.enabled`, routes only through the `nowo_user_kit_sessions` loader): "My sessions" (sign out one,
  sign out all others) and an administration page (one user's sessions, sign out one, sign out everywhere) on the
  UiKit list scaffold with FormKit CSRF-only actions (one token per action and row) and UiKit confirm dialogs; REQ-UI-002
  `security.access_roles` / `manage_roles` / `access_checker` / `allow_unauthenticated`; Twig
  `user_kit_sessions_admin_path(user)`; FormKit profile `user_kit`; translations in `de`, `en`, `es`, `fr`, `it`, `nl`,
  `pt`.
- Audit events `UserSessionStartedEvent`, `UserSessionsRevokedEvent`, `SessionLimitReachedEvent`,
  `UserSessionsPurgedEvent`; retention command `nowo:user-kit:sessions:purge`; `sessions` personal data export section
  and eraser; `sessions.doctrine.table_prefix` (REQ-ORM-002).

### Security

Fixes of the independent REQ-SEC-004 audit of the session registry (Appendix CC, 2026-11-01), each with a regression
test (`SessionSecurityRegressionTest`):

- **M1** — `_stateless` routes of a stateful firewall skipped the revocation and idle checks while the firewall still
  authenticated them from the session: the early check now runs there too (without counting as a session use).
- **M2** — the remember-me cookie name / path / domain were hard-coded (`REMEMBERME`, `/`): the cookie of every
  firewall is now read from its `remember_me` configuration and cleared with its name, path, domain, secure and
  SameSite; `sessions.remember_me_cookies` (default now `[]`) only adds extra cookies.
- **L1** — the administration CSRF token ids are bound to the user (see N3 for the final format): a "Sign out
  everywhere" token of one user no longer signs out another.
- **L2** — the `cache` store no longer deletes the revocation marker on sign-out (it expires with its TTL), so session
  data written back under the old id is still refused.
- **L3** — `revocation.cache_pool` has no default and is required with `store: cache`; per-server pools (filesystem
  — `cache.app` —, array, APCu, PHP files, null) are refused at compile time.
- **L4** — IPv4-mapped / IPv4-compatible IPv6 addresses are truncated (or hashed) as IPv4.
- **L5** — one marker per firewall context and one row per (session, owner) (unique key on `session_hash`,
  `user_type`, `user_id`): a sign-in on a second firewall in the same session no longer takes over the first owner's
  row; revoking any row of a session ends the whole session.
- **N1** (re-verification) — a firewall with a custom `remember_me.service` had no cookie collected, so a revoked
  browser logged back in: the cookie name is now read from the firewall's remember-me authenticator, the request is
  stripped of it and the custom handler clears it (`clearRememberMeCookie()`); a cookie that cannot be resolved fails
  the compilation and asks for `sessions.remember_me_cookies`.
- **N2** (re-verification) — the shared-pool check unwraps `TagAwareAdapter`, `ProxyAdapter`, `TraceableAdapter` and
  `ChainAdapter` and refuses the pool when every tier is per-server (a tag-aware filesystem pool, an array + filesystem
  chain…).
- **N3** (re-verification) — the administration token ids use `/` as separator (`…_admin_revoke_all/<profile>/<user>`,
  `…_admin_revoke/<profile>/<user>/<row>`), so profile or user ids containing `_` cannot collide.
- **N4** (re-verification) — the late sign-out redirect is built from the base URL + `/` + the path without leading
  slashes / backslashes + the query, so a request to `//evil.example/x` (host catch-all route) redirects to
  `/evil.example/x`, never to a protocol-relative URL. The idle bound is now inclusive (`>=`), closing a one-second edge
  with the cache marker TTL.
- `{user}` of the administration routes must equal the identifier exactly (`2abc` → 404); a user the actor may not
  manage is a 404 like an unknown one; anonymous requests are denied before any lookup.
- Templates receive `Sessions\Ui\SessionRow` view models instead of the entity and `current_hash` (the session hash
  never reaches a template); the late sign-out redirect is path-only (no `Host` header); the demo binds to
  `127.0.0.1`; SECURITY.md documents the residual risks (copied remember-me cookie, concurrent sign-ins, `reject_new`
  lock-out, manager vs. administrators, cache-store delay).

### Changed

- `composer.json` now requires `nowo-tech/ui-kit-bundle` ^2.20, `nowo-tech/form-kit-bundle` ^2.17, `symfony/twig-bundle`,
  `symfony/form`, `symfony/security-csrf`, `symfony/routing`, `symfony/console` and `doctrine/dbal` (REQ-TWIG-005 rule 11:
  the bundle now ships pages). Nothing is registered or changed for hosts that keep `sessions.enabled: false`.

[1.9.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.9.0

## [1.8.1] - 2026-10-30

ResetInterface on ProfileRegistry and LastActivitySubscriber (worker mode), PHPDoc See [UPGRADING](UPGRADING.md#to-181).

### Changed

- **Worker reset (REQ-CS-006):** `ProfileRegistry` and `LastActivitySubscriber` implement `Symfony\Contracts\Service\ResetInterface` (autoconfigured `kernel.reset`). `ProfileRegistry::reset()` clears the per-class resolution memo; `LastActivitySubscriber::reset()` evicts only the throttle windows that already expired, so `update_throttle` keeps working across requests of a FrankenPHP worker. `phpstan.neon.dist` now has `ignoreErrors: []` (the two `frankenphp.worker.noMissingResetInterface` ignores are gone).
- `symfony/service-contracts` (`^2.5 || ^3.0`) is now a direct requirement (it was already installed through Symfony).

### Documentation

- PHPDoc descriptions on every public class and public method in `src/` (REQ-CS-001).
- `docs/FRANKENPHP-WORKER-AUDIT.md` updated for the new `reset()` methods.

[1.8.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.8.1

## [1.8.0] - 2026-10-29

UserKit: ContentWidth gains wider. See [UPGRADING](UPGRADING.md#to-180).

### Added

- `ContentWidth::Wider` (`wider`): an extra-wide centered content width between `wide` and `full` for
  `preferred_content_width` (default stays `content`). Hosts size it with CSS on `data-content-width`.

### Changed

- Developer tooling: Rector targets PHP 8.2, the declared `php` floor (REQ-CS-003, REQ-SF-001). Rector's PHP 8.2 rules made the final services / value objects `readonly` classes (no API change).

[1.8.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-28

UserKit: ContentWidth gains narrow and wide. See [UPGRADING](UPGRADING.md#to-170).

### Added

- `ContentWidth::Narrow` (`narrow`) and `ContentWidth::Wide` (`wide`): more centered content widths for
  `preferred_content_width` (default stays `content`). Hosts size them with CSS on `data-content-width`.

[1.7.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.7.0

## [1.6.0] - 2026-10-28

UserKit: last activity is stored with a targeted UPDATE instead of a full flush on every request. See [UPGRADING](UPGRADING.md#to-160).

### Fixed

- **Last activity no longer flushes the whole unit of work.** `LastActivitySubscriber` called `EntityManager::flush()` on
  `kernel.request`, persisting any pending change of any managed entity (and costing a full change-set computation per
  request). It now writes only the timestamp column with a DQL `UPDATE … WHERE <identifier>` and records the value as
  the original one, so a later flush does not write it again. A field that is not a mapped column still falls back to
  `flush()`.

### Added

- Developer tooling: `make check-no-raw-html-form` fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Demo: the login page renders through a Symfony Form Type (`App\Form\LoginType`: flat `_username` / `_password`, `_csrf_token` with token id `authenticate`) with `form_start` / child loop / `form_end` instead of hand-rolled inputs (REQ-TWIG-005 rule 8).
- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `validate-translations`, `coverage-check`, demo release-check); the demo release-check no longer swallows failures.
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README documentation list order, canonical issue templates, CONTRIBUTING Cursor Agent settings (REQ-IDE-006), tables of contents, SPEC-DRIVEN-DEVELOPMENT user stories, 002/003 scope and Engram relationship, commented demo `.env.example` and `.gitignore`, demo compose project name `user-kit-bundle-demo-symfony-8`, Spec Kit inventory refresh (61/61 incl. UI preferences, product tour and personal data).

[1.6.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.6.0

## [1.5.0] - 2026-10-13

### Added

- **GDPR self-service SPI** (spec `003-personal-data`, opt-in with `nowo_user_kit.personal_data.enabled`):
  - `PersonalDataExportProviderInterface`, `PersonalDataEraserInterface` and `ErasureGuardInterface`, autoconfigured with the `nowo_user_kit.personal_data.export_provider` / `.eraser` / `.erasure_guard` tags and ordered by tag priority.
  - `PersonalDataExporter`: versioned document `{schema, version, exported_at, sections}` (`schema` / `version` configurable), dates as ATOM, enums as values, duplicate section keys rejected; `exportJson()`.
  - `PersonalDataEraser`: `check()` pre-flight and `erase()` — guards first (`ErasureVetoedException` with every `ErasureVeto`), then all supporting erasers in one Doctrine transaction, flushed once. `ErasureRequest` (user, actor, context).
  - Built-in sections `account_status`, `last_activity`, `ui_preferences`, `product_tour` and erasers `last_activity`, `ui_preferences`, `product_tour` (`builtin_providers` / `builtin_erasers` toggles).
- `LastActivityTrait::clearLastActivityAt()`.

[1.5.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.5.0

## [1.4.0] - 2026-10-13

### Added

- **UI preferences** (spec `002-ui-preferences`): `Model\UiPreferencesTrait` + `UiPreferencesInterface` + ready-made `Model\UiPreferences` embeddable with the generic display columns `preferred_locale`, `preferred_theme`, `preferred_content_width`, `preferred_ui_density`, `preferred_motion`, `preferred_font_scale`, `preferred_contrast`, `preferred_sidebar`, `preferred_footer` (explicit names, nullable strings, no DB default). Backed enums under `Model\Preference\` (`Theme`, `ContentWidth`, `UiDensity`, `Motion`, `FontScale`, `Contrast`, `SidebarMode`, `FooterMode`) with `DEFAULT`, `values()` and `normalize()`. Setters accept the enum or a raw string (unknown values stored as null); `applyMissingUiPreferences()` and `resetUiPreferences()`.
- **Product tour progress**: `Model\ProductTourProgressTrait` + `ProductTourProgressInterface` + `Model\ProductTourProgress` embeddable (`product_tour_seen_at`, `product_tour_seen_pages`) with `markPageSeen()`, `hasSeenPage()`, `getEnabledPages()`, `syncEnabledPages()`, `markProductTourSeen()`, `clearProductTourSeen()`; the tour catalog is passed per call or declared by overriding `productTourPages()`.
- Twig `user_ui_html_attributes(subject, {resolved: false})` and the `Preference\UiPreferencesHtmlAttributes` service: `<html>` `data-theme` / `data-user-*` / `data-footer-fixed` attributes for a pre-paint theme boot script.
- `Model\ModelResolver` to find the preference models on a user (`getUiPreferences()`, `getProductTourProgress()`).

### Changed

- `NowoUserKitExtension::findAuthKitUserClass()` is now an instance method (behaviour-neutral Rector fix).
- `igor.json` excludes `src/Model/` (entity traits / embeddables / enums, never container services) from the worker-state audit.

[1.4.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.4.0

## [1.3.0] - 2026-10-13

### Fixed

- AuthKit bridge: when the default profile omits `user_class`, UserKit now reads it from the raw `nowo_auth_kit` configuration in `prepend()` (flat `user_class` or the AuthKit default profile), so it no longer depends on AuthKitBundle being registered before UserKitBundle in `config/bundles.php`. The `nowo_auth_kit.user_class` parameter remains a fallback.

### Changed

- Minimum Symfony version raised from 7.0 to **7.4** in `composer.json` (`^7.4 || ^8.0`), matching the documented requirement and the lowest version tested in CI; the Symfony 7.0 CI cell was removed.

[1.3.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-10-13

### Security

- `AccountStatusUserChecker` is now wired into every security-enabled firewall (`security.user_checker.<firewall>` + per-firewall chain) by a compiler pass. Previously it was tagged `security.user_checker`, which SecurityBundle never consumes, so disabled accounts could still log in unless the firewall set `user_checker` manually. A custom firewall `user_checker` is kept in the chain and still runs.

### Fixed

- `invalidate_sessions_on_disable: true` now attaches `AccountDisabledListener` as a Doctrine entity listener on each opted-in profile `user_class` (it was tagged with `entity: null` and never attached).

### Changed

- Docs: session invalidation section describes the real behaviour (Doctrine `postUpdate`; `DefaultSessionInvalidator` is a no-op) and how to plug a real invalidator.

[1.2.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.2.0

## [1.1.13] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.

### Dependencies

- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #20).

[1.1.13]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.13

## [1.1.12] - 2026-10-09

### Changed

- PHPStan: `frankenphp.worker.noMissingResetInterface` ignored (with justification) for `ProfileRegistry` (class → profile resolve cache) and `LastActivitySubscriber` (bounded per-worker write throttle); both are intentional cross-request state now detected by phpstan-frankenphp 1.2.2+.
- Dependencies (Dependabot + lock refresh): `doctrine/orm` 3.7.3; dev `igor-php/igor-php` 0.10, PHPStan 2.3.1 (+ phpunit/symfony extensions 2.1), Rector 2.7.0, `nowo-tech/phpstan-frankenphp` 1.2.3.
- Demo (Symfony 8): `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, Twig 3.30.0, HotReloadBundle 1.5.5, TwigInspectorBundle 1.1.7; regenerated `config/reference.php`.

[1.1.12]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.12

## [1.1.11] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.1.11]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.11

## [1.1.10] - 2026-09-25

### Fixed

- **FrankenPHP worker mode without kernel reset** (`LastActivitySubscriber`, see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)):
  - The throttle map is keyed by profile and user identifier, drops expired entries on every write and is capped (`LastActivitySubscriber::MAX_THROTTLE_ENTRIES` = 10 000, constructor argument `$maxThrottleEntries`), so memory no longer grows with the number of distinct users.
  - The entity manager is resolved per request through `ManagerRegistry::getManagerForClass()` and reset when closed; a failing `flush()` is logged, resets closed managers and no longer turns the page into an HTTP 500.
  - Activity is only recorded when the main request passed a firewall with security enabled, so a token left over from a previous request never updates another user's `lastActivityAt`.
  - New optional constructor arguments (autowired): `$managerRegistry`, `$security`, `$logger`, `$maxThrottleEntries`.

### Changed

- **PHPStan:** include `ruleset-worker-no-kernel-reset.neon` (FrankenPHP kernel reused / `FRANKENPHP_RESET_KERNEL` unset/false).
- **`NowoUserKitBundle`:** rely on Symfony's extension naming convention (no mutable `getContainerExtension()` override).
- **Docs:** `FRANKENPHP-WORKER-AUDIT.md`, upgrading notes for 1.1.10, baseline spec FR-PRES-001 / FR-RUNTIME-001.

## [1.1.9] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.1.9]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.9

## [1.1.8] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [1.1.7] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.1.7]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.7

## [1.1.6] - 2026-08-12

### Fixed

- **`AccountStatusUserChecker`**: reject disabled accounts in **`checkPreAuth`** as well as `checkPostAuth`, so AuthKit flows that call `Security::login()` (magic login, social, QR) cannot sign in a disabled user.

### Changed

- Spec FR-SEC-001 updated: pre-auth is no longer a no-op for account status.

## [1.1.5] - 2026-07-29

### Added

- **REQ-DI-001:** `LastActivitySubscriber` and `UserPresenceResolver` use `Psr\Clock\ClockInterface` (require `symfony/clock`).
- **Makefile:** `check-open-prs`, `coverage-check`, `demo-smoke`, `down-dev`; `release-check` wires the full REQ-MAKE-002 chain.
- **SF-005:** `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist` and CI.
- FrankenPHP Friendly banner (`docs/images/frankenphp-friendly.png`) — REQ-DOCS-017.
- Spec Kit inventory entry for `Profile/UnknownProfileException.php`.

### Security

- Release checklist (12.4.1) includes **REQ-SEC-004** Pass (conditional) row.

### Changed

- Demo image `dunglas/frankenphp:1-php8.5-alpine` (REQ-DEMO-010).
- Packagist homepage + GitHub About topics for REQ-DOCS-018.

## [1.1.4] - 2026-07-22

### Added

- **Demo FrankenPHP:** `FRANKENPHP_MODE` (`classic` \| `worker`, default `worker`) via `demo/symfony8/docker/entrypoint.sh`, `.env.example`, and Compose; documented in `docs/DEMO-FRANKENPHP.md`.

### Changed

- PHP-CS-Fixer `fully_qualified_strict_types` enables `import_symbols`; source uses imported class names (`ProfileSettings`, Twig `AbstractExtension`) instead of leading-backslash FQCNs.
- Dev dependency lock updates (`composer.lock`, `demo/symfony8/composer.lock`).

## [1.1.3] - 2026-07-16

### Added

- **REQ-GIT-001:** CI job `git-hygiene`, `.scripts/check-no-cursor-coauthor.sh`, `.githooks/commit-msg`, and Makefile targets `check-no-cursor-coauthor` / `strip-cursor-coauthor-from-history` so release and CI reject Cursor co-author trailers in git history.
- **`docs/GITHUB_CI.md`** — operator guide for GitHub Actions git-hygiene requirements.
- **`CODE_OF_CONDUCT.md`** (Contributor Covenant) and README / Contributing links.

### Changed

- `make release-check` runs `check-no-cursor-coauthor` first.
- `make setup-hooks` also installs the `commit-msg` hook.
- `docs/RELEASE.md` and `docs/CONTRIBUTING.md` document the git-hygiene workflow.

## [1.1.2] - 2026-07-15

### Changed

- **`demo/symfony8`:** configuration migrated to the **`profiles`** layout (`default_profile: app_user`); demo UI shows the resolved profile name and online threshold via `ProfileRegistry`.
- **`demo/symfony8/README.md`** and **`demo/README.md`:** document named profiles and the `app_user` profile example.

## [1.1.1] - 2026-07-14

### Fixed

- **CI:** PHP 8.2 and 8.3 matrix jobs now install `doctrine/doctrine-bundle` ^2.10; ^3.0 is used only on PHP 8.4+ (Doctrine Bundle 3.x requires PHP ^8.4).

## [1.1.0] - 2026-07-14

### Added

- **Named configuration profiles** under `nowo_user_kit.profiles` for applications with multiple user entities (e.g. `App\Entity\User` and `App\Entity\Admin`).
- **`ProfileRegistry`** with O(1) class lookup and per-class resolution cache (inheritance supported).
- Optional **profile name** argument on `UserPresenceResolver::isOnline()` and Twig `user_is_online()`.
- `default_profile` configuration key.

### Changed

- `AccountStatusUserChecker`, `LastActivitySubscriber`, `AccountDisabledListener`, and `UserPresenceResolver` resolve settings from the matching profile (by entity class).
- Configuration YAML templates and Flex recipe migrated to the `profiles` layout (flat config still accepted and normalized to `profiles.default`).
- `docs/CONFIGURATION.md` and `docs/USAGE.md` document profiles and runtime resolution.

## [1.0.3] - 2026-07-14

### Changed

- Demos reduced to a single Symfony 8 application (`demo/symfony8` only). Removed `demo/symfony7` and `demo/symfony8-php85`.
- Demo `composer.json` and documentation aligned with bundle minimum **PHP 8.2+** (FrankenPHP image remains PHP 8.4 for Symfony 8).
- English comments added to bundle configuration YAML (`Resources/config/packages/nowo_user_kit.yaml`, `Resources/config/services.yaml`) and the Symfony Flex recipe.
- `docs/INSTALLATION.md`, `docs/DEMO-FRANKENPHP.md`, and `docs/CONTRIBUTING.md` updated for the single-demo layout.
- `phpstan.neon.dist` comment aligned with PHP 8.2+.

## [1.0.2] - 2026-07-14

### Added

- Translation catalogues **de**, **fr**, **it**, **nl**, and **pt** for domain `NowoUserKitBundle` (seven-locale minimum per Nowo bundle standards).
- Makefile target **`validate-translations`** (YAML lint + key parity across required locales).
- **`.scrutinizer.yml`** for Scrutinizer CI (PHP coverage via `composer test-coverage`).
- Demo applications **`demo/symfony7`** (Symfony 7.4, PHP 8.2) and **`demo/symfony8-php85`** (Symfony 8.1, PHP 8.5).
- Script **`.scripts/validate-translation-keys.php`** for translation key parity checks.

### Changed

- README: standard badges, canonical `## Documentation` section, FrankenPHP **worker mode** note, and three-demo quick start.
- `docs/USAGE.md` and `docs/CONFIGURATION.md`: translation overrides and supported locales.
- `docs/SPEC-DRIVEN-DEVELOPMENT.md` and `.specify/memory/constitution.md` aligned with UserKitBundle (implemented baseline).
- `specs/001-baseline/code-inventory.md`: seven translation files mapped.
- `demo/README.md` and `demo/Makefile`: aggregator for symfony7, symfony8, and symfony8-php85.
- `make release-check` now runs **`test-coverage-100`** and **`validate-translations`**.

### Fixed

- **`phpunit.xml.dist`**: removed non-existent `tests/Integration` suite (fixes `composer test` / `make test-coverage-100`).
- **`demo/symfony7`** and **`demo/symfony8-php85`**: replaced stale WalletQrBundle scaffolding with User Kit demos.

## [1.0.1] - 2026-07-14

### Changed

- README: links to CHANGELOG, UPGRADING, RELEASE, and SECURITY; `## Tests and coverage` with explicit PHP coverage percentage.
- GitHub metadata corrected from stale WalletQrBundle references (SECURITY policy, issue template, CODEOWNERS, FUNDING).
- Root Makefile header comment aligned with User Kit Bundle.

### Fixed

- CHANGELOG 1.0.0 demo note: primary demo is `demo/symfony8` (FrankenPHP + SQLite).

## [1.0.0] - 2026-07-14

### Added

- **`AccountStatusUserChecker`** — blocks authentication for disabled accounts via Symfony `UserChecker` (`DisabledException`).
- **`LastActivitySubscriber`** — throttled `lastActivityAt` updates on authenticated HTTP requests.
- **`UserPresenceResolver`** — `isOnline()` based on configurable `online_threshold`.
- **`user_is_online` Twig helper** — optional when `symfony/twig-bundle` is installed.
- **`AccountDisabledListener`** — optional session invalidation when an account is disabled.
- **`SessionInvalidatorInterface`** and **`DefaultSessionInvalidator`** extension point.
- Entity contracts: `AccountStatusInterface`, `LastActivityInterface`.
- Optional Doctrine traits: `EnabledUserTrait`, `LastActivityTrait`.
- Configuration tree `nowo_user_kit` (`user_class`, `account_status`, `last_activity`, `twig`).
- AuthKit coexistence: inherits `user_class` from `nowo_auth_kit.user_class` when present.
- Translation catalogues **en** and **es** for domain `NowoUserKitBundle`.
- GitHub Spec Kit baseline (`specs/001-baseline/`), operator manual ([`SPEC-KIT.md`](SPEC-KIT.md)), and Cursor Agent skills.
- Demo application **`demo/symfony8`** (FrankenPHP + SQLite, account status and presence smoke tests).
- Symfony Flex recipe `.symfony/recipe/nowo-tech/user-kit-bundle/1.0/`.
- CI matrix: PHP 8.2–8.5, Symfony 7.0 / 7.4 / 8.0 / 8.1 with **100%** PHPUnit line coverage on `src/`.

[1.1.10]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.10
[1.1.9]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.9
[1.1.6]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.6
[1.1.5]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.5
[1.1.4]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.4
[1.1.3]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.1.0
[1.0.3]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.3
[1.0.2]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.2
[1.0.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.0
