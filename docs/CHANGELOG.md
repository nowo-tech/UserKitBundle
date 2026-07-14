# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.3] - 2026-07-14

### Changed

- Demos reduced to a single Symfony 8 application (`demo/symfony8` only). Removed `demo/symfony7` and `demo/symfony8-php85`.
- Demo `composer.json` and documentation aligned with bundle minimum **PHP 8.2+** (FrankenPHP image remains PHP 8.4 for Symfony 8).
- English comments added to bundle configuration YAML (`Resources/config/packages/nowo_user_kit.yaml`, `Resources/config/services.yaml`) and the Symfony Flex recipe.
- `docs/INSTALLATION.md`, `docs/DEMO-FRANKENPHP.md`, and `docs/CONTRIBUTING.md` updated for the single-demo layout.
- `phpstan.neon.dist` comment aligned with PHP 8.2+.

## [1.0.2] - 2026-07-14

### Added

- Translation catalogues **de**, **fr**, **it**, **nl**, and **pt** for domain `NowoUserKitBundle` (seven-locale minimum per Nowo bundle standards).
- Makefile target **`validate-translations`** (YAML lint + key parity across required locales).
- **`.scrutinizer.yml`** for Scrutinizer CI (PHP coverage via `composer test-coverage`).
- Demo applications **`demo/symfony7`** (Symfony 7.4, PHP 8.2) and **`demo/symfony8-php85`** (Symfony 8.1, PHP 8.5).
- Script **`.scripts/validate-translation-keys.php`** for translation key parity checks.

### Changed

- README: standard badges, canonical `## Documentation` section, FrankenPHP **worker mode** note, and three-demo quick start.
- `docs/USAGE.md` and `docs/CONFIGURATION.md`: translation overrides and supported locales.
- `docs/SPEC-DRIVEN-DEVELOPMENT.md` and `.specify/memory/constitution.md` aligned with UserKitBundle (implemented baseline).
- `specs/001-baseline/code-inventory.md`: seven translation files mapped.
- `demo/README.md` and `demo/Makefile`: aggregator for symfony7, symfony8, and symfony8-php85.
- `make release-check` now runs **`test-coverage-100`** and **`validate-translations`**.

### Fixed

- **`phpunit.xml.dist`**: removed non-existent `tests/Integration` suite (fixes `composer test` / `make test-coverage-100`).
- **`demo/symfony7`** and **`demo/symfony8-php85`**: replaced stale WalletQrBundle scaffolding with User Kit demos.

## [1.0.1] - 2026-07-14

### Changed

- README: links to CHANGELOG, UPGRADING, RELEASE, and SECURITY; `## Tests and coverage` with explicit PHP coverage percentage.
- GitHub metadata corrected from stale WalletQrBundle references (SECURITY policy, issue template, CODEOWNERS, FUNDING).
- Root Makefile header comment aligned with User Kit Bundle.

### Fixed

- CHANGELOG 1.0.0 demo note: primary demo is `demo/symfony8` (FrankenPHP + SQLite).

## [1.0.0] - 2026-07-14

### Added

- **`AccountStatusUserChecker`** — blocks authentication for disabled accounts via Symfony `UserChecker` (`DisabledException`).
- **`LastActivitySubscriber`** — throttled `lastActivityAt` updates on authenticated HTTP requests.
- **`UserPresenceResolver`** — `isOnline()` based on configurable `online_threshold`.
- **`user_is_online` Twig helper** — optional when `symfony/twig-bundle` is installed.
- **`AccountDisabledListener`** — optional session invalidation when an account is disabled.
- **`SessionInvalidatorInterface`** and **`DefaultSessionInvalidator`** extension point.
- Entity contracts: `AccountStatusInterface`, `LastActivityInterface`.
- Optional Doctrine traits: `EnabledUserTrait`, `LastActivityTrait`.
- Configuration tree `nowo_user_kit` (`user_class`, `account_status`, `last_activity`, `twig`).
- AuthKit coexistence: inherits `user_class` from `nowo_auth_kit.user_class` when present.
- Translation catalogues **en** and **es** for domain `NowoUserKitBundle`.
- GitHub Spec Kit baseline (`specs/001-baseline/`), operator manual ([`SPEC-KIT.md`](SPEC-KIT.md)), and Cursor Agent skills.
- Demo application **`demo/symfony8`** (FrankenPHP + SQLite, account status and presence smoke tests).
- Symfony Flex recipe `.symfony/recipe/nowo-tech/user-kit-bundle/1.0/`.
- CI matrix: PHP 8.2–8.5, Symfony 7.0 / 7.4 / 8.0 / 8.1 with **100%** PHPUnit line coverage on `src/`.

[1.0.3]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.3
[1.0.2]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.2
[1.0.1]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.0
