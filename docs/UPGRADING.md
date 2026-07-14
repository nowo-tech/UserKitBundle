# Upgrading

This document describes how to upgrade between versions of User Kit Bundle.

## 1.x

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
