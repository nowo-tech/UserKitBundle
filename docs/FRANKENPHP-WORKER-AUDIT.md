# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/user-kit-bundle` (`symfony-bundle`) |
| Audited revision | `v1.1.10` |
| Audit date | 2026-09-25 |
| Method | Manual review of every file under `src/` (services, subscriber, Doctrine listener, user checker, Twig extension, DI extension, config) + PHPStan `ruleset-classic` + `ruleset-worker-no-kernel-reset` |
| **Verdict** | ✅ **Viable under scenario B (100%)** — the last-activity throttle map is bounded, the subscriber resets closed entity managers and survives flush failures, and it ignores tokens outside a security-enabled firewall. Safe under FrankenPHP worker with `FRANKENPHP_RESET_KERNEL` unset/false (kernel reused; `services_resetter` still runs). |
| Remediation | W-01 and W-02 resolved in `src/EventSubscriber/LastActivitySubscriber.php`; `NowoUserKitBundle` no longer mutates `$extension` (convention-based DI extension). Regression tests in `tests/Unit/EventSubscriber/LastActivitySubscriberWorkerTest.php` |

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
| `ResetInterface` / `kernel.reset` coverage | ✅ | No service implements `ResetInterface`; the throttle map is intentionally long-lived and now self-evicting |
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
| `Nowo\UserKitBundle\Profile\ProfileRegistry` | yes | `$resolveCache` (class-string → profile), filled lazily; `$byName` / `$byExactClass` set in constructor only | ✅ | ✅ (bounded by number of user classes and their proxies) |
| `Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber` | yes (removed if no profile enables `last_activity`) | `$throttledUntil` (profile + user identifier → window end), pruned and capped | ✅ | ✅ |
| `Nowo\UserKitBundle\Security\AccountStatusUserChecker` (`security.user_checker`) | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\UserKitBundle\EventListener\AccountDisabledListener` (`doctrine.orm.entity_listener`, lazy) | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\UserKitBundle\Presence\UserPresenceResolver` | yes | none (`readonly`, uses `ClockInterface`) | ✅ | ✅ |
| `Nowo\UserKitBundle\Twig\UserPresenceExtension` | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\UserKitBundle\Session\DefaultSessionInvalidator` | yes | none (no-op) | ✅ | ✅ |

`ProfileSettings` is `final readonly` and built once in the `ProfileRegistry` constructor. The traits under `src/Model/` are entity code and excluded from the container (`src/Resources/config/services.yaml:12`).

## Findings

### W-01 — Last-activity throttle map grows without bound (Medium)

- **Where:** `src/EventSubscriber/LastActivitySubscriber.php:22` (`private array $lastWriteAt = []`), read at line 64 and written at line 77.
- **Worker impact:** each distinct `getUserIdentifier()` that passes through the subscriber adds one entry, and entries are never removed. In a long-lived worker serving many users, memory grows linearly with the number of distinct users seen by that thread (small per entry, but unbounded). There is no cross-user data exposure: the map only stores a timestamp per identifier and only decides whether to skip a DB write. Two notes on behaviour:
  - Under PHP-FPM the map is empty on every request, so the throttle never applies and every authenticated request writes. In worker mode the throttle actually works, but per worker thread, not globally.
  - Keys are the bare user identifier, so two profiles whose users share an identifier (for example `john` in `App\Entity\User` and in `App\Entity\Admin`) share a throttle slot; one of them may skip a write inside the window. This affects only freshness of `lastActivityAt`, not security.
- **Recommendation:** drop entries older than `updateThrottle` before inserting (or cap the map size, e.g. LRU of a few thousand keys), and prefix keys with the profile name. Do **not** simply add `ResetInterface` that clears the map: that would restore FPM behaviour (a write on every request). Until fixed, set FrankenPHP `max_requests` (or `FRANKENPHP_LOOP_MAX`) to recycle workers periodically.
- **Status:** Resolved — the map is now `$throttledUntil` (window end per `profile\0identifier`), kept in insertion order: expired entries at the head are dropped on every successful write, entries are only stored when `update_throttle > 0`, and the size is capped at `MAX_THROTTLE_ENTRIES` (10 000, constructor argument `$maxThrottleEntries`) by evicting the oldest entry. No `ResetInterface` was added, so the throttle keeps working across requests. The throttle remains per worker thread (accepted: it only limits write frequency). Tests: `testThrottleMapIsCappedAcrossManyUsers`, `testExpiredThrottleEntriesArePruned`, `testThrottleIsNotRecordedWhenDisabled`, `testSameIdentifierInTwoProfilesHasSeparateThrottleSlots`.

### W-02 — `flush()` on `kernel.request` depends on a healthy EntityManager (Medium)

- **Where:** `src/EventSubscriber/LastActivitySubscriber.php:76` (`$this->entityManager->flush()`), subscribed to `KernelEvents::REQUEST` at line 36.
- **Worker impact:**
  - If a previous request closed the EntityManager (any exception during `flush()`), the next authenticated request that is not throttled fails with "EntityManager is closed". Under scenario A, DoctrineBundle's `doctrine` registry is reset by `services_resetter`, which resets closed managers. Under scenario B nothing resets it, so every non-throttled authenticated request in that worker fails until the worker is recycled.
  - The call flushes the whole unit of work, not only the user. In worker mode under scenario B the identity map is also not cleared between requests, so leftover changes from an earlier request could be written here.
  - A DB error during this flush turns a normal page view into a 500 and closes the EM for the rest of the request.
- **Recommendation:** keep `services_resetter` active (scenario A) whenever this subscriber is enabled. In the bundle, consider catching exceptions around the flush, checking `$entityManager->isOpen()`, and updating only the timestamp column with a DQL/DBAL `UPDATE` instead of a full `flush()`.
- **Status:** Resolved — with the (autowired, optional) `ManagerRegistry` the subscriber gets the manager of the user class on each request; if it is closed, `resetManager()` is called before writing, and if it is still unusable the write is skipped. `flush()` is wrapped in `try/catch (Throwable)`: the error is logged as a warning, closed managers are reset, the throttle is not recorded (retried on the next request) and the page is served normally. Additionally, with the (autowired) `Security` service the subscriber only acts when the main request carries `_firewall_context` and that firewall has security enabled, so a stale token under scenario B cannot update another user. The targeted DQL `UPDATE` was not adopted because `last_activity.field` may be written through a setter that is not a mapped column; the full `flush()` stays. Residual application responsibility under scenario B: pending changes left in the identity map by an earlier request would be flushed here — clearing the application's EntityManager between requests remains the application's job (the bundle never calls `clear()`). Tests: `testClosedEntityManagerIsResetBeforeWriting`, `testFlushFailureIsLoggedResetsManagerAndIsRetriedOnNextRequest`, `testFlushFailureWithoutRegistryDoesNotBreakTheRequest`, `testUserThatIsNotAnEntityIsSkipped`, `testPreviousUserIsNotTouchedOnNextRequestOutsideFirewall`, `testFirewallWithSecurityDisabledIsIgnored`.

### W-03 — Profile resolution cache is class-keyed and bounded (Info)

- **Where:** `src/Profile/ProfileRegistry.php:18` (`$resolveCache`), filled in `resolveForObject()` at lines 52-71.
- **Worker impact:** the cache maps an object class to its profile (or `null`). Its size is bounded by the number of distinct classes passed in (user classes, Doctrine proxies, and any entity class seen by the lazy `AccountDisabledListener`). It holds no user data, so the user checker cannot make a decision for user Y based on user X.
- **Recommendation:** none.

No other findings. `AccountStatusUserChecker` reads the enabled flag from the user object on every call and caches nothing, which is the correct security behaviour in a long-lived worker.

## Usage recommendations in worker mode

- If `last_activity.enabled` is `false` in every profile (the default), `LastActivitySubscriber` is removed from the container (`src/DependencyInjection/NowoUserKitExtension.php:59-61`) and W-01 / W-02 do not apply; the bundle is then ✅ under both scenarios.
- If `last_activity` is enabled: the subscriber is safe under both scenarios. Under scenario B, clearing the application's identity map between requests remains the application's responsibility, because the subscriber flushes the application's unit of work.
- Custom `SessionInvalidatorInterface` implementations must stay stateless (no per-user buffers) or implement `ResetInterface`. They run inside Doctrine `postUpdate`, so do not call `flush()` from them.
- Do not decorate `ProfileRegistry` with caches keyed by user identifier.
- The demo (`demo/symfony8/docker/frankenphp/Caddyfile`) runs FrankenPHP in `worker` mode.

## Re-audit triggers

Re-run this audit when a change adds: new properties to any subscriber, listener, user checker or resolver; a new cache in `ProfileRegistry`; more Doctrine writes on kernel events; a `ResetInterface` on `LastActivitySubscriber`; or any use of `$_SERVER` / `$_ENV` / session globals at runtime.
