# Security Policy

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Logging](#logging)
- [CSRF (REQ-SEC-005)](#csrf-req-sec-005)
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
invalidation on disable, a GDPR export / erasure SPI and, since 1.9.0, an **opt-in session registry** (`sessions.*`,
off by default) with two optional pages ("My sessions" and an administration page) and CSRF-protected sign-out
actions. Without `sessions.web_ui.enabled` the bundle has no HTTP routes or controllers; authentication and firewalls
stay in the host (or AuthKit).

## Attack surface

- `AccountStatusUserChecker` on the firewall (login and every authenticated request).
- `LastActivitySubscriber` on `kernel.request` (writes one timestamp column of the current user).
- `AccountDisabledListener` (Doctrine `postUpdate`) calling `SessionInvalidatorInterface`.
- Twig helpers `user_is_online()` and `user_ui_html_attributes()` (attribute values come from stored user preferences).
- `PersonalDataExporter` / `PersonalDataEraser` services and their tagged providers / erasers / guards.
- Bundle configuration (`nowo_user_kit.profiles.*`, `nowo_user_kit.sessions.*`).
- Session registry (`sessions.enabled`): `SessionRegistryListener` on `kernel.request` (before and after the firewall),
  `kernel.response`, `CheckPassportEvent`, `LoginSuccessEvent`, `LogoutEvent`; `PasswordChangeListener` (Doctrine);
  the `user_kit_session` table; the `nowo:user-kit:sessions:purge` command.
- Session pages (`sessions.web_ui.enabled`): `GET` / `POST` routes under `web_ui.path_prefix` and
  `web_ui.admin_path_prefix`, mounted only by the `nowo_user_kit_sessions` route loader.

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
| Session id disclosure | Registry | Only `HMAC-SHA256(hash_secret, session id)` is stored; the hash is never printed, exported or put in an event; URLs use a random 128-bit public id |
| Revoked session keeps working | Registry listener | Checked on **every** signed-in request before the firewall (`database` store: the row itself; `cache` store: a marker in a pool that must be shared by all servers, plus the row at every touch); a revoked, missing or idle (`idle_lifetime`) row ends the session: session invalidated, token dropped, remember-me cookies removed from the request (no auto-login) and cleared. Works with any session handler |
| Remember-me cookie copied to another device | Registry | Out of scope of session revocation: the copied cookie creates a **new** session (subject to the limit and listed on the page). Hosts that need to void every remember-me cookie add a changing property (password, a "session epoch") to `remember_me.signature_properties`; a password change does both |
| IDOR on sign-out | Pages | Every revocation is scoped to the owner (`user_type` + `user_id`) in the `UPDATE`, so another user's public id never matches (403 without that row's own CSRF token, 404 otherwise); administration needs `canManage(actor, subject)` on the loaded user |
| CSRF on sign-out | Pages | Every POST is a FormKit CSRF-only form; one token id per action **and row** (a token for one row does not sign another out); invalid / missing token → 403; the container refuses the pages without form CSRF (`SessionsPass`, P2 fail-closed) |
| Open access to the pages | Pages | `security.access_roles` / `manage_roles` must not be empty (P1, compile error) unless a custom checker is set; anonymous users denied; `allow_unauthenticated` refused outside `kernel.debug`; host `access_control` on both prefixes recommended |
| Routes exposed without the host's consent | Routing | Controller not autoconfigured: the host's `routing.controllers` import mounts nothing (REQ-SF-004 rule 7, kernel test); only the explicit loader import does |
| Personal data minimisation (GDPR art. 5(1)(c)) | Registry | Browser / OS **names** only (no raw user agent, no versions), IP truncated to /24 – /48 by default (or a keyed hash, or nothing), optional host device id; no geolocation |
| Storage limitation (GDPR art. 5(1)(e)) | Purge | `nowo:user-kit:sessions:purge` deletes rows idle for `idle_lifetime` + `touch_interval`; logout deletes the row; erasure deletes every row of the user |
| Limit bypass | Sign-in | `reject_new` is checked after the credentials (no user enumeration / lock-out by unauthenticated requests); two simultaneous sign-ins may both pass (no lock) — documented residual risk |
| Session fixation / owner switch | Registry | The row follows the session id when it migrates (re-keyed); a session that changes owner without a login event gets a new row (the old one goes); impersonation keeps the original user's row |
| Cross-request state in workers | Listeners | Services hold no request state; `PasswordChangeListener` pending changes are cleared on `kernel.reset` |

## Logging

`LastActivitySubscriber` logs a `warning` with the exception class and message when the timestamp cannot be stored; no
user identifiers, tokens or session ids are logged. Nothing else in the bundle logs: the session registry reports
through events (`UserSessionStartedEvent`, `UserSessionsRevokedEvent`, `SessionLimitReachedEvent`,
`UserSessionsPurgedEvent`) that carry the owner reference, actor, reason and public ids — never a session id or hash.

## CSRF (REQ-SEC-005)

The only session mutations are the session pages' sign-out actions: FormKit CSRF-only forms validated server-side
(fail closed). Sign-out is never a GET.

## Dependencies

Runtime dependencies are Symfony components (security, http-kernel, property-access, clock, form, security-csrf,
routing, twig-bundle, …), Doctrine ORM / DBAL, and since 1.9.0 `nowo-tech/ui-kit-bundle` and `nowo-tech/form-kit-bundle`
(session pages).
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

