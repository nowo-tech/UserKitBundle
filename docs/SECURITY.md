# Security Policy

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Logging](#logging)
- [Dependencies](#dependencies)
- [Supported Versions](#supported-versions)
- [Reporting a Vulnerability](#reporting-a-vulnerability)
  - [How to Report](#how-to-report)
  - [Response Timeline](#response-timeline)
  - [Disclosure Policy](#disclosure-policy)
- [Preferred Languages](#preferred-languages)
- [Contact](#contact)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Scope

UserKit adds account-level building blocks to a host user entity: an account-status user checker, last-activity
tracking and online presence, UI preferences rendered as `<html>` data attributes, product-tour progress, session
invalidation on disable and a GDPR export / erasure SPI. It has **no HTTP routes, controllers or forms of its own**;
authentication, firewalls and the admin UI stay in the host (or AuthKit).

## Attack surface

- `AccountStatusUserChecker` on the firewall (login and every authenticated request).
- `LastActivitySubscriber` on `kernel.request` (writes one timestamp column of the current user).
- `AccountDisabledListener` (Doctrine `postUpdate`) calling `SessionInvalidatorInterface`.
- Twig helpers `user_is_online()` and `user_ui_html_attributes()` (attribute values come from stored user preferences).
- `PersonalDataExporter` / `PersonalDataEraser` services and their tagged providers / erasers / guards.
- Bundle configuration (`nowo_user_kit.profiles.*`).

## Threat model

| Threat | Where | Mitigation |
| --- | --- | --- |
| Disabled account keeps access | User checker, sessions | `AccountStatusUserChecker` refuses disabled accounts when they authenticate; `invalidate_sessions_on_disable` calls the host `SessionInvalidatorInterface` (default no-op: hosts with server-side sessions must implement it) |
| Attribute / HTML injection through stored preferences | `user_ui_html_attributes()` | Values normalised against closed enums (`ContentWidth`, theme, density…; unknown values dropped) and escaped with `htmlspecialchars()` |
| Cross-request state in workers | Last activity throttle, presence | Throttle map bounded (`MAX_THROTTLE_ENTRIES`) and keyed by profile + identifier; only behind a security-enabled firewall; closed entity managers reset |
| Unintended writes | Last activity | Since 1.6.0 a targeted DQL `UPDATE` of the timestamp column only (no flush of other pending changes) |
| Over-disclosure in GDPR export | Export providers | Fixed sections; providers tagged by the host; documents contain only the listed fields |
| Partial / vetoed erasure | Eraser | Guards run before any change; erasers run in one transaction, rolled back on failure |
| Presence as personal data | Online status | Derived from last activity; hosts decide who may see it |

## Logging

`LastActivitySubscriber` logs a `warning` with the exception class and message when the timestamp cannot be stored; no
user identifiers, tokens or session ids are logged. Nothing else in the bundle logs.

## Dependencies

Runtime dependencies are Symfony components (security, http-kernel, property-access, clock, …) and Doctrine ORM.
`composer audit` runs on the bundle and the demo before every release; Dependabot alerts are triaged and security fixes
ship as patch releases listed under *Security* in the [CHANGELOG](CHANGELOG.md).

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

We take the security of User Kit Bundle seriously. If you believe you have found a security vulnerability, please report it to us as described below.

### How to Report

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please send an email to: **hectorfranco@nowo.tech**

Include the following information in your report:

- Type of issue (e.g., buffer overflow, SQL injection, cross-site scripting, etc.)
- Full paths of source file(s) related to the issue
- The location of the affected source code (tag/branch/commit or direct URL)
- Any special configuration required to reproduce the issue
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact of the issue, including how an attacker might exploit it

### Response Timeline

- **Initial Response**: Within 48 hours
- **Status Update**: Within 7 days
- **Resolution**: Varies depending on complexity

### Disclosure Policy

- We will confirm receipt of your vulnerability report
- We will work with you to understand and validate the issue
- We will develop and release a fix as quickly as possible
- We will publicly acknowledge your responsible disclosure (if desired)

## Preferred Languages

We prefer all communications to be in English or Spanish.

## Contact

- **Maintainer**: [Héctor Franco Aceituno](https://github.com/HecFranco)
- **Organization**: [nowo-tech](https://github.com/nowo-tech)

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current and linked from the README where applicable. |
| **`.gitignore` and `.env`** | `.env` and local env files are ignored; no committed secrets. |
| **No secrets in repo** | No API keys, passwords, or tokens in tracked files. |
| **Recipe / Flex** | Default recipe or installer templates do not ship production secrets. |
| **Input / output** | Inputs validated; outputs escaped in Twig/templates where user-controlled. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Logging** | Logs do not print secrets, tokens, or session identifiers unnecessarily. |
| **Cryptography** | If used: keys from secure config; never hardcoded. |
| **Permissions / exposure** | Routes and admin features documented; roles configured for production. |
| **Limits / DoS** | Timeouts, size limits, rate limits where applicable. |
| **AI security audit (REQ-SEC-004)** | **Pass (good)** / **Low** (re-audit 2026-09-28; no admin HTTP surface). Recorded in the Nowo monorepo `BUNDLES_SECURITY_ANALYSIS.md`. |

Record confirmation in the release PR or tag notes.

