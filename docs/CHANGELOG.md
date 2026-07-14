# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Demo applications: `demo/symfony7`, `demo/symfony8`, `demo/symfony8-php85` (FrankenPHP + SQLite).
- CI matrix: PHP 8.2–8.5, Symfony 7.0 / 7.4 / 8.0 / 8.1 with **100%** PHPUnit line coverage on `src/`.

[1.0.0]: https://github.com/nowo-tech/UserKitBundle/releases/tag/v1.0.0
