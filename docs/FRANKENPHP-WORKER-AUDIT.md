# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/user-kit-bundle` (`symfony-bundle`) |
| Audited revision | `v1.1.10`; session registry remember-me epoch addendum on `v1.14.1` + Unreleased (W-04) |
| Audit date | 2026-09-25; 2026-11-11 (W-04, REQ-RUNTIME-002 sweep) |
| Method | Manual review of every file under `src/` (services, subscriber, Doctrine listener, user checker, Twig extension, DI extension, config) + PHPStan `ruleset-classic` + `ruleset-worker-no-kernel-reset` |
| **Verdict** | ✅ **Viable under scenario B (100%)** — the last-activity throttle map is bounded, the subscriber never flushes (targeted `UPDATE` of the user's row), resets only the user's closed manager and survives write failures, and it ignores tokens outside a security-enabled firewall. Safe under FrankenPHP worker with `FRANKENPHP_RESET_KERNEL` unset/false (kernel reused; `services_resetter` still runs). |
| Remediation (2026-11-11, delta audit MD) | W-05 / W-06 resolved: `PasswordChangeListener` and `UserRemovalListener` key their pending entries by entity manager and unit of work and clear them at the request / console / Messenger boundaries. Tests: `tests/Integration/Sessions/SessionListenersWorkerTest.php` |
| Remediation (2026-11-11) | W-04 resolved: `RememberMeEpoch::$kept` is emptied at the end of the main `kernel.response`, at the main `kernel.request`, `kernel.terminate`, `console.terminate` and the Messenger worker events, not only by `kernel.reset`. Tests: `tests/Integration/Sessions/RememberMeEpochWorkerTest.php`, `HostHooksTest::testResetAndTheMainRequestDropTheKeptSessions`, `HostHooksTest::testWiring` |
| Remediation | W-01 and W-02 resolved in `src/EventSubscriber/LastActivitySubscriber.php`; `NowoUserKitBundle` no longer mutates `$extension` (convention-based DI extension). Regression tests in `tests/Unit/EventSubscriber/LastActivitySubscriberWorkerTest.php` |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
  - [W-01 — Last-activity throttle map grows without bound (Medium)](#w-01--last-activity-throttle-map-grows-without-bound-medium)
  - [W-02 — `flush()` on `kernel.request` depends on a healthy EntityManager (Medium)](#w-02--flush-on-kernelrequest-depends-on-a-healthy-entitymanager-medium)
  - [W-03 — Profile resolution cache is class-keyed and bounded (Info)](#w-03--profile-resolution-cache-is-class-keyed-and-bounded-info)
  - [W-05 — Password-change revocation survives a failed flush (Medium)](#w-05--password-change-revocation-survives-a-failed-flush-medium)
  - [W-06 — Pending user removal applied by another entity manager (Low)](#w-06--pending-user-removal-applied-by-another-entity-manager-low)
  - [W-04 — Remember-me epoch "kept session" survives its request (Medium, security)](#w-04--remember-me-epoch-kept-session-survives-its-request-medium-security)
- [Worker tests](#worker-tests)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests (`FRANKENPHP_RESET_KERNEL` unset/false — the production default). Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests. This is the normal FrankenPHP worker profile.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `LastActivitySubscriber::$throttledUntil` is pruned and capped (W-01 resolved); `ProfileRegistry::$resolveCache` is keyed by class name (bounded) |
| Static properties / `static` locals | ✅ | None (only static closures in `Configuration`, compile time) |
| `ResetInterface` / `kernel.reset` coverage | ✅ | Session registry (1.10+): `RememberMeEpoch`, `CommitQueue`, `UserRemovalListener`, `PasswordChangeListener` implement `ResetInterface` and are also emptied at their own boundaries (W-04, W-05, W-06; `CommitQueue` at `kernel.response` / `kernel.terminate` / `console.terminate` / Messenger; the two Doctrine listeners keep their pending entries per entity manager and unit of work, drop them at `postFlush` / `onClear` (and `PasswordChangeListener` at the `onFlush` of a new unit of work), and at the main `kernel.request`, `kernel.terminate`, `console.terminate` and the Messenger worker events), so correctness never depends on `kernel.reset`. `ProfileRegistry` and `LastActivitySubscriber` implement `ResetInterface` (autoconfigured `kernel.reset`). `ProfileRegistry::reset()` drops the resolution memo; `LastActivitySubscriber::reset()` evicts only expired throttle windows, so the throttle still spans requests (2026-10-30, REQ-CS-006) |
| Request / user / locale captured in services | ✅ | User is read from `TokenStorage` inside `onKernelRequest()`, only on requests behind a security-enabled firewall; time comes from `ClockInterface` |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used |
| Doctrine / EntityManager | ✅ | `LastActivitySubscriber` resolves the manager per request (`ManagerRegistry::getManagerForClass()`), resets it when closed and catches flush failures (W-02 resolved) |
| Output, headers, `exit`, shutdown functions | ✅ | None |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ✅ | Throttle map pruned on each write and capped at `MAX_THROTTLE_ENTRIES` (W-01 resolved) |
| Blocking I/O and timeouts | ✅ | Only a Doctrine `flush()`; DB timeouts are the application's DBAL config |
| Third-party static state | ✅ | Only Symfony Security / PropertyAccess / Doctrine ORM |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker-no-kernel-reset.neon` in `phpstan.neon.dist` (covers kernel reuse / missing `ResetInterface`) |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Nowo\UserKitBundle\Profile\ProfileRegistry` | yes | `$resolveCache` (class-string → profile), filled lazily and cleared by `reset()`; `$byName` / `$byExactClass` set in constructor only | ✅ | ✅ (bounded by number of user classes and their proxies) |
| `Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber` | yes (removed if no profile enables `last_activity`) | `$throttledUntil` (profile + user identifier → window end), pruned and capped; `reset()` evicts expired windows | ✅ | ✅ |
| `Nowo\UserKitBundle\Security\AccountStatusUserChecker` (`security.user_checker.<firewall>`) | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\UserKitBundle\EventListener\AccountDisabledListener` (`doctrine.orm.entity_listener`, lazy) | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\UserKitBundle\Presence\UserPresenceResolver` | yes | none (`readonly`, uses `ClockInterface`) | ✅ | ✅ |
| `Nowo\UserKitBundle\Twig\UserPresenceExtension` | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\UserKitBundle\Session\DefaultSessionInvalidator` | yes | none (no-op) | ✅ | ✅ |
| `Nowo\UserKitBundle\Sessions\Epoch\RememberMeEpoch` (`sessions.remember_me_epoch.enabled`, 1.10) | yes | `$kept`: the session kept by a revocation of the current request, per owner. Emptied at the end of the main `kernel.response` (where it is used), at the main `kernel.request`, `kernel.terminate`, `console.terminate`, `WorkerMessageReceived/Handled/FailedEvent`, by `forget()` and `reset()` | ✅ | ✅ (since 1.14.2, W-04) |

`ProfileSettings` is `final readonly` and built once in the `ProfileRegistry` constructor. The traits under `src/Model/` are entity code and excluded from the container (`src/Resources/config/services.yaml:12`).

## Findings

### W-01 — Last-activity throttle map grows without bound (Medium)

- **Where:** `src/EventSubscriber/LastActivitySubscriber.php:22` (`private array $lastWriteAt = []`), read at line 64 and written at line 77.
- **Worker impact:** each distinct `getUserIdentifier()` that passes through the subscriber adds one entry, and entries are never removed. In a long-lived worker serving many users, memory grows linearly with the number of distinct users seen by that thread (small per entry, but unbounded). There is no cross-user data exposure: the map only stores a timestamp per identifier and only decides whether to skip a DB write. Two notes on behaviour:
  - Under PHP-FPM the map is empty on every request, so the throttle never applies and every authenticated request writes. In worker mode the throttle actually works, but per worker thread, not globally.
  - Keys are the bare user identifier, so two profiles whose users share an identifier (for example `john` in `App\Entity\User` and in `App\Entity\Admin`) share a throttle slot; one of them may skip a write inside the window. This affects only freshness of `lastActivityAt`, not security.
- **Recommendation:** drop entries older than `updateThrottle` before inserting (or cap the map size, e.g. LRU of a few thousand keys), and prefix keys with the profile name. Do **not** simply add `ResetInterface` that clears the map: that would restore FPM behaviour (a write on every request). Until fixed, set FrankenPHP `max_requests` (or `FRANKENPHP_LOOP_MAX`) to recycle workers periodically.
- **Status:** Resolved — the map is now `$throttledUntil` (window end per `profile\0identifier`), kept in insertion order: expired entries at the head are dropped on every successful write, entries are only stored when `update_throttle > 0`, and the size is capped at `MAX_THROTTLE_ENTRIES` (10 000, constructor argument `$maxThrottleEntries`) by evicting the oldest entry. `ResetInterface` was added on 2026-10-30 (REQ-CS-006), but `reset()` only evicts windows that already expired, so the throttle keeps working across requests (`testResetEvictsExpiredWindowsAndKeepsOpenOnes`). The throttle remains per worker thread (accepted: it only limits write frequency). Tests: `testThrottleMapIsCappedAcrossManyUsers`, `testExpiredThrottleEntriesArePruned`, `testThrottleIsNotRecordedWhenDisabled`, `testSameIdentifierInTwoProfilesHasSeparateThrottleSlots`.

### W-02 — `flush()` on `kernel.request` depends on a healthy EntityManager (Medium)

- **Where:** `src/EventSubscriber/LastActivitySubscriber.php:76` (`$this->entityManager->flush()`), subscribed to `KernelEvents::REQUEST` at line 36.
- **Worker impact:**
  - If a previous request closed the EntityManager (any exception during `flush()`), the next authenticated request that is not throttled fails with "EntityManager is closed". Under scenario A, DoctrineBundle's `doctrine` registry is reset by `services_resetter`, which resets closed managers. Under scenario B nothing resets it, so every non-throttled authenticated request in that worker fails until the worker is recycled.
  - The call flushes the whole unit of work, not only the user. In worker mode under scenario B the identity map is also not cleared between requests, so leftover changes from an earlier request could be written here.
  - A DB error during this flush turns a normal page view into a 500 and closes the EM for the rest of the request.
- **Recommendation:** keep `services_resetter` active (scenario A) whenever this subscriber is enabled. In the bundle, consider catching exceptions around the flush, checking `$entityManager->isOpen()`, and updating only the timestamp column with a DQL/DBAL `UPDATE` instead of a full `flush()`.
- **Status:** Resolved — with the (autowired, optional) `ManagerRegistry` the subscriber gets the manager of the user class on each request; if it is closed, `resetManager()` is called before writing, and if it is still unusable the write is skipped. `flush()` is wrapped in `try/catch (Throwable)`: the error is logged as a warning, closed managers are reset, the throttle is not recorded (retried on the next request) and the page is served normally. Additionally, with the (autowired) `Security` service the subscriber only acts when the main request carries `_firewall_context` and that firewall has security enabled, so a stale token under scenario B cannot update another user. The targeted DQL `UPDATE` was not adopted because `last_activity.field` may be written through a setter that is not a mapped column; the full `flush()` stays. Residual application responsibility under scenario B: pending changes left in the identity map by an earlier request would be flushed here — clearing the application's EntityManager between requests remains the application's job (the bundle never calls `clear()`). Tests: `testClosedEntityManagerIsResetBeforeWriting`, `testFlushFailureIsLoggedResetsManagerAndIsRetriedOnNextRequest`, `testFlushFailureWithoutRegistryDoesNotBreakTheRequest`, `testUserThatIsNotAnEntityIsSkipped`, `testPreviousUserIsNotTouchedOnNextRequestOutsideFirewall`, `testFirewallWithSecurityDisabledIsIgnored`.
  **Update 1.14.1:** the residual is gone. The subscriber never flushes: a mapped field is written with a targeted DQL `UPDATE` (since 1.6.0), and a setter that writes another property now has the mapped columns it changed written the same way. Only the user's own manager is reset when closed. Tests: `LastActivityKernelTest` (unflushed host changes stay pending; 1,200 requests of two alternating users on one kept-alive kernel with flat memory and per-user rows), `testOnlyTheUsersClosedManagerIsReset`.

### W-03 — Profile resolution cache is class-keyed and bounded (Info)

- **Where:** `src/Profile/ProfileRegistry.php:18` (`$resolveCache`), filled in `resolveForObject()` at lines 52-71.
- **Worker impact:** the cache maps an object class to its profile (or `null`). Its size is bounded by the number of distinct classes passed in (user classes, Doctrine proxies, and any entity class seen by the lazy `AccountDisabledListener`). It holds no user data, so the user checker cannot make a decision for user Y based on user X.
- **Recommendation:** none.
- **Update 2026-10-30:** `ProfileRegistry` implements `ResetInterface`; `reset()` clears the memo between requests under scenario A (resolution is recomputed on first use, a few `instanceof` checks).

### W-04 — Remember-me epoch "kept session" survives its request (Medium, security)

- **Where:** `src/Sessions/Epoch/RememberMeEpoch.php` (1.10.0 – 1.14.1, with `sessions.remember_me_epoch.enabled`).
  `keep()` stores the session kept by a revocation (revokeOthers / revokeAll `except`, or the caller's current session
  for revoke / revokeDevice with `invalidateRememberMe`); only `forget()` and `reset()` (`kernel.reset`) emptied it.
- **Worker impact:** under **A** the services resetter emptied it. Under **B** (kernel reset false) the entry stayed in
  the worker. After "sign out this device" with `invalidateRememberMe` (kept = "the current session, except the revoked
  ones"), a later request of **another** session of the same user in that worker matched it and got a fresh
  remember-me cookie, undoing the invalidation for that browser. The map also grew with every owner who revoked.
- **Status:** Resolved in 1.14.2 — `onResponse()` empties the memo at the end of every main response, and the service
  also listens to the main `kernel.request`, `kernel.terminate`, `console.terminate` and the Messenger worker events;
  `kernel.reset` still calls `reset()`. Tests: `tests/Integration/Sessions/RememberMeEpochWorkerTest.php`,
  `tests/Unit/Sessions/HostHooksTest.php`.

### W-05 — Password-change revocation survives a failed flush (Medium)

- **Where:** `src/Sessions/EventListener/PasswordChangeListener.php` (1.9.0 – 1.14.1, session registry with
  `sessions.password_change.revoke_sessions`, the default). `preUpdate` stored the user in `$pending`; only `postFlush`
  and `reset()` (`kernel.reset`) emptied it (there was no `onClear`, despite what this document said before).
- **Impact:** a flush that failed after `preUpdate` (constraint error, deadlock, commit failure) never reached
  `postFlush`; the next flush of any user (any request in a worker with reset false, or later in the same request on a
  classic host) signed out every other session of the user whose password change was never stored. Fail-safe
  (over-revocation), audit MD-U-M1.
- **Status:** Resolved in 1.14.2 — pending changes are kept per entity manager with the unit of work that collected
  them; the `onFlush` of a new unit of work, `onClear`, `postFlush` of that manager, the main `kernel.request`,
  `kernel.terminate`, `console.terminate` and the Messenger worker events drop them. A nested flush of the same unit
  of work keeps them. Test: `SessionListenersWorkerTest`.

### W-06 — Pending user removal applied by another entity manager (Low)

- **Where:** `src/Sessions/EventListener/UserRemovalListener.php` (1.10.0 – 1.14.1, with
  `sessions.user_removal.delete_sessions`). `preRemove` (at `remove()`, not at flush) stored the user in one shared
  list; the `postFlush` of any manager that also maps the user class saw it as neither scheduled nor managed and
  deleted the user's sessions and epoch, although the user was never removed. Entries also stayed for the worker's
  lifetime when nothing flushed or cleared. Audit MD-U-L1.
- **Status:** Resolved in 1.14.2 — pending removals are kept per entity manager and unit of work; only that manager's
  `postFlush` applies them, a removal of a unit of work the manager no longer has is dropped, and the same request /
  console / Messenger boundaries clear them. Test: `SessionListenersWorkerTest`.

No other findings. `AccountStatusUserChecker` reads the enabled flag from the user object on every call and caches nothing, which is the correct security behaviour in a long-lived worker.

## Worker tests

Kept-alive kernel, services resetter never called:

| Test | What it serves | N | Users | Memory |
|------|----------------|---|-------|--------|
| `tests/Integration/Sessions/RememberMeEpochWorkerTest.php` (requests through the `http_kernel` service) | `GET /page`, with a "sign out this device" + `invalidateRememberMe` every 50th request; plus a 3-session revocation scenario | 1,000 | alice and bob alternating | < 4 MB growth between request 100 and the end |
| `tests/Integration/Sessions/SessionListenersWorkerTest.php` | failed password flush then another user's flush on one kernel; removals across two managers; stale unit of work; boundaries | — | alice and bob | — |
| `tests/Integration/LastActivityKernelTest.php` | last-activity subscriber on `kernel.request` | 1,200 | alice and bob alternating | flat, per-user rows |

Static checks: `composer igor` and the PHPStan FrankenPHP rulesets (`phpstan.neon.dist`) pass in CI.

## Usage recommendations in worker mode

- If `last_activity.enabled` is `false` in every profile (the default), `LastActivitySubscriber` is removed from the container (`src/DependencyInjection/NowoUserKitExtension.php:59-61`) and W-01 / W-02 do not apply; the bundle is then ✅ under both scenarios.
- If `last_activity` is enabled: the subscriber is safe under both scenarios. Under scenario B, clearing the application's identity map between requests remains the application's responsibility, because the subscriber flushes the application's unit of work.
- Custom `SessionInvalidatorInterface` implementations must stay stateless (no per-user buffers) or implement `ResetInterface`. They run inside Doctrine `postUpdate`, so do not call `flush()` from them.
- Do not decorate `ProfileRegistry` with caches keyed by user identifier.
- The demo (`demo/symfony8/docker/frankenphp/Caddyfile`) runs FrankenPHP in `worker` mode.

## Re-audit triggers

Re-run this audit when a change adds: new properties to any subscriber, listener, user checker or resolver; a new cache in `ProfileRegistry`; more Doctrine writes on kernel events; a `reset()` on `LastActivitySubscriber` that drops open throttle windows; or any use of `$_SERVER` / `$_ENV` / session globals at runtime.
