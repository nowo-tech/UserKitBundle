# User Kit Bundle

[![CI](https://github.com/nowo-tech/UserKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/UserKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/user-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/user-kit-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/user-kit-bundle.svg)](https://packagist.org/packages/nowo-tech/user-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/user-kit-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/UserKitBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install from [Packagist](https://packagist.org/packages/nowo-tech/user-kit-bundle) and give the repo a star on GitHub.

Symfony bundle for **user account lifecycle and presence**: enable/disable accounts (`UserChecker`), throttled `lastActivityAt` updates, and configurable online detection.

Designed to complement [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) without a hard Composer dependency.

## Features

- **`enabled` / disabled accounts** — `AccountStatusUserChecker` blocks login for disabled users
- **`lastActivityAt`** — updated on authenticated HTTP requests (throttled)
- **`online_threshold`** — `UserPresenceResolver::isOnline()` and optional Twig `user_is_online()`
- **Session invalidation hook** — optional listener when an account is disabled
- **Optional traits** — `EnabledUserTrait`, `LastActivityTrait`
- **Translations** — domain `NowoUserKitBundle` (`de`, `en`, `es`, `fr`, `it`, `nl`, `pt`)

## Requirements

- PHP 8.2+
- Symfony 7.4 | 8.x

## Quick start

```bash
composer require nowo-tech/user-kit-bundle
```

```yaml
# config/packages/nowo_user_kit.yaml
nowo_user_kit:
    user_class: App\Entity\User
    account_status:
        enabled: true
    last_activity:
        enabled: true
        online_threshold: 300
```

## AuthKit coexistence

```
Login (AuthKit) → form_login → UserProvider → UserChecker (UserKit) → OK / blocked
```

Use the same `user_class` in both bundles. UserKit may inherit `user_class` from `nowo_auth_kit.user_class` when that parameter exists.

## Development

```bash
make up
make test-coverage-100
make phpstan
```

## Demo

```bash
make -C demo up-symfony7        # Symfony 7.4 — http://localhost:8022
make -C demo up-symfony8        # Symfony 8.1 — http://localhost:8023
make -C demo up-symfony8-php85  # Symfony 8.1 + PHP 8.5 — http://localhost:8024
```

Login with `demo@user-kit.test` / `demo`. Demos run under **FrankenPHP** in Docker. See [demo/README.md](demo/README.md) and [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md) for development vs production setup, including **FrankenPHP worker mode** for production.

## Tests and coverage

- **PHP:** 100% line coverage on `src/` (verified via `make test-coverage-100`)
- **Compatibility:** PHP 8.2+ · Symfony 7.4 / 8.x (CI matrix)

## Package

- **Composer:** `nowo-tech/user-kit-bundle`
- **Config root:** `nowo_user_kit`

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [Baseline product spec](specs/001-baseline/spec.md)
- [Code inventory](specs/001-baseline/code-inventory.md)
