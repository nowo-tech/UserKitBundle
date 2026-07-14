# User Kit Bundle

Symfony bundle for **user account lifecycle and presence**: enable/disable accounts (`UserChecker`), throttled `lastActivityAt` updates, and configurable online detection.

Designed to complement [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) without a hard Composer dependency.

## Features

- **`enabled` / disabled accounts** — `AccountStatusUserChecker` blocks login for disabled users
- **`lastActivityAt`** — updated on authenticated HTTP requests (throttled)
- **`online_threshold`** — `UserPresenceResolver::isOnline()` and optional Twig `user_is_online()`
- **Session invalidation hook** — optional listener when an account is disabled
- **Optional traits** — `EnabledUserTrait`, `LastActivityTrait`

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

## Documentation

| Document | Purpose |
| -------- | ------- |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | Install and enable |
| [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) | Configuration reference |
| [`docs/USAGE.md`](docs/USAGE.md) | Traits, checker, presence |
| [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | Release history |
| [`docs/UPGRADING.md`](docs/UPGRADING.md) | Upgrade guide |
| [`docs/RELEASE.md`](docs/RELEASE.md) | Release process |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Security policy |
| [`specs/001-baseline/spec.md`](specs/001-baseline/spec.md) | Product spec |
| [`specs/001-baseline/code-inventory.md`](specs/001-baseline/code-inventory.md) | Source traceability |

## Development

```bash
make up
make test-coverage
make phpstan
```

## Tests and coverage

- **PHP:** 100% line coverage on `src/` (verified via `make test-coverage-100`)
- **Compatibility:** PHP 8.2+ · Symfony 7.4 / 8.x (CI matrix)

## Package

- **Composer:** `nowo-tech/user-kit-bundle`
- **Config root:** `nowo_user_kit`

## Found this useful?

If this bundle helps your project, consider starring the repository on GitHub.
