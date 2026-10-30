# Upgrading

## Table of contents

- [Unreleased](#unreleased)
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
- [To 1.1.11](#to-1111)
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
