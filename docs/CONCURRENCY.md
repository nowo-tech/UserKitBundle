# Concurrency

How UserKit stays correct when the same data is written at the same time (nowo-tech REQ-RUNTIME-003): the write
paths, the conflict answer and its codes, the host options and the residual risks. The mechanics (UserKit's runner,
retries, named locks, what happens to your entity manager) are in [HIGH-LOAD](HIGH-LOAD.md).

## Table of contents

- [Write paths](#write-paths)
- [Conflict codes](#conflict-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Host options](#host-options)
- [Workers and entity managers](#workers-and-entity-managers)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Write paths

| Path | Transaction | Concurrency control | Conflict answer |
| --- | --- | --- | --- |
| Personal data erasure (`PersonalDataErasureInterface::erase()`) | One unit of work of `UserKitTransactionRunner` (one transaction, the erasers, one flush; a savepoint inside your transaction) | All or nothing; levels repaired after a failure (1305 never success); not replayed | 409 + `CONFLICT_001` + `Retry-After` |
| Session registry: register, touch, rekey, revoke, delete (request listeners, `UserSessionManager`, the sessions pages) | One autocommit DBAL statement each, retried 3 times; inside your transaction, never replayed | Unique session hash plus catch (`UserKitRows::insert()` in its own savepoint, the winner's row read); exact owner key (binary collation or byte-exact re-check) | 409 + `CONFLICT_001`; `CONFLICT_002` when an earlier statement of the same call is committed (below) |
| Concurrent-session limit (sign-in: count, evict, register) | Autocommit statements under a named lock of the owner (`GET_LOCK()` / `pg_try_advisory_lock()`, 5 s) | Named lock; `reject_new` checked again under the lock | Lock not obtained: 409 + `CONFLICT_001`. Eviction committed, then a failure: 409 + `CONFLICT_002`, no `Retry-After`. The failed sign-in is signed out |
| Revocations with the remember-me epoch (`revokeAll()`, `revokeOthers()`, `forgetOwner()`) | Epoch bump, revocation (and deletion) as autocommit statements | Epoch moves forward only; first row unique plus a locking re-read | 409 + `CONFLICT_002` when the bump or the revocation is committed and the next statement fails; else `CONFLICT_001` |
| Remember-me epoch alone | One autocommit statement | Monotonic `UPDATE`; first row unique plus a locking re-read | 409 + `CONFLICT_001` |
| Last activity (`kernel.request`) | One DQL `UPDATE`, throttled, autocommit, retried | Moves forward only (`WHERE column IS NULL OR column < :value`) | Failures logged, never fail the request (best-effort timestamp) |
| Owner images (`OwnerImagesInterface::upload()` / `remove()`) | Pointer row in one transaction (a savepoint inside yours) under a named lock of the kind and subject; previous file deleted after the outermost commit | Named lock plus `LockingOwnerImagePointerStoreInterface::replace()` (locking read); unique (kind, subject) plus catch | 409 + `CONFLICT_001` (the new file stays an orphan StorageKit purges). A look-alike subject: `UserKitConflict` (domain 409) |
| Session purge command (`nowo:user-kit:sessions:purge`) | Batches of `DELETE … WHERE id IN (…)` in autocommit (`--batch-size`) | Idempotent; small batches | Console error; run it again |
| UI preferences, profile settings, product tour | Your application's own flush (fields of your user entity) | Yours | Yours |

Side effects (the revocation events, a deleted owner image file, the revocation marks in the cache) run after the
outermost commit through the commit queue when a transaction is open, else right after the statement.

## Conflict codes

Since **1.21.0** (with nowo-tech/transaction-doctrine ^1.5). A `UserKitStorageFailure` reaching the kernel is answered
by `StorageFailureSubscriber` on any route (UserKit's session listener and erasure run on your own pages and APIs):

| Code (`X-Conflict-Code`) | When | Status | `Retry-After` | Platform JSON `error` | Client should |
| --- | --- | --- | --- | --- | --- |
| `CONFLICT_001` | Deadlock (MySQL / MariaDB 1213, PostgreSQL 40P01), lock wait timeout (1205, 55P03), serialization failure (MariaDB 1020, PostgreSQL 40001), a UserKit named lock not obtained in 5 s; nothing of the request committed | 409 | `1` | `concurrency_conflict` | Retry |
| `CONFLICT_002` | The same, after the request already committed part of its work (`UserKitStorageFailure::afterCommit()`) | 409 | none | `concurrency_conflict_partial` | Read again, do not repeat blindly |

- **Default: your rendering.** The failure becomes a `ConflictHttpException` (a `ServiceUnavailableHttpException` at
  503) with `Retry-After` and `X-Conflict-Code`, so your error pages and JSON error format (problem+json, API Platform,
  your templates) keep their shapes; the code is added as a header only.
- **Platform answer** (`conflict_response.platform_answer: true`, or `platform_answer` left null while
  `nowo_transaction_doctrine.conflict_response.map_transient_failures` is true): JSON, XHR and `/api/` requests get `{"error": "…", "code": "CONFLICT_00n", "message": "…"}`, HTML the
  "try again" page with `data-testid="concurrency-conflict"` and `data-conflict-code="CONFLICT_00n"`
  (transaction-doctrine's template, extending UiKit's error page, when its bundle is registered; a plain English page
  otherwise). The `X-Conflict-Code` header is set too.
- `X-Conflict-Code` only ever carries `CONFLICT_001` or `CONFLICT_002` (platform convention); domain conflicts never
  use it.
- Code that calls UserKit directly: `UserKitStorageFailure::conflictCode()` (a `Nowo\TransactionDoctrine\ConflictCode`)
  and `committed()`. A committed failure also holds a `ConcurrencyConflict` with `committed: true` in its previous
  chain, so `ConflictCode::forFailure()` and transaction-doctrine's own responder give the same code.

## What is not a conflict

- **Unique violations.** UserKit's own "only one" writes (the first row of a session, of an epoch, of an owner image
  pointer) resolve their races where they happen: the `INSERT` runs in its own savepoint and the winner's row is read.
  A unique violation that reaches the end of an erasure comes from your erasers and is rethrown as it is (a real
  error; 1.19–1.20 answered it as `UserKitConflict`, 409).
- **`UserKitConflict`** is a domain answer, not a lock conflict: an owner image subject whose unique key a look-alike
  subject holds (a pointer table not on the binary collation), or several rows for one subject id. It is answered 409
  through your error page with no `CONFLICT_*` code: retrying gives the same answer.
- **Lost connections and every other database error** are not mapped: they stay errors (500).

## Host options

| Option | Default | Effect |
| --- | --- | --- |
| `nowo_user_kit.conflict_response.status` | null | `409` or `503` (503 keeps the 1.20 `ServiceUnavailableHttpException`). Null: transaction-doctrine's `conflict_response.status` when its bundle is registered, else 409. |
| `nowo_user_kit.conflict_response.retry_after` | null | Seconds of `Retry-After` on `CONFLICT_001`. Null: transaction-doctrine's `conflict_response.retry_after` when its bundle is registered, else 1. |
| `nowo_user_kit.conflict_response.platform_answer` | null | `true`: transaction-doctrine's platform answer; `false`: your error rendering; both always win. Null (auto): the platform answer only when `nowo_transaction_doctrine.conflict_response.map_transient_failures` is true (you already asked transaction-doctrine to answer every transient failure), else your rendering. Registering the bundle alone never changes your rendering. |
| `nowo_transaction_doctrine.conflict_response.*` | — | With the bundle registered: `status` and `retry_after` apply when UserKit's are null. When UserKit gives the platform answer with the bundle's `conflict_response` enabled and its own `status` / `retry_after` null, the bundle's responder shapes it (`template`, `json_error`, `json_path_prefixes`, your `ConflictResponseFormatterInterface` services). |

| transaction-doctrine | `platform_answer: true` | `false` | null (auto) |
| --- | --- | --- | --- |
| not registered | platform (plain English) | your rendering | your rendering |
| registered, default config | platform | your rendering | your rendering |
| registered, `conflict_response.enabled: false` | platform (bundle's template) | your rendering | your rendering |
| registered, `map_transient_failures: true` | platform | your rendering | platform |

**Priority.** `StorageFailureSubscriber` listens on `kernel.exception` at **-72** (audit TH UK-3): the bundle that owns
the route answers first in its own shape (AuthKit's subscriber at -64 answers a UserKit failure raised on its sign-in
routes), and Symfony renders at -128. With `map_transient_failures: true`, transaction-doctrine's responder (+64)
answers before both.
| `sessions.limit` | none | The named lock is taken only with a limit. |
| `doctrine.dbal.use_savepoints: true` | — | Required with DBAL 3 (DBAL 4 always uses savepoints). |

UserKit's writes always go through `nowo_user_kit.transaction_runner`, never through a runner your application binds
to `TransactionRunnerInterface` (it would drive your entity manager). Do not list UserKit's routes in
transaction-doctrine's `controller_routes` write units: the erasure and the sign-in have side effects and are not
replayed.

## Workers and entity managers

- UserKit never resets, clears or flushes your entity manager on its own. After a failed erasure only the rows it
  touched are detached; a manager a failed flush closed stays closed for the request and is replaced at the next
  boundary (`ClosedManagerRecovery`, `UnitOfWorkBoundarySubscriber`).
- Every service is stateless or resets itself: FrankenPHP worker mode with the kernel kept alive
  (`FRANKENPHP_RESET_KERNEL=false`), standard and DDD hosts (the session registry and the epoch can live on a dedicated
  entity manager, `sessions.doctrine.entity_manager`).
- A named lock whose release fails closes the connection, so a worker never carries it into its next request.

## Tests and CI

- **Load readiness** (required): `tests/Load` on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, each with DBAL 4 and DBAL 3,
  two connections on one server: deadlock in the erasure's nested flush, lock waits, first-row races inside a
  transaction, two-process sign-ins under a limit, a sign-in past the lock wait (409 + `CONFLICT_001`), an eviction
  committed before a lock wait (409 + `CONFLICT_002`, no `Retry-After`), two parallel uploads of one subject.
- **Session transactions on PostgreSQL, MySQL and MariaDB** (required).
- `tests/Unit/Transaction/ConflictCodeTest.php`: every lock-conflict kind gives `CONFLICT_001` + 409 + `Retry-After`,
  and `CONFLICT_002` without it after a commit; a unique violation is never a conflict.

## Residual risks

- **Commits made outside UserKit's own steps (audit TH UK-2).** UserKit marks `CONFLICT_002` only around its own
  multi-step autocommit paths. When your controller commits and then a UserKit call meets a lock conflict (for example
  a password change, then the session revocation), the answer is `CONFLICT_001` with `Retry-After`, as 1.20 answered 503
  with `Retry-After`. Request-level commit detection is covered from nowo-tech/transaction-doctrine 1.6 (UserKit adopts it
  with that version); until then, make such controllers idempotent or run them as one write unit.

- **The session limit's lock inside your transaction (audit QM-L3).** The named lock is a session lock released right
  after the registration. When UserKit's connection is inside a transaction your application opened at sign-in, the
  registered row stays invisible to the next sign-in until your transaction commits, so two sign-ins can both count
  below the limit. Sign users in outside a transaction.
- **A host pointer store without `replace()`.** A store implementing only `OwnerImagePointerStoreInterface` is read,
  then written: under MySQL's REPEATABLE READ inside your transaction, a picture another request stored meanwhile can
  stay live until you replace it again. Implement `LockingOwnerImagePointerStoreInterface::replace()`.
- **Last activity across workers.** Two workers keep separate throttle maps, so two requests of one user can both
  write; the column only moves forward, so the value stays correct. A custom setter on an unmapped field has no such
  guard.
- **Topology.** PgBouncer in transaction or statement pooling is not supported for UserKit's connection with a session
  limit; on Galera / PXC send UserKit's writes to one writer node (see [HIGH-LOAD](HIGH-LOAD.md#database-topology)).
- **`CONFLICT_002` is conservative.** It is given whenever an earlier autocommit statement of the same call could have
  committed (an eviction, a revocation, an epoch bump), even when repeating the request would be harmless.
