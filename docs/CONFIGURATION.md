# Configuration

All options live under the `nowo_user_kit` root key.

A commented default template ships at `src/Resources/config/packages/nowo_user_kit.yaml` in the bundle repository (and in the Symfony Flex recipe). Copy or adapt it as `config/packages/nowo_user_kit.yaml` in your application.

## Table of contents

- [Profiles](#profiles)
  - [Resolving profiles at runtime](#resolving-profiles-at-runtime)
- [Legacy flat configuration](#legacy-flat-configuration)
- [Doctrine mapping for the kit embeddables](#doctrine-mapping-for-the-kit-embeddables)
- [Personal data (GDPR)](#personal-data-gdpr)
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

## AuthKit integration

If `nowo_auth_kit.user_class` is set and the **default profile** omits `user_class`, UserKit inherits the AuthKit user class automatically. It is read from the AuthKit configuration (flat `user_class` or its default profile) at prepend time, so the order of the bundles in `config/bundles.php` does not matter.

Use the same entity for both bundles so the `UserChecker` applies to login flows provided by AuthKit.

## Translations

Domain: **`NowoUserKitBundle`**

Supported bundle locales: `de`, `en`, `es`, `fr`, `it`, `nl`, `pt`.

Override keys in the application under `translations/NowoUserKitBundle.<locale>.yaml`. See [USAGE.md](USAGE.md#translation-overrides-req-i18n-001).
