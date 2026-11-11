# Configuration

All options live under the `nowo_user_kit` root key.

A commented default template ships at `src/Resources/config/packages/nowo_user_kit.yaml` in the bundle repository (and in the Symfony Flex recipe). Copy or adapt it as `config/packages/nowo_user_kit.yaml` in your application.

## Table of contents

- [Profiles](#profiles)
  - [Resolving profiles at runtime](#resolving-profiles-at-runtime)
- [Legacy flat configuration](#legacy-flat-configuration)
- [Doctrine mapping for the kit embeddables](#doctrine-mapping-for-the-kit-embeddables)
- [Personal data (GDPR)](#personal-data-gdpr)
- [Session registry (sessions)](#session-registry-sessions)
  - [Keys](#keys)
  - [Example](#example)
  - [Tables](#tables)
  - [Routes and access control](#routes-and-access-control)
  - [FormKit profile](#formkit-profile)
- [AuthKit integration](#authkit-integration)
- [Translations](#translations)

## Profiles

Each **profile** maps a `user_class` to its own account-status and last-activity settings. Use multiple profiles when the application has more than one authenticated user entity (for example `App\Entity\User` and `App\Entity\Admin`).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `default_profile` | string | first profile key | Profile used when no name is passed to `UserPresenceResolver::isOnline()` or Twig `user_is_online()`. |
| `profiles` | map | `default` | Named profile definitions (at least one required). |
| `twig` | bool | `true` | Register the `user_is_online` and `user_ui_html_attributes` Twig functions when Twig is installed. |
| `personal_data` | map | see [Personal data](#personal-data-gdpr) | GDPR export / erasure SPI (since 1.5.0, opt-in). |

Each profile supports:

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `user_class` | string | — | FQCN of the user entity for this profile (required; must be unique across profiles). |
| `account_status.enabled` | bool | `true` | Apply `AccountStatusUserChecker` to this profile (wired automatically into every security-enabled firewall; `false` on all profiles removes it). |
| `account_status.field` | string | `enabled` | Enabled flag property or accessor. |
| `account_status.invalidate_sessions_on_disable` | bool | `false` | Attach a Doctrine `postUpdate` entity listener on `user_class` that calls `SessionInvalidatorInterface` when the account is disabled. The default invalidator is a no-op; see [USAGE](USAGE.md#session-invalidation-on-disable). |
| `last_activity.enabled` | bool | `false` | Persist last activity on authenticated requests for this profile. |
| `last_activity.field` | string | `lastActivityAt` | Last-activity timestamp property. |
| `last_activity.online_threshold` | int | `300` | Seconds within which the user is considered online (min `1`). |
| `last_activity.update_throttle` | int | `30` | Minimum seconds between DB writes (min `0`). |

Example with two profiles:

```yaml
nowo_user_kit:
    default_profile: app_user
    twig: true
    profiles:
        app_user:
            user_class: App\Entity\User
            account_status:
                enabled: true
                field: enabled
            last_activity:
                enabled: true
                online_threshold: 300
                update_throttle: 30
        admin:
            user_class: App\Entity\Admin
            account_status:
                enabled: true
                field: isActive
            last_activity:
                enabled: true
                field: lastSeenAt
                online_threshold: 60
                update_throttle: 15
```

### Resolving profiles at runtime

- **Automatic (recommended):** pass only the user object. The bundle resolves the profile from the entity class (O(1) lookup, with inheritance cache).
- **Explicit name:** pass a profile name as the second argument when thresholds or fields differ for the same property layout.

```php
$resolver->isOnline($user);              // profile from entity class
$resolver->isOnline($user, 'admin');     // force admin thresholds/fields
```

```twig
{{ user_is_online(user) }}
{{ user_is_online(admin, 'admin') }}
```

## Legacy flat configuration

The previous flat layout remains supported and is normalized internally to a single `default` profile:

```yaml
nowo_user_kit:
    user_class: App\Entity\User
    account_status:
        enabled: true
    last_activity:
        enabled: true
        online_threshold: 300
    twig: true
```

Prefer the `profiles` layout for new projects or when adding a second user entity.

## Doctrine mapping for the kit embeddables

The ready-made embeddables `Nowo\UserKitBundle\Model\UiPreferences` and `Nowo\UserKitBundle\Model\ProductTourProgress` (since 1.4.0) live outside your `src/Entity` mapping, so Doctrine needs a mapping entry for the kit model namespace:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        mappings:
            NowoUserKitBundle:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/vendor/nowo-tech/user-kit-bundle/src/Model'
                prefix: 'Nowo\UserKitBundle\Model'
```

DDD / XML-mapped hosts (1.15): register the shipped XML mapping instead (`type: xml`, `dir: '%kernel.project_dir%/vendor/nowo-tech/user-kit-bundle/src/Resources/config/doctrine/model'`, same prefix), never both; see [USAGE](USAGE.md#ddd-host-xml-mapping-of-the-embeddables-and-trait-fields).

Not needed when you only use the traits (`UiPreferencesTrait`, `ProductTourProgressTrait`) inside your own entity or embeddable. No other configuration key is involved: the preference columns are plain entity mapping. See [USAGE](USAGE.md#ui-preferences).

## Personal data (GDPR)

Since **1.5.0**. Opt-in; see [USAGE](USAGE.md#personal-data-export-and-erasure-gdpr).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `personal_data.enabled` | bool | `false` | Register `PersonalDataExporter` and `PersonalDataEraser`, and autoconfigure `PersonalDataExportProviderInterface`, `PersonalDataEraserInterface` and `ErasureGuardInterface`. |
| `personal_data.schema` | string | `nowo-user-kit/personal-data` | `schema` key of the export document. |
| `personal_data.version` | int | `1` | `version` key of the export document (min `1`); bump it when your document shape changes. |
| `personal_data.builtin_providers` | bool | `true` | Register the `account_status`, `last_activity`, `ui_preferences` and `product_tour` sections. |
| `personal_data.builtin_erasers` | bool | `true` | Register the `last_activity`, `ui_preferences` and `product_tour` erasers. |

```yaml
nowo_user_kit:
    personal_data:
        enabled: true
        schema: 'acme-account-export'
        version: 2
```

## Session registry (sessions)

Since **1.9.0**. Opt-in (`sessions.enabled: false` by default: hosts that do not enable it get no listener, table,
route or page). See [USAGE](USAGE.md#session-registry-and-limits) and [UPGRADING](UPGRADING.md#to-190).

### Keys

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `sessions.enabled` | bool (literal) | `false` | Register the registry, its request / login / logout listeners, the purge command, the Doctrine mapping of `user_kit_session`. |
| `sessions.hash_secret` | string | `%kernel.secret%` | HMAC key of the stored session-id hashes (and of the `hash` IP mode). After a change each row is re-keyed on its session's next request. |
| `sessions.idle_lifetime` | int (s, ≥ 60) | `86400` | A session idle longer than this is expired: signed out on its next request and purged. Keep it ≥ `framework.session.gc_maxlifetime`. |
| `sessions.touch_interval` | int (s, ≥ 1, < `idle_lifetime`) | `300` | `last_seen_at` is written at most once per this many seconds per session (the time of the last write is kept in the session, so the requests in between cost no write). |
| `sessions.remember_me_cookies` | list | `[]` | **Extra** cookies removed from the request and cleared (path `/`) when a revoked session is signed out. The remember-me cookie of every firewall is read from the security configuration (name, path, domain, secure, SameSite; with a custom `remember_me.service`, the name from the firewall's authenticator, cleared by the handler) and needs no entry here; a cookie that cannot be resolved fails the compilation and must be listed here. |
| `sessions.remember_me_epoch.enabled` | bool (literal) | `false` | Since 1.10. `revokeAll()` / `revokeOthers()` move the user's epoch to now and every remember-me cookie issued before it is refused (built-in signature and persistent handlers of every firewall; custom `remember_me.service` handlers are left alone). The kept session gets a fresh cookie on the same response. |
| `sessions.remember_me_epoch.store` | service id \| null | `null` | Service implementing `Sessions\Epoch\SessionEpochStoreInterface`; `null` = the bundle table `user_kit_session_epoch` (mapped only then). |
| `sessions.early_check.json_response` | bool (literal) | `false` | Since 1.10. XHR / `Accept: application/json` requests of a revoked, missing or idle session get `401 {"error":"session_signed_out"}` from the early check instead of going on as a guest. |
| `sessions.purge.revoked_retention` | int (s, ≥ 0) \| null | `null` | Since 1.10. Revoked rows are kept this long after their revocation by the purge (shorter or longer than live rows); `null` = the live-row rule. |
| `sessions.owner.resolver` | service id \| null | `null` | Since 1.14. Service implementing `Sessions\Owner\SessionOwnerResolverInterface`; `null` = Doctrine root class + id, else class + user identifier. The security user and the entity the Doctrine listeners see must resolve to the same owner. |
| `sessions.owner.on_unresolved` | `log` \| `exception` | `log` | Since 1.14. A revocation (`revoke*`, `forgetUser`, `password_change`, `user_removal`, account disabled) for a user without owner logs a warning, or throws `UnresolvedSessionOwnerException`. |
| `sessions.user_removal.delete_sessions` | bool (literal) | `false` | Since 1.10. After a Doctrine removal of a profile user, revoke and delete its rows and epoch (the table has no foreign key). |
| `sessions.flash` | bool | `true` | Add a `warning` flash ("You were signed out because…", `sessions.signed_out.<reason>`) to the signed-out browser. |
| `sessions.revocation.store` | `database` \| `cache` | `database` | Where each signed-in request checks its revocation. `database`: one indexed lookup of its row; works with any session handler and any number of servers; a missing row fails closed. `cache`: a marker in `cache_pool`, no query per request; the pool **must be shared by every web server** (Redis, Memcached, PDO…); a lost marker is caught by the throttled touch, so `touch_interval` is the worst-case revocation delay. |
| `sessions.revocation.cache_pool` | service id \| null | `null` | PSR-6 pool of the `cache` store. **Required** with `store: cache`; per-server adapters (filesystem — `cache.app` by default —, array, APCu, PHP files, null) are refused at compile time, also behind `TagAwareAdapter` / `ProxyAdapter` / `TraceableAdapter` / `ChainAdapter` when every tier is per-server. Unknown classes (custom pools, factories) are accepted: the check is a guard, not a proof. |
| `sessions.client.user_agent` | `label` \| `none` | `label` | `label` keeps the browser and OS **names** only (no versions, no raw user agent). |
| `sessions.client.ip` | `truncate` \| `hash` \| `none` | `truncate` | `truncate` keeps the network (`203.0.113.0/24`, `2001:db8:85a3::/48`); `hash` keeps `h:` + 16 hex of an HMAC of that network (not shown in the pages); `none` keeps nothing. |
| `sessions.client.ipv4_prefix` | `8` \| `16` \| `24` | `24` | Network kept for IPv4. |
| `sessions.client.ipv6_prefix` | `8` … `64` (multiple of 8) | `48` | Network kept for IPv6. |
| `sessions.client.device_id_resolver` | service id \| null | `null` | Service implementing `Sessions\Client\DeviceIdProviderInterface` (1.15; or the deprecated `DeviceIdResolverInterface`): device id stored per session; enables `UserSessionManager::revokeDevice()`. |
| `sessions.limit.max_sessions` | int ≥ 1 \| null | `null` | Live sessions a user may hold at once (`null` = unlimited). |
| `sessions.limit.policy` | `evict_oldest` \| `reject_new` | `evict_oldest` | Over the limit: sign the least recently active sessions out, or refuse the sign-in (authentication failure on the login form). |
| `sessions.limit.max_trusted_devices` | int ≥ 0 \| null | `null` | Since 1.10. Trusted devices a user may keep; **not enforced** by the bundle (no trusted-device store): carried in `SessionLimit` for the host. |
| `sessions.limit.resolver` | service id \| null | `null` | Service implementing `Sessions\Limit\SessionLimitResolverInterface` (per-user limits: roles, plans, organizations…); replaces `max_sessions` / `policy` / `max_trusted_devices`. |
| `sessions.password_change.revoke_sessions` | bool | `true` | When the password field of a profile user changes, sign out its other sessions (the session that changed it stays). |
| `sessions.password_change.field` | string | `password` | Mapped password field of the profile user classes. |
| `sessions.doctrine.table_prefix` | string `[A-Za-z0-9_]{0,32}` | `''` | Prefix of the bundle table **and** index names (REQ-ORM-002). |
| `sessions.doctrine.entity_manager` | string \| null | `null` | Entity manager that maps the registry (`null` = the default one). |
| `sessions.web_ui.enabled` | bool (literal) | `false` | Register the "My sessions" and administration pages (needs Twig, UiKit, FormKit and form CSRF; the container refuses to compile otherwise). |
| `sessions.web_ui.path_prefix` | path | `/account/sessions` | "My sessions" (`nowo_user_kit_sessions*` routes). |
| `sessions.web_ui.admin` | bool (literal) | `true` | Register the administration page of a user's sessions. |
| `sessions.web_ui.admin_path_prefix` | path | `/admin/user-sessions` | Administration routes (`nowo_user_kit_sessions_admin*`); must differ from `path_prefix`. |
| `sessions.web_ui.layout_template` | string \| null | `null` | Layout the pages extend (`null` = `nowo_ui_kit.layouts.admin`). |
| `sessions.web_ui.templates.index` / `.admin` | string \| null | `null` | Per-page template override (see [TEMPLATES](TEMPLATES.md)). |
| `sessions.security.access_roles` | list | `['ROLE_USER']` | Roles that open "My sessions" (any of them). Must not be empty (P1) unless `access_checker` is set or `allow_unauthenticated` is true. |
| `sessions.security.manage_roles` | list | `['ROLE_ADMIN']` | Roles that open the administration page for **any** user (any of them). Same non-empty rule. |
| `sessions.security.access_checker` | service id \| null | `null` | Service implementing `Sessions\Security\SessionsAccessCheckerInterface` (`canAccessOwn()`, `canManage(actor, subject)`) — e.g. "managers may not act on administrators". |
| `sessions.security.allow_unauthenticated` | bool (literal) | `false` | DEV / DEMO only: skip the role checks. Refused at compile time unless `kernel.debug` is true. Both pages still need a signed-in user. |

Invalid values fail the container compilation with the path and the allowed values (REQ-SF-006); environment
placeholders are refused for the literal switches.

### Example

```yaml
# config/packages/nowo_user_kit.yaml
nowo_user_kit:
    user_class: App\Entity\User
    sessions:
        enabled: true
        idle_lifetime: 86400          # >= framework.session.gc_maxlifetime
        limit:
            max_sessions: 5
            policy: evict_oldest      # or reject_new
        client:
            ip: truncate              # truncate | hash | none
        doctrine:
            table_prefix: 'app_'      # → app_user_kit_session
        web_ui:
            enabled: true
            path_prefix: /account/sessions
            admin_path_prefix: /admin/user-sessions
        security:
            access_roles: [ROLE_USER]
            manage_roles: [ROLE_ADMIN]
```

### Tables

One table, `user_kit_session` (prefixed: `<table_prefix>user_kit_session`, indexes and unique constraints prefixed
too): `id`, `public_id` (32 hex, used in URLs), `user_type` / `user_id` (owner: Doctrine root class + identifier),
`session_hash` (HMAC-SHA256 of the session id — the id itself is never stored), `browser`, `os`, `ip`, `device_id`,
`created_at`, `last_seen_at`, `revoked_at`, `revoked_reason`; one row per (session, owner), unique on
`session_hash` + `user_type` + `user_id`. Create it with Doctrine Migrations
(`doctrine:migrations:diff` after enabling the feature) or MigrationsKit; see [UPGRADING](UPGRADING.md#to-190).

With `remember_me_epoch.enabled` and no custom `store`, a second table `user_kit_session_epoch` (`id`, `user_type`,
`user_id`, `epoch_at`; unique on the owner) is mapped; see [UPGRADING](UPGRADING.md#to-1130).

### Routes and access control

The routes are **opt-in** (REQ-SF-004 rule 7): they are never loaded by the host's `routing.controllers` import, only by
the bundle route loader:

```yaml
# config/routes/nowo_user_kit_sessions.yaml
nowo_user_kit_sessions:
    resource: .
    type: nowo_user_kit_sessions
```

| Route | Method | Path |
| --- | --- | --- |
| `nowo_user_kit_sessions` | GET | `{path_prefix}` |
| `nowo_user_kit_sessions_revoke` | POST | `{path_prefix}/{id}/revoke` |
| `nowo_user_kit_sessions_revoke_others` | POST | `{path_prefix}/revoke-others` |
| `nowo_user_kit_sessions_admin` | GET | `{admin_path_prefix}/{profile}/{user}` |
| `nowo_user_kit_sessions_admin_revoke` | POST | `{admin_path_prefix}/{profile}/{user}/{id}/revoke` |
| `nowo_user_kit_sessions_admin_revoke_all` | POST | `{admin_path_prefix}/{profile}/{user}/revoke-all` |

`{profile}` is a UserKit profile name and `{user}` the Doctrine identifier of that profile's user class (use the Twig
helper `user_kit_sessions_admin_path(user)` to link to it). Protect both prefixes in the host firewall as well (layer 1
of REQ-UI-002):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/account/sessions, roles: ROLE_USER }
        - { path: ^/admin/user-sessions, roles: ROLE_ADMIN }
```

### FormKit profile

The page actions are FormKit CSRF-only forms themed through the FormKit profile `nowo_form_kit.profiles.user_kit`,
prepended by the bundle (translation domain `NowoUserKitBundle`). Define that profile in your `nowo_form_kit`
configuration to override it; the bundle then keeps yours.

## AuthKit integration

If `nowo_auth_kit.user_class` is set and the **default profile** omits `user_class`, UserKit inherits the AuthKit user class automatically. It is read from the AuthKit configuration (flat `user_class` or its default profile) at prepend time, so the order of the bundles in `config/bundles.php` does not matter.

Use the same entity for both bundles so the `UserChecker` applies to login flows provided by AuthKit.

## Translations

Domain: **`NowoUserKitBundle`**

Supported bundle locales: `de`, `en`, `es`, `fr`, `it`, `nl`, `pt`.

Override keys in the application under `translations/NowoUserKitBundle.<locale>.yaml`. See [USAGE.md](USAGE.md#translation-overrides-req-i18n-001).
