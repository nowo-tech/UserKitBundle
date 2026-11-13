# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.17.0](#to-1170)
- [To 1.17.0](#to-1170-1)
  - [To 1.17.0: `sessions.doctrine.exact_owner_collation` (optional, one query less per session list)](#to-1170-sessionsdoctrineexact_owner_collation-optional-one-query-less-per-session-list)
- [To 1.16.3](#to-1163)
- [To 1.16.2](#to-1162)
- [To 1.16.1](#to-1161)
  - [To 1.16.1: owner columns of the session registry (look-alike owners)](#to-1161-owner-columns-of-the-session-registry-look-alike-owners)
- [To 1.16.0](#to-1160)
  - [Request attributes for the device id provider (opt-in)](#request-attributes-for-the-device-id-provider-opt-in)
- [To 1.15.0](#to-1150)
- [To 1.14.2](#to-1142)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
- [To 1.13.2](#to-1132)
- [To 1.13.1](#to-1131)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.1](#to-1111)
- [To 1.11.0](#to-1110)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
  - [Enabling the session registry](#enabling-the-session-registry)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
- [To 1.1.13](#to-1113)
- [To 1.1.12](#to-1112)
- [To 1.1.11](#to-1111-1)
- [From 1.1.9 to 1.1.10](#from-119-to-1110)
- [From 1.1.8 to 1.1.9](#from-118-to-119)
- [1.1.8](#118)
- [1.1.7](#117)
- [1.1.6](#116)
- [1.1.5](#115)
- [1.1.4](#114)
- [1.1.3](#113)
- [1.1.2](#112)
- [1.1.1](#111)
- [1.1.0](#110)
- [1.0.x](#10x)
  - [1.0.3](#103)
  - [1.0.2](#102)
  - [1.0.1](#101)
  - [1.0.0](#100)

## Unreleased

## To 1.17.0

From **1.16.3**:

```bash
composer update nowo-tech/user-kit-bundle
```

## To 1.17.0

From **1.16.3**:

```bash
composer update nowo-tech/user-kit-bundle
```

Nothing to do. One new option, off by default; behaviour is unchanged until it is turned on.

### To 1.17.0: `sessions.doctrine.exact_owner_collation` (optional, one query less per session list)

The session list reads candidate rows and decides the owner in PHP (`===`), because a MySQL / MariaDB table still on a
folding or PAD SPACE collation matches `Alice`, `álice` and `alice ` as `alice`. Where the owner columns have already
been migrated to the binary NO PAD collation — the `ALTER` of
[To 1.16.1](#to-1161-owner-columns-of-the-session-registry-look-alike-owners) — the SQL match is byte-exact and that
candidate scan is redundant:

```yaml
# config/packages/nowo_user_kit.yaml
nowo_user_kit:
    sessions:
        doctrine:
            exact_owner_collation: true
```

A signed-in request that renders the session list then runs **two** registry queries instead of three (the revocation
state and the rows; the candidate `SELECT id, user_type, user_id … LIMIT 200` is gone). Nothing else changes: the other
owner statements are single round trips already.

**Precondition: the columns must actually be binary NO PAD.** Check before turning it on (add your
`sessions.doctrine.table_prefix`):

```sql
SELECT column_name, collation_name
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'user_kit_session' AND column_name IN ('user_type', 'user_id');
```

Both rows must read `utf8mb4_0900_bin` (MySQL 8.0+), `utf8mb4_nopad_bin` (MariaDB) or `utf8mb4_bin` (MySQL 5.7). If
they do not, run the `ALTER` of [To 1.16.1](#to-1161-owner-columns-of-the-session-registry-look-alike-owners) first.
PostgreSQL and SQLite compare exactly, so the option is safe there as soon as the column carries no explicit folding
collation (`COLLATE NOCASE` on SQLite, a `CITEXT` column or a non-deterministic `CREATE COLLATION` on PostgreSQL).

**Turning it on without the `ALTER` signs users out; it never matches them to someone else.** The `===` re-check runs
in both paths. On a column that still folds, SQL hands back a look-alike's row, the re-check drops it and the lookup
finds nothing: the user sees an empty session list and, where a missing row ends the session, is signed out. That is
the fail-closed outcome, and it is why the option is safe to leave off — and safe to turn off again at any time, with
no schema change and no data migration. **Leave it `false`** unless the check above passes; the default costs one
indexed `SELECT` and is correct everywhere.

There is **no auto-detection**, by design. Reading the collation from `information_schema` costs one round trip per
database connection. On a host that opens a connection per request (PHP-FPM, mod_php) that is exactly the query the
option saves, so detection would be a wash at best and a loss in practice (`information_schema` is dearer than the
indexed candidate scan). The explicit option gives the whole saving at no cost, and the `===` re-check already makes a
wrong answer harmless.

## To 1.16.3

From **1.16.2**:

```bash
composer update nowo-tech/user-kit-bundle
```

From **1.16.2**, nothing to do beyond `composer update nowo-tech/user-kit-bundle`. Hosts on MySQL / MariaDB with Doctrine session
storage and DBAL 4.3 or 4.4 (or ORM 3.0-3.6 with DBAL 4.3+) get working `schema:update`, `doctrine:migrations:diff` and
`schema:validate` again: they crashed with `BadMethodCallException` or an undefined-method error. No schema change.

## To 1.16.2

From **1.16.1**:

```bash
composer update nowo-tech/user-kit-bundle
```

## To 1.16.1

From **1.16.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

### To 1.16.1: owner columns of the session registry (look-alike owners)

Target **1.16.1**, from **1.9.0–1.16.0** (only with `sessions.enabled`):

```bash
composer update nowo-tech/user-kit-bundle
```

The registry fix works without a migration: on MySQL / MariaDB every owner statement adds a byte comparison and every
row read back is re-checked with `===`, so `Alice`, `álice` or `alice ` no longer reach the sessions or the epoch of
`alice`. Migrate the columns anyway, so the database and its unique keys are exact too. PostgreSQL and SQLite need
nothing.

> **With `sessions.remember_me_epoch` enabled on MySQL / MariaDB, the `ALTER` of the epoch table is REQUIRED.** Until
> it runs, its folding unique key makes the epoch bump of an owner whose look-alike already has an epoch row fail: the
> sessions are still revoked, but the remember-me cookies of that owner stay valid and the call (a password change,
> "sign out everywhere", an erasure) ends with a `LogicException` (HTTP 500 in a request).

**Schema change (MySQL / MariaDB).** New tables get a binary NO PAD collation on `user_type` and `user_id`
(`OwnerCollationListener`). For existing tables, `doctrine:migrations:diff` produces the change, or run it by hand
(add your `sessions.doctrine.table_prefix`; skip the epoch table when `remember_me_epoch` is off):

```sql
-- MySQL 8.0+ (utf8mb4_0900_bin); MariaDB: utf8mb4_nopad_bin; MySQL 5.7: utf8mb4_bin
ALTER TABLE user_kit_session
    MODIFY user_type VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
    MODIFY user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE user_kit_session_epoch
    MODIFY user_type VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
    MODIFY user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
```

Impact on existing data:

- **Integer or UUID owner ids** (Doctrine users with such ids, the common case): nothing changes; the `ALTER` cannot
  fail, since a binary collation only splits values a folding one merged.
- **Matching becomes case-, accent- and trailing-space-sensitive.** Rows are owned by exactly the id they were written
  with. A host whose resolver returns differently cased ids for the same person (for example `getUserIdentifier()` as
  typed at sign-in) must normalise them in a custom `sessions.owner.resolver`; otherwise each spelling is its own owner
  (its own sessions, limit and epoch).
- **Rows with a padded or invisible-character id** (`alice `, a zero-width space…) written by an earlier release are
  no longer reachable: such ids are refused now, so their users are not registered (the default resolver returns
  null; revocations for them follow `sessions.owner.on_unresolved`). Their rows age out with
  `nowo:user-kit:sessions:purge`; to delete the space-padded ones at once (a byte comparison, since PAD SPACE would
  call `alice ` and `alice` equal):
  `DELETE FROM user_kit_session WHERE CAST(user_id AS BINARY) <> CAST(TRIM(user_id) AS BINARY)`.
- **Look-alike owners that already exist** can be listed before the `ALTER` (the epoch table holds one row per folded
  group under the old collation, so check the registry):

  ```sql
  SELECT user_type, LOWER(TRIM(user_id)) AS folded, COUNT(DISTINCT CAST(user_id AS BINARY)) AS spellings
  FROM user_kit_session GROUP BY user_type, LOWER(TRIM(user_id)) HAVING spellings > 1;
  ```

  Each spelling keeps its own rows after the `ALTER`. An epoch row created by one look-alike was shared by the whole
  group; after the `ALTER` it belongs to the exact spelling stored in it, and the others start without an epoch (their
  older remember-me cookies are accepted again until they next sign out everywhere). Bump them if in doubt
  (`UserSessionManager::revokeAll()` for each affected user).
- **Until the epoch table is migrated**, its unique key still merges look-alikes: the first epoch bump of an owner
  whose look-alike already has a row throws a `LogicException` instead of moving the other owner's epoch. The
  owner's session rows are revoked first anyway (a password change still signs every other session out), but its
  remember-me cookies are not invalidated and the exception reaches the caller, logged as an error. This is why the
  `ALTER` is required with `remember_me_epoch`.
- **Custom owner resolvers** that build a `SessionUser` with such an id now get an `InvalidArgumentException`; return
  null instead (`SessionUser::isUsable()` tells).

## To 1.16.0

From **1.15.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

Target **1.16.0**, from **1.15.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

Nothing to change: the new `sessions.client.context_attributes` allowlist defaults to `[]`. Two hardening changes
to the `DeviceContext` a `DeviceIdProviderInterface` receives:

- the remember-me cookies (every firewall's and `sessions.remember_me_cookies`) are no longer in it: a provider that
  read one must use a dedicated device cookie instead;
- header and cookie values are kept outside the object's properties, so dumps no longer show them; a `clone` of a
  context has no headers, cookies or attributes (build a new context instead of cloning one).

### Request attributes for the device id provider (opt-in)

A resolver migrating from the deprecated `DeviceIdResolverInterface` that read a request attribute (for example
DeviceIntelligence's `_device`) names it and reads it with `DeviceContext::attribute()`:

```yaml
nowo_user_kit:
    sessions:
        client:
            device_id_resolver: App\Session\DeviceIdProvider
            context_attributes: ['_device']
```

```php
public function deviceIdFor(DeviceContext $context): ?string
{
    $device = $context->attribute('_device'); // raw value, objects included; null when absent
    // ...
}
```

Name only what your provider needs: an attribute you name is given as is (your responsibility). The values never
appear in a dump, `var_export()`, `(array)` cast or `json_encode()` of the context, and a clone has none.

## To 1.15.0

From **1.14.2**:

```bash
composer update nowo-tech/user-kit-bundle
```

Minor release (1.15.0), no breaking change; nothing to do for a standard host.

**Deprecations (removed in 2.0)**

| Deprecated | Replacement |
|---|---|
| `UserSessionsInterface::currentHash($requestOrSession)` | `$manager->sessionHash($manager->sessionRef($requestOrSession))`; type application services against `SessionHashesInterface` |
| `Sessions\Client\DeviceIdResolverInterface::deviceId(Request)` | `Sessions\Client\DeviceIdProviderInterface::deviceIdFor(DeviceContext)`; the context leaves out the session cookie and the `Authorization`, `Cookie`, `Proxy-Authorization` headers (`$request->headers->get('X')` becomes `$context->header('X')`, `$request->cookies->get('x')` becomes `$context->cookie('x')`, `$request->getClientIp()` becomes `$context->clientIp`) |

Both deprecated forms keep working and trigger a `E_USER_DEPRECATED` notice (`currentHash()` on each call, the legacy
resolver once when the session client factory is built). `sessionHash(sessionRef($x))` returns the same hash as
`currentHash($x)` for every started session. In 2.0, `UserSessionsInterface` extends `SessionHashesInterface`: if you
implement `UserSessionsInterface` yourself (a hand-written fake), add `sessionHash()` now.

**Migrating a DDD / XML-mapped host to the shipped embeddable mapping (optional).** Replace the `attribute` mapping
entry for `Nowo\UserKitBundle\Model` with the `xml` entry of
[USAGE](USAGE.md#ddd-host-xml-mapping-of-the-embeddables-and-trait-fields). The columns are identical, so
`doctrine:schema:validate` / `doctrine:migrations:diff` show no change. Keep exactly one of the two entries.

## To 1.14.2

From **1.14.1**:

```bash
composer update nowo-tech/user-kit-bundle
```

## To 1.14.1

From **1.14.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration change. If your `last_activity.field` is not a mapped column (your setter writes another property),
last activity is now written with a DQL `UPDATE` of the columns that setter changed, instead of a `flush()` of the whole
EntityManager. Your other pending changes are no longer committed on `kernel.request`, and Doctrine lifecycle callbacks
/ entity listeners no longer run for this write (as already the case for a mapped field since 1.6.0). A setter that
writes no mapped column is not persisted. A user that is not persisted yet is not written by the subscriber.

## To 1.14.0

From **1.13.2**:

```bash
composer update nowo-tech/user-kit-bundle
```

Target 1.14.0. Additive; no configuration change is needed.

- **Non-entity security user** (a `SecurityUser` adapter around a domain user): implement
  `Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface` so the adapter and the Doctrine entity resolve to
  the same `SessionUser`, and set `nowo_user_kit.sessions.owner.resolver` to its service id. The `type` must be unique
  per identity space (use the domain class name), never shared by two `user_class`es whose ids can collide.
- **Switching the resolver changes every owner key.** Session rows follow (the session marker keeps the old key, and the
  row is re-registered under the new owner on the next request), but the remember-me epoch does not: the new owner starts
  without one, so remember-me cookies revoked earlier ("sign out everywhere", password change) would be accepted again.
  When you switch, invalidate every remember-me cookie: rotate the `remember_me` secret of each firewall, or empty the
  token table of the persistent token provider. Then delete the epoch rows of the old owners (`user_kit_session_epoch`,
  or your `remember_me_epoch.store`); `sessions:purge` does not remove them.
- A revocation for a user without owner now logs a warning (`sessions.owner.on_unresolved: log`). Set `exception` to make
  it fail instead (the Doctrine listeners then abort the flush).
- Application services can type against `UserSessionsInterface`, `PersonalDataExporterInterface`,
  `PersonalDataErasureInterface` and `UserPresenceResolverInterface` instead of the final classes.
- `PersonalDataEraser::erase()` called inside your own open transaction now joins it: the erasers' writes are committed or
  rolled back with your transaction, and an eraser exception no longer closes your entity manager. That exception marks
  your transaction rollback-only (a partial erasure cannot be committed), and the returned list is final only once you
  commit.
- With `on_unresolved: exception`, do not swallow `UnresolvedSessionOwnerException`: thrown from `EntityManager::remove()`,
  cascaded children are already scheduled for removal.

## To 1.13.2

From **1.13.1**:

```bash
composer update nowo-tech/user-kit-bundle
```

On MySQL and MariaDB the session touch fallback now takes a shared lock (`FOR SHARE` on MySQL 8.0+, `LOCK IN SHARE MODE` on MariaDB and MySQL 5.7), chosen from the DBAL platform. Declare the real server in `serverVersion` (for MariaDB, the `-MariaDB` suffix): a MariaDB or MySQL 5.7 server declared as MySQL 8+ gets `FOR SHARE`, a syntax error there, and the touch then answers 500 (fails closed; audit IY-I3).

## To 1.13.1

From **1.13.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

From **1.13.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration or schema change.

- **MySQL hosts that set `PDO::MYSQL_ATTR_FOUND_ROWS` only as a workaround** for random sign-outs of parallel requests
  (the registry touch read a same-second no-op UPDATE as a missing session) can drop that driver option after
  upgrading: the touch no longer depends on the affected-row count. Keep it if something else in the host relies on it.

## To 1.13.0

From **1.12.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration or schema change. REQ-SEC-004: Pass (good) / Low, Appendix HU.

- **Hosts that change a password inside their own transaction.** `UserSessionsRevokedEvent` with reason
  `password_changed` now fires after the outermost commit, not during the flush, and does not fire on rollback.
  A listener on it no longer takes part in that transaction: its database writes are its own, and a failure in it no
  longer rolls the password change back. With no transaction open (a plain `flush()`) it fires as before, before
  `flush()` returns.
  A listener's exception can surface from a later `flush()`, from `PersonalDataEraser::erase()` after the erasure
  committed, or from the response of the request. The password change stays, and later listeners on the event do not run.
- **`revocation.store: cache`.** Inside a transaction the mark waits for the outermost commit, and goes with a rollback.
  The registry rows are written in the transaction as before.
- **When the waiting effects run.** After a commit they run on the next of: `postFlush`, `kernel.response` (priority
  32), the end of a Messenger message, and last `kernel.terminate` and `console.terminate` (priority -4096). The first
  two wait while another transaction is open. A standalone script that boots no kernel event should call
  `UserSessionManager::releaseHeldRevocations()` after its commit.
  Between the commit and that point the rows are already revoked, so a signed-in request finds out at its next touch at
  the latest. With the registry on another connection the rows wait too, so a process that dies in that gap revokes
  nothing. `kernel.reset` runs what is left; a fatal or an `exit` in that gap loses it.
  `kernel.terminate`, `console.terminate` and the end of a message run them even with a transaction still open, and a
  listener's writes then follow that transaction. With `autoCommit: false` one always is open, so a `password_changed`
  listener must commit its own writes.
- **Other reasons do not wait.** `UserSessionsRevokedEvent` for `everywhere`, `admin`, `account_disabled`, an erasure and
  so on still fires inside the transaction, so a listener's writes follow it, and it still fires if that transaction
  then rolls back.
- **A connection you built yourself.** Only connections from DoctrineBundle get the middleware. On any other, or with
  the middleware removed, everything runs at once as in 1.12.
- **AuthKit.** Upgrade AuthKit with this release. `SocialHandlerSignInRollback::restore()` must call `adopt()` for GY-L7
  and HP-I2 to close: without it, a revocation that waits on the row during a failed sign-in is lost until the next
  sign-out. With AuthKit 1.38.0, a first social link in add mode also loses the account just added to the switcher,
  because `password_changed` now fires after the sign-in; it can be added again.
- **DBAL 3.** `Connection::commit()` returns null with the middleware. Do not test its return value.
  On PostgreSQL with DBAL 3 the registry does not work, as in earlier releases: ORM maps the id to a sequence and the
  registry's insert has no id. Use DBAL 4 on PostgreSQL.

## To 1.12.0

From **1.11.1**:

```bash
composer update nowo-tech/user-kit-bundle
```

No breaking change. An eraser that looks data up by the user identifier should read `$request->identifier()` instead
of `$request->user->getUserIdentifier()`: the latter is already anonymised when a higher-priority identity eraser ran.
Code that builds `ErasureRequest` itself keeps working (the new argument is optional).

## To 1.11.1

From **1.11.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

From **1.11.0**: `sessionState()` / `sessionStates()` answer `revoked` (reason `signed_out`) for a session without a
registry row with the database revocation store (it was `unknown`). Callers that treated `unknown` as "can come back"
(AuthKit 1.34 multi-account before its audit fixes) must treat only `expired` that way.

## To 1.11.0

From **1.10.1**:

```bash
composer update nowo-tech/user-kit-bundle
```

From **1.10.1**: no action. The new `UserSessionManager` methods (`sessionState()`, `sessionStates()`,
`endSession()`, `rekey()`) are additions; nothing changes for existing hosts, no schema change. AuthKitBundle 1.34
multi-account sign-in requires them.

Pending sign-ins (second factor): tokens implementing `Nowo\UserKitBundle\Security\PendingAuthenticationTokenInterface`
(or with the token attribute `nowo.authentication_pending` = `true`) are registered once final instead of at
`LoginSuccessEvent`. No other token is affected. A custom pending token must not extend Symfony's `AbstractToken`
with an empty role list (the firewall would treat it as a changed user); implement `TokenInterface` directly.

## To 1.10.1

From **1.10.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

From **1.10.0** (security patch, no configuration or schema change):

- `sessions.remember_me_epoch`: the deferred re-issue marker (`RememberMeEpoch::PENDING_ATTRIBUTE`) stays in the session until the cookie is re-issued; a remember-me cookie issued during the revocation's second is re-issued from the epoch second on. Nothing to change in the host.
- After an incident, or when you shorten `remember_me.lifetime`, rotate the remember-me secret (`remember_me.secret`, default `kernel.secret`): see the F4 window in [SECURITY.md](SECURITY.md).

## To 1.10.0

From **1.9.1**:

```bash
composer update nowo-tech/user-kit-bundle
```

No breaking change: every new key is opt-in or keeps the 1.9 behaviour; new constructor parameters and method
parameters are optional and appended last.

- **Remember-me epoch** (`sessions.remember_me_epoch.enabled: true`): with the default store, create the new table
  `user_kit_session_epoch` (`doctrine:migrations:diff` after enabling it; prefixed with `doctrine.table_prefix`). With
  `remember_me_epoch.store`, implement `Sessions\Epoch\SessionEpochStoreInterface` instead (no table). After enabling,
  existing remember-me cookies keep working until the next revokeAll / revokeOthers of their user.
- **Trusted devices**: return `new SessionLimit($max, $policy, maxTrustedDevices: $n)` from your resolver (or set
  `limit.max_trusted_devices`) and read it with `UserSessionManager::limitFor($user)`; enforce it where the trusted
  devices live.
- **Host-defined reasons**: pass `customReason: 'link'` (and `context: [...]`) to the revoke methods; add
  `sessions.signed_out.link` to your `NowoUserKitBundle` translations for a specific flash.
- **JSON clients**: set `sessions.early_check.json_response: true` to answer XHR / JSON requests of a signed-out session
  with `401 {"error":"session_signed_out"}` before the firewall.
- **Retention / deleted users**: `sessions.purge.revoked_retention` (seconds) and `sessions.user_removal.delete_sessions`
  (or call `UserSessionManager::forgetUser($user)` before deleting a user yourself).
- Listeners of `UserSessionsRevokedEvent` that store the reason should prefer `$event->reasonCode()` (the host reason
  when one was given; `$event->reason` stays the built-in category).

## To 1.9.1

From **1.9.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

From **1.9.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

- **Twig Extra is now a declared dependency** (REQ-TWIG-004): `twig/extra-bundle` and `twig/string-extra` (`^3.12`)
  move into `require`. They were already installed through UiKit, so the lock usually does not change. Symfony Flex
  enables `Twig\Extra\TwigExtraBundle\TwigExtraBundle`; without Flex add it to `config/bundles.php` (see
  [Installation](INSTALLATION.md#twig-extra)).
- No configuration or code change otherwise.

## To 1.9.0

From **1.8.x**:

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration or code change is required: the new session registry is **off** (`nowo_user_kit.sessions.enabled:
false`) and registers nothing until you enable it.

- **New Composer dependencies**: `nowo-tech/ui-kit-bundle` (^2.20), `nowo-tech/form-kit-bundle` (^2.17),
  `symfony/twig-bundle`, `symfony/form`, `symfony/security-csrf`, `symfony/routing`, `symfony/console`, `doctrine/dbal`.
  Symfony Flex registers `NowoUiKitBundle` and `NowoFormKitBundle` in `config/bundles.php`; without Flex add them by
  hand (they are inert until used).

### Enabling the session registry

1. Configure it (see [CONFIGURATION](CONFIGURATION.md#session-registry-sessions)):

   ```yaml
   # config/packages/nowo_user_kit.yaml
   nowo_user_kit:
       sessions:
           enabled: true
           idle_lifetime: 86400   # >= framework.session.gc_maxlifetime
           web_ui:
               enabled: true      # optional pages
   ```

2. Create the table (`user_kit_session`, or `<sessions.doctrine.table_prefix>user_kit_session`). The bundle maps its
   entity automatically when the feature is on, so Doctrine Migrations picks it up:

   ```bash
   php bin/console doctrine:migrations:diff
   php bin/console doctrine:migrations:migrate
   ```

   Do not use `doctrine:schema:update --force` in production.

3. Pages only: import the routes and protect the prefixes in your firewall:

   ```yaml
   # config/routes/nowo_user_kit_sessions.yaml
   nowo_user_kit_sessions:
       resource: .
       type: nowo_user_kit_sessions
   ```

   ```yaml
   # config/packages/security.yaml
   security:
       access_control:
           - { path: ^/account/sessions, roles: ROLE_USER }
           - { path: ^/admin/user-sessions, roles: ROLE_ADMIN }
   ```

4. Schedule the retention purge daily: `php bin/console nowo:user-kit:sessions:purge`.

5. **1.9-dev testers** (who enabled the feature from `main` before the release): the unique key changed from
   `uniq_user_kit_session_hash` (`session_hash`) to `uniq_user_kit_session_owner` (`session_hash`, `user_type`,
   `user_id`) and the session marker became one entry per firewall context. Regenerate the migration
   (`doctrine:migrations:diff`) — or drop and recreate `user_kit_session`; existing sessions are registered again on
   their next request (old markers are ignored).

6. With `revocation.store: cache`, set `revocation.cache_pool` to a pool shared by every web server (Redis,
   Memcached, PDO); per-server pools such as the default `cache.app` are refused, also behind tag-aware / chain /
   proxy wrappers. Remember-me cookies are read from your firewalls (also with a custom `remember_me.service`); list
   only extra cookies under `sessions.remember_me_cookies`.

Sessions that were already signed in are registered on their next request. With the feature on, a password change of a
profile user signs out its other sessions (`sessions.password_change.revoke_sessions: false` to keep the old
behaviour), and `RegistrySessionInvalidator` replaces the no-op `DefaultSessionInvalidator` unless you aliased your own
`SessionInvalidatorInterface`.

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration or code change is required.

- `ProfileRegistry` and `LastActivitySubscriber` now implement `ResetInterface`, so Symfony's `services_resetter` calls their `reset()` between requests (FrankenPHP worker / kernel reuse). Observable behaviour is unchanged: profile resolution is recomputed after a reset, and open last-activity throttle windows are kept (only expired ones are evicted).
- If you build these services by hand (tests, custom DI), nothing changes: constructors are the same.

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

No schema change: `preferred_content_width` is a string column and `wider` fits it. Hosts that size the centered
widths with CSS on `data-content-width` add a rule for `wider` (between `wide` and `full`).

## To 1.7.0

From **1.6.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

## To 1.6.0

From **1.5.0**:

```bash
composer update nowo-tech/user-kit-bundle
```

## To 1.5.0

From **1.4.0** — opt-in GDPR export / erasure SPI.

```bash
composer update nowo-tech/user-kit-bundle
```

- Nothing changes until you set `nowo_user_kit.personal_data.enabled: true` (see [CONFIGURATION](CONFIGURATION.md#personal-data-gdpr)).
- When enabled, every service implementing `PersonalDataExportProviderInterface`, `PersonalDataEraserInterface` or `ErasureGuardInterface` is tagged automatically; the built-in sections / erasers come after host ones (priority `-100` and below). Set `builtin_providers` / `builtin_erasers` to `false` to drop them.
- Replacing a hand-written exporter changes the document shape to `{schema, version, exported_at, sections}`: bump your `version` (or `schema`) so consumers can tell the formats apart.
- `LastActivityTrait` gained `clearLastActivityAt()`; if your entity already declares a method with that name, PHP keeps your method.

## To 1.4.0

From **1.3.0** — opt-in UI preferences and product-tour progress models.

```bash
composer update nowo-tech/user-kit-bundle
```

- No configuration change and no schema change unless you adopt the new models.
- To use the ready-made `UiPreferences` / `ProductTourProgress` embeddables, add the Doctrine mapping entry for `Nowo\UserKitBundle\Model` (see [CONFIGURATION](CONFIGURATION.md#doctrine-mapping-for-the-kit-embeddables)); the traits need no mapping entry.
- **Adopting over existing columns:** the column names, types and lengths match the common `preferred_*` / `product_tour_seen_*` layout (Symfony Beacon). Embed with `columnPrefix: false` (or `use` the traits in your existing embeddable) and run `doctrine:schema:validate`: no migration is expected. Remove your own copies of the properties first (a class cannot declare a property that a trait it uses also declares with a different default).
- With `twig: false`, `user_ui_html_attributes()` is not registered either.

## To 1.3.0

From **1.2.0** — AuthKit user_class resolved in prepend() regardless of bundle order; Symfony >=7.4.

```bash
composer update nowo-tech/user-kit-bundle
```

- **Symfony 7.4+ required** by Composer (was `^7.0`; 7.4 was already the documented/tested minimum).
- No configuration changes. With AuthKit installed, omitting `user_class` on the default profile now works regardless of bundle order in `config/bundles.php`.

## To 1.2.0

From **1.1.13** — security: AccountStatusUserChecker enforced on every firewall; session listener attached.

```bash
composer update nowo-tech/user-kit-bundle
```

- **Security:** `AccountStatusUserChecker` is now actually attached to every security-enabled firewall (the previous global `security.user_checker` tag was ignored by SecurityBundle). Disabled accounts that could log in before are now rejected with `DisabledException`. Remove any manual firewall `user_checker: Nowo\UserKitBundle\Security\AccountStatusUserChecker` workaround (harmless if kept); a custom firewall `user_checker` keeps running alongside UserKit’s checker.
- **Fixed:** with `invalidate_sessions_on_disable: true`, `AccountDisabledListener` is now attached to the profile `user_class` and calls `SessionInvalidatorInterface`. The default `DefaultSessionInvalidator` is a no-op; alias the interface to your own implementation to really clear sessions (see [USAGE](USAGE.md#session-invalidation-on-disable)).

## To 1.1.13

From **1.1.12** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/user-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.1.12

From **1.1.11** — dependency refresh and PHPStan configuration only. No breaking changes. No application upgrade steps.

```bash
composer update nowo-tech/user-kit-bundle
```

## To 1.1.11

From **1.1.10** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/user-kit-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

This document describes how to upgrade between versions of User Kit Bundle.

## From 1.1.9 to 1.1.10

From **1.1.9** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration or entity changes required.

**Behaviour of `last_activity` (FrankenPHP worker / `FRANKENPHP_RESET_KERNEL` unset/false):**

- `lastActivityAt` is only updated on main requests behind a firewall with `security: true`. Classic and reset-enabled setups see no practical difference (no token outside those paths).
- A database error while storing the timestamp is logged (`warning`) instead of failing the request with HTTP 500; closed entity managers are reset via `ManagerRegistry` when available.
- The in-memory throttle is keyed by profile + user identifier, pruned, and capped at 10 000 entries per worker (`LastActivitySubscriber::MAX_THROTTLE_ENTRIES` / constructor `$maxThrottleEntries`).
- New optional constructor arguments (autowired): `$managerRegistry`, `$security`, `$logger`, `$maxThrottleEntries`. Manual instantiations in tests should pass them when asserting worker behaviour.

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## From 1.1.8 to 1.1.9

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/user-kit-bundle
```

## 1.1.8

From **1.1.7** — **no action required**.

```bash
composer update nowo-tech/user-kit-bundle
```

## 1.1.7

From **1.1.6** — **no action required**. Demos only: Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

## 1.1.6

From **1.1.5** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

**Behavior:** `AccountStatusUserChecker` now rejects disabled accounts in **`checkPreAuth`** (and still in `checkPostAuth`). This covers AuthKit magic login / social / QR, which call `Security::login()` and only run pre-auth.

Do **not** replace the firewall `user_checker` with a custom service unless you chain UserKit’s checker; overriding it can skip the tagged `AccountStatusUserChecker`.

Hosts that added a custom pre-auth-only disabled checker for AuthKit can remove it after upgrading to **1.1.6**.

## 1.1.5

From **1.1.4** — backward compatible for normal integrators.

```bash
composer update nowo-tech/user-kit-bundle
```

**Note:** `LastActivitySubscriber` and `UserPresenceResolver` now require a `Psr\Clock\ClockInterface` constructor argument. Symfony FrameworkBundle already provides the `clock` service (autowiring). If you instantiate these classes manually in tests or custom DI, pass a clock (e.g. `Symfony\Component\Clock\Clock` or `MockClock`).

## 1.1.4

From **1.1.3** or any earlier **1.x** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration, entity, or template changes required for application integrators. Repository demo operators can set `FRANKENPHP_MODE=classic` or `worker` (default) in `demo/symfony8/.env`; recreate the container after changing it. See [Demo with FrankenPHP](DEMO-FRANKENPHP.md).

## 1.1.3

From **1.1.2** or any earlier **1.x** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration, entity, or template changes required. This release adds repository git-hygiene (REQ-GIT-001), Code of Conduct, and CI documentation only. Application integrators are unaffected.

## 1.1.2

From **1.1.1**, **1.1.0**, or any **1.0.x** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration, entity, or template changes required. This release updates the repository demo only (`demo/symfony8` uses the recommended `profiles` layout). Integrators already on flat config or profiles layout are unaffected.

## 1.1.1

From **1.1.0** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration, entity, or template changes required. This release fixes the CI dependency matrix only (`doctrine/doctrine-bundle` ^2.10 on PHP 8.2–8.3, ^3.0 on PHP 8.4+).

## 1.1.0

From **1.0.3**, **1.0.2**, **1.0.1**, or **1.0.0** — backward compatible for single-entity setups.

```bash
composer update nowo-tech/user-kit-bundle
```

**No migration required** if you keep the flat configuration (`user_class` at root). It is normalized internally to a single `default` profile.

**What is new:**

- Multiple user entities can each have their own `account_status` and `last_activity` settings under `nowo_user_kit.profiles`.
- `UserPresenceResolver::isOnline($user, 'profile_name')` and `user_is_online(user, 'profile_name')` accept an optional profile name.
- Automatic profile resolution uses the authenticated entity class (cached O(1) lookup).

**Optional migration to profiles layout:**

```yaml
nowo_user_kit:
    default_profile: app_user
    profiles:
        app_user:
            user_class: App\Entity\User
            account_status:
                enabled: true
            last_activity:
                enabled: true
                online_threshold: 300
```

**Behavior note:** `AccountStatusUserChecker` now applies only to user classes registered in a profile with `account_status.enabled: true`. Unmapped classes are no longer checked implicitly.

## 1.0.x

### 1.0.3

From **1.0.2**, **1.0.1**, or **1.0.0** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration or entity changes required.

**What is new:**

- Bundle and Flex recipe YAML templates now include English comments describing each option. You may copy comments into your app's `config/packages/nowo_user_kit.yaml` when helpful; existing configs without comments keep working unchanged.
- Repository demos: only **`demo/symfony8`** remains (Symfony 8.1). If you relied on clone paths `demo/symfony7` or `demo/symfony8-php85`, use `demo/symfony8` instead (`make -C demo up`, default **http://localhost:8023**).

### 1.0.2

From **1.0.1** or **1.0.0** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration or entity changes required.

**What is new:**

- Five additional bundle translation files (`de`, `fr`, `it`, `nl`, `pt`) ship with the same keys as `en` / `es`. Existing app overrides under `translations/NowoUserKitBundle.*.yaml` continue to work unchanged.
- Integrators may override any locale; see [Usage — Translation overrides](USAGE.md#translation-overrides-req-i18n-001).

### 1.0.1

From **1.0.0** — backward compatible.

```bash
composer update nowo-tech/user-kit-bundle
```

No configuration, entity, or template changes required. Documentation and GitHub metadata only.

### 1.0.0

First stable release.

- **Requirements:** PHP **8.2+**, Symfony **^7.0 || ^8.0**, Doctrine ORM.
- **Install:** `composer require nowo-tech/user-kit-bundle`
- **Configure:** create `config/packages/nowo_user_kit.yaml` (see [Installation](INSTALLATION.md) and [Configuration](CONFIGURATION.md)).
- **User entity:** implement `AccountStatusInterface` and/or `LastActivityInterface`, or use the optional traits and ensure the configured field names match.
- **Security:** register the bundle's `UserChecker` on your firewall (automatic when `account_status.enabled: true` and Symfony auto-wires tagged checkers).
- **AuthKit:** if both bundles are installed, set the same `user_class`; UserKit may inherit it from `nowo_auth_kit.user_class`.

No prior versions exist; no migration steps are required.
