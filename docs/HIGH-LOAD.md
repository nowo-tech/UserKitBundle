# High load

How UserKit behaves when many requests write at once (Beacon spec 239), since **1.18.0**. Read this when you run
UserKit behind a busy site, in FrankenPHP worker mode with the kernel kept alive (`FRANKENPHP_RESET_KERNEL=false`), or
with Messenger consumers.

## Table of contents

- [What UserKit writes](#what-userkit-writes)
- [UserKit's own transaction runner](#userkits-own-transaction-runner)
- [503 and 409](#503-and-409)
- [Retries](#retries)
- [Races that are serialised](#races-that-are-serialised)
- [The application's entity manager](#the-applications-entity-manager)
- [Database topology](#database-topology)
- [Workers and boundaries](#workers-and-boundaries)
- [Testing on a real database](#testing-on-a-real-database)

## What UserKit writes

| Path | Writes | Since 1.18.0 |
| --- | --- | --- |
| Personal data erasure (`PersonalDataErasureInterface::erase()`) | Your erasers, then one flush of the user's manager, in one transaction | One unit of work of UserKit's runner (was `wrapInTransaction()`) |
| Session registry (request listeners, `UserSessionManager`, the sessions pages, the purge command) | One DBAL statement at a time (`INSERT`, `UPDATE`, `DELETE`), never a flush | Retried in autocommit; inserts in a savepoint inside a transaction |
| Remember-me epoch (`revokeAll()`, `revokeOthers()`, erasure) | One row per user, moved forward only | Same as the registry; the re-read after a lost first insert is a locking read |
| Concurrent-session limit (sign-in) | Count, evict, register | Under a named lock of the user |
| Last activity (`kernel.request`) | One DQL `UPDATE` of the user's column, throttled | Moves forward only; retried in autocommit; failures only logged |

UI preferences, profile settings and product tour progress are fields of your user entity: your application writes
them with its own flush. UserKit only resets them inside the erasure.

## UserKit's own transaction runner

`Nowo\UserKitBundle\Transaction\UserKitTransactionRunner` (service id `nowo_user_kit.transaction_runner`) implements
`Nowo\TransactionContract\TransactionRunnerInterface` on nowo-tech/transaction-doctrine. UserKit injects it **by its
class** into its own services. A runner your application binds to `TransactionRunnerInterface` (for example
DoctrineDeadlockRetryBundle's adapter) is never used for UserKit's writes: such a runner drives your entity manager,
would commit your pending work with an erasure and would reset or clear your manager after a UserKit failure.

A unit of work (the erasure):

1. runs in one transaction (a savepoint when your application already opened one on that connection);
2. runs the erasers, then flushes the user's manager **once**, inside the transaction;
3. after any failure leaves no transaction level of its own open: the levels are rolled back to where they were, and
   the connection is closed only when that rollback fails (a MySQL deadlock inside the nested flush, where the server
   already dropped the savepoint and `ROLLBACK TO SAVEPOINT` fails with 1305);
4. is not replayed: erasers and the listeners of `UserSessionsRevokedEvent` may have side effects.

UserKit never flushes your manager **before** a unit (to keep your pending work out of it). The flush inside the unit
is the erasure's own, as in every earlier version: if your application left other changes pending in the same manager,
the erasure's flush writes them too, and rolls them back with it on failure. Flush your own work first if that matters.

## 503 and 409

| Exception (namespace `Nowo\UserKitBundle\Transaction`) | When | HTTP answer |
| --- | --- | --- |
| `UserKitStorageFailure` (a `Nowo\TransactionContract\TransientStorageFailure`) | Deadlock, lock wait timeout, serialization failure, a named lock not obtained in 5 s | 503 + `Retry-After: 1` |
| `UserKitConflict` | The erasure lost a unique key race it cannot resolve (another request stored the same value first) | 409 |

`StorageFailureSubscriber` (`kernel.exception`, priority -64) replaces these two exceptions with
`ServiceUnavailableHttpException` / `ConflictHttpException` on **every** route, because a session listener or the
erasure can run on any of your pages. Your own exceptions are never touched. The messages are generic; the database
error stays in the previous exception, for your logs. Your error pages and JSON error format render the answer as usual.

In code that calls UserKit directly (a console command, a Messenger handler, an API controller with its own error
mapping), catch `TransientStorageFailure` (or `UserKitStorageFailure`) and retry later; catch `UserKitConflict` and
reload. Up to 1.17 the raw Doctrine exceptions (`DeadlockException`, `LockWaitTimeoutException`, a 1305
`DriverException`, `UniqueConstraintViolationException`) escaped instead; they are now the previous exception.

## Retries

- **Single statements in autocommit** (the registry, the epoch, the last activity): a statement that deadlocks or waits
  too long for a lock was rolled back on its own and is idempotent, so UserKit runs it again: 3 attempts, with a short
  random pause (up to 20 ms, then 40 ms) so two writers do not collide again in lockstep. Then `UserKitStorageFailure`.
- **Inside a transaction your application opened**: never replayed (the server may have rolled back your whole
  transaction). The failure surfaces at once as `UserKitStorageFailure`; let it reach your outermost transaction or
  retry policy.
- **The erasure**: one attempt (see above). Retry it from your side after the `Retry-After`, as a new request or a new
  message.

## Races that are serialised

- **Concurrent-session limit.** With `sessions.limit` configured, counting the user's sessions, evicting the oldest and
  registering the new one run under a named lock of the user (`GET_LOCK()` on MySQL / MariaDB,
  `pg_try_advisory_lock()` polled on PostgreSQL; no lock on SQLite, which has one writer). Up to 1.17 two parallel
  sign-ins of one user both counted the same sessions and both registered: one session over the limit.
  - `evict_oldest`: the second sign-in counts the first and evicts it.
  - `reject_new`: the limit is checked again under the lock. The sign-in that comes second is refused after its
    credentials were accepted: its session is signed out, the refusal is the flash message, and the browser goes back
    to the path it posted to (with form login's default, where `check_path` is `login_path`, the login form); a JSON
    client gets `401 {"error":"session_signed_out"}`.
  - A sign-in waits 5 s at most for the lock, then answers 503. Without a limit no lock is taken.
- **First row of a session or of an epoch.** A lost unique key race is resolved by reading the winner's row. Inside a
  transaction the `INSERT` runs in its own savepoint, so PostgreSQL no longer aborts the whole transaction (up to 1.17:
  `25P02 current transaction is aborted` on the next statement, which failed an erasure that raced a "sign out
  everywhere"). After a lost first epoch insert the row is re-read with a locking read, so MySQL's REPEATABLE READ
  snapshot no longer hides the winner (up to 1.17 that read as a look-alike collision and failed the erasure).
- **Last activity.** Two workers keep separate throttle maps, so two requests of one user can both write. The column
  only moves forward (`WHERE column IS NULL OR column < :value`), so the later write of an earlier request no longer
  moves the last activity back. A custom setter on an unmapped field writes the mapped fields it changed, as before,
  without that guard.

## The application's entity manager

After a failed erasure UserKit never resets nor clears your entity manager:

- the rows the unit added, flushed or changed are **detached** (their database state was rolled back): the user entity
  the erasers changed, for example. Reload them before you write them again;
- a row that points (to-one) at a detached row is detached too, otherwise your next flush would try to insert the
  detached row again; UserKit logs a warning with the count. Its pending changes are lost: reload it;
- every other row you hold stays managed **with its pending changes**: UserKit compares each row's field values with a
  copy taken before the unit, without asking the ORM for change sets. A lazy reference the erasure only loaded stays
  managed;
- in-place changes of a mutable object field (a `DateTime` modified in place) and changes of to-many collections made
  by an eraser without a flush are not seen by that comparison: return new objects from your erasers;
- a manager the ORM closed in a failed flush **stays closed** for the rest of the request, so your next flush fails
  loudly ("The EntityManager is closed") instead of silently writing nothing. It is replaced at the next boundary.

Inside a transaction your application opened, a failed erasure also marks that transaction rollback-only (as in 1.17):
roll it back.

## Database topology

The named lock of the session limit is a **session** lock, taken outside any transaction and released right after the
registration:

- **PgBouncer** in transaction or statement pooling mode is not supported for UserKit's connection when a session limit
  is configured (the lock and its release can reach different server sessions). Session pooling or a direct connection
  is fine.
- **ProxySQL** keeps the session after `GET_LOCK()` (no multiplexing for it): fine.
- **Galera / Percona XtraDB Cluster**: `GET_LOCK()` is local to a node; send UserKit's writes to one writer node.
- A lock release that fails (a network error, a statement timeout) closes the connection, which drops the lock with the
  session, so a worker never carries it into its next request. DBAL reconnects on the next statement.

**DBAL 3:** set `doctrine.dbal.use_savepoints: true` (DBAL 4 always uses savepoints). The erasure nested in your
transaction, and the inserts above, rely on them. On PostgreSQL with DBAL 3 the ORM gives the registry and epoch ids
a sequence (`AUTO`); since 1.18.0 UserKit's DBAL inserts take the next value from it (up to 1.17 they left the id out
and every registration failed with a NOT NULL violation).

## Workers and boundaries

- Every UserKit service is stateless or resets itself. `ClosedManagerRecovery` remembers the managers a failed write
  closed in the current request and replaces them at the next boundary: before the next main request, at
  `kernel.terminate`, `console.terminate`, and after each Messenger message (`UnitOfWorkBoundarySubscriber`), and on
  `kernel.reset`. Never in the middle of a request.
- UserKit's PHPStan configuration enables the four load rules of nowo-tech/phpstan-frankenphp 1.4
  (`flagRollbackAfterNestedBegin`, `flagFlushInHandWrittenTransaction`, `flagUnboundedRecursion`,
  `flagFlushWithoutClosedManagerRecovery`). Turn them on in your application too.

## Testing on a real database

`tests/Load` runs the races on a real server (CI job **Load readiness**: MySQL 8.4 and PostgreSQL 16, each with DBAL 4
and DBAL 3). Use a throwaway database: the setup drops everything in it and refuses a database name without a `test`,
`race`, `load` or `throwaway` part.

```bash
USER_KIT_LOAD_DATABASE_URL='mysql://root@127.0.0.1:3306/user_kit_load?serverVersion=8.4.0&charset=utf8mb4' \
  vendor/bin/phpunit --testsuite load
```

| Test | Up to 1.17 (same test against `main`) |
| --- | --- |
| `ErasureLoadTest::testADeadlockInTheErasuresNestedFlushIsTransientAndTheNextErasureWorks` | MySQL: raw `1305 SAVEPOINT DOCTRINE_2 does not exist` |
| `ErasureLoadTest::testALockWaitOnTheMemberRowIsTransientAndErasesNothing` | Raw `1205` (MySQL) / `55P03` (PostgreSQL) |
| `ErasureLoadTest::testAParallelFirstEpochBumpDoesNotFailTheErasure` | PostgreSQL: `25P02`; MySQL: false look-alike `LogicException` |
| `RegistryLoadTest::testATouchThatWaitedTooLongForTheRowIsRetried` | Raw `1205` / `55P03` |
| `RegistryLoadTest::testAnInsertThatLosesTheRaceInsideATransactionLeavesItUsable` | PostgreSQL: `25P02` |
| `SessionLimitRaceTest` (`evict_oldest`, `reject_new`; two processes) | Two live sessions under a limit of one |
