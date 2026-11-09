# Upgrading

## Table of contents

- [Unreleased](#unreleased)
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
