# Configuration

All options live under the `nowo_user_kit` root key.

A commented default template ships at `src/Resources/config/packages/nowo_user_kit.yaml` in the bundle repository (and in the Symfony Flex recipe). Copy or adapt it as `config/packages/nowo_user_kit.yaml` in your application.

## Profiles

Each **profile** maps a `user_class` to its own account-status and last-activity settings. Use multiple profiles when the application has more than one authenticated user entity (for example `App\Entity\User` and `App\Entity\Admin`).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `default_profile` | string | first profile key | Profile used when no name is passed to `UserPresenceResolver::isOnline()` or Twig `user_is_online()`. |
| `profiles` | map | `default` | Named profile definitions (at least one required). |
| `twig` | bool | `true` | Register `user_is_online` when Twig is installed. |

Each profile supports:

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `user_class` | string | — | FQCN of the user entity for this profile (required; must be unique across profiles). |
| `account_status.enabled` | bool | `true` | Apply `AccountStatusUserChecker` to this profile. |
| `account_status.field` | string | `enabled` | Enabled flag property or accessor. |
| `account_status.invalidate_sessions_on_disable` | bool | `false` | Invalidate sessions when the account is disabled. |
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

## AuthKit integration

If `nowo_auth_kit.user_class` is set and the **default profile** omits `user_class`, UserKit inherits the AuthKit user class automatically.

Use the same entity for both bundles so the `UserChecker` applies to login flows provided by AuthKit.

## Translations

Domain: **`NowoUserKitBundle`**

Supported bundle locales: `de`, `en`, `es`, `fr`, `it`, `nl`, `pt`.

Override keys in the application under `translations/NowoUserKitBundle.<locale>.yaml`. See [USAGE.md](USAGE.md#translation-overrides-req-i18n-001).
