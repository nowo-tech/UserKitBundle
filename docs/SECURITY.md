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
  the `user_kit_session` table; the `nowo:user-kit:sessions:purge` command. The early check runs on `_stateless`
  routes of stateful firewalls too; only stateless firewalls are out of scope (no session).
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
| Revoked session keeps working | Registry listener | Checked on **every** signed-in request before the firewall, **`_stateless` routes of stateful firewalls included** (the firewall's ContextListener reads the session there too; the check parks the session usage index like ContextListener, so the route stays stateless): `database` store — the session's rows themselves (a missing row fails closed); `cache` store — a marker in a pool shared by all servers (`cache_pool` required, per-server pools refused at compile time), plus the row at every touch. A revoked, missing or idle (`idle_lifetime`) row ends the whole session: session invalidated, token dropped, the remember-me cookie of **every firewall** removed from the request (no auto-login) and cleared with its configured name, path, domain, secure and SameSite. Markers of the `cache` store are never deleted early, so session data written back under the old id is still refused. Works with any session handler |
| Remember-me cookie copied to another device (residual, Low) | Registry | Out of scope of session revocation: a copied remember-me cookie logs back in after "Sign out" / "Sign out everywhere", creating a **new**, visible session (subject to the limit). Hosts that need to void every remember-me cookie add a changing property (password, a "session epoch") to `remember_me.signature_properties`, or use a `token_provider` and delete the user's tokens on revoke-all; a password change does both. Advise users who suspect a compromise to change their password |
| IDOR on sign-out | Pages | Every revocation is scoped to the owner (`user_type` + `user_id`) in the `UPDATE`, so another user's public id never matches (403 without that row's own CSRF token, 404 otherwise); administration needs `canManage(actor, subject)` on the loaded user; `{user}` must equal the identifier exactly (`2abc` is not user 2); a user the actor may not manage is a 404 like an unknown one (no probing of other tenants' ids); anonymous requests are denied before any lookup |
| CSRF on sign-out | Pages | Every POST is a FormKit CSRF-only form; one token id per action **and row** (a token for one row does not sign another out); invalid / missing token → 403; the container refuses the pages without form CSRF (`SessionsPass`, P2 fail-closed) |
| Open access to the pages | Pages | `security.access_roles` / `manage_roles` must not be empty (P1, compile error) unless a custom checker is set; anonymous users denied (also with `allow_unauthenticated`, which only skips the role checks and is refused outside `kernel.debug`); host `access_control` on both prefixes recommended |
| Manager signs out a higher-privileged user (by design) | Pages | The default `RoleSessionsAccessChecker::canManage()` ignores the subject: any `manage_roles` holder may sign out **any** profile user, administrators included. Hosts with a role hierarchy plug an `access_checker` that denies when the subject holds a role the actor lacks |
| Routes exposed without the host's consent | Routing | Controller not autoconfigured: the host's `routing.controllers` import mounts nothing (REQ-SF-004 rule 7, kernel test); only the explicit loader import does |
| Personal data minimisation (GDPR art. 5(1)(c)) | Registry | Browser / OS **names** only (no raw user agent, no versions), IP truncated to /24 – /48 by default (or a keyed hash, or nothing), optional host device id; no geolocation |
| Storage limitation (GDPR art. 5(1)(e)) | Purge | `nowo:user-kit:sessions:purge` deletes rows idle for `idle_lifetime` + `touch_interval`; logout deletes the row; erasure deletes every row of the user |
| Limit bypass (residual, Low) | Sign-in | `reject_new` is checked after the credentials (no user enumeration / lock-out by unauthenticated requests: wrong password and unknown user give the same error); N simultaneous sign-ins may all pass, and `evict_oldest` may overshoot the same way (no lock) — a business limit, not a security boundary |
| `reject_new` lock-out by a credential holder (by design) | Sign-in | With a low limit, whoever knows the password can hold the slots and the real user is refused until the sessions expire (`idle_lifetime`). The way out is a password reset (it signs the other sessions out); hosts can also e-mail a sign-out link from `SessionLimitReachedEvent`. Prefer `evict_oldest` when lock-out matters more than the limit |
| Session fixation / owner switch | Registry | The row follows the session id when it migrates (re-keyed); one marker per firewall context and one row per (session, owner), so signing in on a second firewall in the same (stolen) session does not take over the first owner's row, and revoking that row ends the whole session; a context that changes owner without a login event gets a new row (only its own old row goes). Impersonation keeps the original user's row: revoking the impersonated user does not end an administrator's impersonation, revoking the administrator does. Logout deletes the row of its own context only; with several contexts in one session the others expire after `idle_lifetime` |
| Host header in redirects | Registry listener | The late sign-out redirect is the request path (`getRequestUri()`), never an absolute URL built from the `Host` header |
| Revocation delay of the `cache` store (residual, Low) | Registry | A lost marker (eviction, outage) is caught by the throttled touch: `touch_interval` (default 300 s) is the worst-case delay. Use the `database` store when revocation must be immediate whatever the cache does |
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
| **AI security audit (REQ-SEC-004)** | 1.9.0 (session registry and pages, new HTTP surface): independent audit 2026-11-01 (Appendix CC) **Pass (conditional) / Medium** on `3081d15`; findings M1, M2, L1–L5 and the info items fixed on `fix/sessions-sec-004-cc` with regression tests (`tests/Integration/Sessions/SessionSecurityRegressionTest.php`); re-audit expected **Pass (good) / Low** before the tag. Earlier: Pass (good) / Low (2026-09-28, before the pages). Recorded in the Nowo monorepo `BUNDLES_SECURITY_ANALYSIS.md`. |

Record confirmation in the release PR or tag notes.

