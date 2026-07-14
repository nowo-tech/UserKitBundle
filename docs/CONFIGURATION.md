# Configuration

All options live under the `nowo_user_kit` root key.

A commented default template ships at `src/Resources/config/packages/nowo_user_kit.yaml` in the bundle repository (and in the Symfony Flex recipe). Copy or adapt it as `config/packages/nowo_user_kit.yaml` in your application.

## Top level

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `user_class` | string\|null | `null` | FQCN of the application user entity. Required unless `nowo_auth_kit.user_class` is defined. |
| `twig` | bool | `true` | Register `user_is_online` when Twig is installed. |

Example:

```yaml
nowo_user_kit:
    user_class: App\Entity\User
    twig: true
```

## Account status

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `account_status.enabled` | bool | `true` | Register `AccountStatusUserChecker`. |
| `account_status.field` | string | `enabled` | Entity property or accessor for the enabled flag. |
| `account_status.invalidate_sessions_on_disable` | bool | `false` | Invalidate active sessions when `enabled` changes from `true` to `false`. |

Example:

```yaml
nowo_user_kit:
    account_status:
        enabled: true
        field: enabled
        invalidate_sessions_on_disable: false
```

When `enabled: false` on the user entity, login fails with Symfony `DisabledException` after credential validation.

## Last activity & presence

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `last_activity.enabled` | bool | `false` | Persist `lastActivityAt` on authenticated requests. |
| `last_activity.field` | string | `lastActivityAt` | Entity property for the last activity timestamp. |
| `last_activity.online_threshold` | int | `300` | Seconds within which a user is considered online (min `1`). |
| `last_activity.update_throttle` | int | `30` | Minimum seconds between DB writes (min `0`). |

Example:

```yaml
nowo_user_kit:
    last_activity:
        enabled: true
        field: lastActivityAt
        online_threshold: 300
        update_throttle: 30
```

## Full example

```yaml
nowo_user_kit:
    user_class: App\Entity\User
    account_status:
        enabled: true
        field: enabled
        invalidate_sessions_on_disable: false
    last_activity:
        enabled: true
        field: lastActivityAt
        online_threshold: 300
        update_throttle: 30
    twig: true
```

## AuthKit integration

If `nowo_auth_kit.user_class` is set and `nowo_user_kit.user_class` is omitted, UserKit inherits the AuthKit user class automatically.

Use the same entity for both bundles so the `UserChecker` applies to login flows provided by AuthKit.

## Translations

Domain: **`NowoUserKitBundle`**

Supported bundle locales: `de`, `en`, `es`, `fr`, `it`, `nl`, `pt`.

Override keys in the application under `translations/NowoUserKitBundle.<locale>.yaml`. See [USAGE.md](USAGE.md#translation-overrides-req-i18n-001).
