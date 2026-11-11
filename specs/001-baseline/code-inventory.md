# Code inventory — traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/user-kit-bundle`  
**Last audited**: 2026-11-02  
**Status**: Implemented (FrankenPHP worker / no-kernel-reset audit ✅)

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/services.yaml` | DI wiring | FR-SEC-002, FR-PRES-001 | Mapped |
| `Resources/config/packages/nowo_user_kit.yaml` | Default config template (profiles) | FR-CFG-002 | Mapped |
| `Profile/ProfileRegistry.php` | Named profile resolution | FR-CFG-001 | Mapped |
| `Profile/ProfileSettings.php` | Profile value object | FR-CFG-001 | Mapped |
| `Profile/UnknownProfileException.php` | Unknown profile name (default or runtime) | FR-CFG-001 | Mapped |
| `Resources/config/personal_data.yaml` | GDPR SPI services, loaded when `personal_data.enabled` is true | 003 FR-001, 003 FR-002 | Mapped |

## PHP — bundle core

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `NowoUserKitBundle.php` | Bundle entry | FR-BUNDLE-001 | Mapped |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 | Mapped |
| `DependencyInjection/NowoUserKitExtension.php` | DI extension | FR-CFG-002 | Mapped |
| `DependencyInjection/Compiler/AccountStatusUserCheckerPass.php` | Wires `AccountStatusUserChecker` into every security-enabled firewall | FR-SEC-001 | Mapped |

## PHP — contracts & traits

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Model/AccountStatusInterface.php` | Entity contract | FR-CONTRACT-001 | Mapped |
| `Model/LastActivityInterface.php` | Entity contract | FR-CONTRACT-002 | Mapped |
| `Model/EnabledUserTrait.php` | Doctrine trait | FR-TRAIT-001 | Mapped |
| `Model/LastActivityTrait.php` | Doctrine trait | FR-TRAIT-002 | Mapped |

## PHP — security & presence

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Security/AccountStatusUserChecker.php` | UserChecker | FR-SEC-001 | Mapped |
| `EventSubscriber/LastActivitySubscriber.php` | Request subscriber | FR-PRES-001, FR-RUNTIME-001 | Mapped |
| `Presence/UserPresenceResolver.php` | Online detection | FR-PRES-002 | Mapped |
| `EventListener/AccountDisabledListener.php` | Session invalidation | FR-SES-001 | Mapped |
| `Session/SessionInvalidatorInterface.php` | Extension point | FR-SES-002 | Mapped |
| `Session/DefaultSessionInvalidator.php` | Default strategy | FR-SES-002 | Mapped |

## PHP — Twig

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Twig/UserPresenceExtension.php` | Twig helper | FR-PRES-002, US-09 | Mapped |

## PHP — UI preferences & product tour (spec [`002-ui-preferences`](../002-ui-preferences/spec.md))

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Model/UiPreferencesInterface.php` | Generic UI / display preferences contract | 002 FR-001, 002 FR-003 | Mapped |
| `Model/UiPreferencesTrait.php` | `preferred_*` columns (embeddable or entity), defaults, normalising setters | 002 FR-001 … 002 FR-004 | Mapped |
| `Model/UiPreferences.php` | Ready-made embeddable for UI preferences | 002 FR-001, 002 FR-007 | Mapped |
| `Model/ProductTourProgressInterface.php` | Product-tour seen tracking contract | 002 FR-005 | Mapped |
| `Model/ProductTourProgressTrait.php` | `product_tour_seen_at` / `product_tour_seen_pages` columns and catalog helpers | 002 FR-005 | Mapped |
| `Model/ProductTourProgress.php` | Ready-made embeddable for product-tour progress | 002 FR-005, 002 FR-007 | Mapped |
| `Model/ModelResolver.php` | Finds a kit model on the user itself or on an embedded property | 002 FR-006, 003 FR-005 | Mapped |
| `Model/Preference/PreferenceChoiceTrait.php` | Shared helpers of the string-backed preference enums | 002 FR-003 | Mapped |
| `Model/Preference/Theme.php` | Colour theme (`data-theme`) | 002 FR-002, 002 FR-003 | Mapped |
| `Model/Preference/ContentWidth.php` | Content width (`content` / `full`) | 002 FR-002, 002 FR-003 | Mapped |
| `Model/Preference/UiDensity.php` | UI density (`data-ui-density`) | 002 FR-002, 002 FR-003 | Mapped |
| `Model/Preference/Motion.php` | Motion preference (`data-motion`) | 002 FR-002, 002 FR-003 | Mapped |
| `Model/Preference/FontScale.php` | Root font scale (`data-font-scale`) | 002 FR-002, 002 FR-003 | Mapped |
| `Model/Preference/Contrast.php` | Contrast (`data-contrast`) | 002 FR-002, 002 FR-003 | Mapped |
| `Model/Preference/SidebarMode.php` | Desktop sidebar default (expanded / rail / collapsed) | 002 FR-002, 002 FR-003 | Mapped |
| `Model/Preference/FooterMode.php` | Footer scroll / fixed | 002 FR-002, 002 FR-003 | Mapped |
| `Preference/UiPreferencesHtmlAttributes.php` | Builds the `<html>` `data-*` attributes for the pre-paint theme script | 002 FR-006 | Mapped |
| `Twig/UiPreferencesExtension.php` | `user_ui_html_attributes(user, {resolved})` Twig function | 002 FR-006 | Mapped |

## PHP — personal data / GDPR (spec [`003-personal-data`](../003-personal-data/spec.md))

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `PersonalData/PersonalDataExportProviderInterface.php` | Export section provider contract (art. 15 / 20) | 003 FR-001 | Mapped |
| `PersonalData/PersonalDataExporter.php` | Versioned export document, value normalisation, duplicate-key guard | 003 FR-002, 003 FR-003 | Mapped |
| `PersonalData/PersonalDataEraserInterface.php` | Eraser contract (art. 17) | 003 FR-001 | Mapped |
| `PersonalData/PersonalDataEraser.php` | Runs guards, then all supporting erasers in one transaction | 003 FR-004 | Mapped |
| `PersonalData/ErasureGuardInterface.php` | Erasure veto contract (host domain rules) | 003 FR-001 | Mapped |
| `PersonalData/ErasureRequest.php` | Erased user, actor and host context | 003 FR-004 | Mapped |
| `PersonalData/ErasureVeto.php` | Veto code + details | 003 FR-004 | Mapped |
| `PersonalData/ErasureVetoedException.php` | Thrown when a guard vetoes (nothing erased) | 003 FR-004 | Mapped |
| `PersonalData/Provider/AccountStatusExportProvider.php` | Built-in `account_status` section | 003 FR-005 | Mapped |
| `PersonalData/Provider/LastActivityExportProvider.php` | Built-in `last_activity` section | 003 FR-005 | Mapped |
| `PersonalData/Provider/UiPreferencesExportProvider.php` | Built-in `ui_preferences` section | 003 FR-005 | Mapped |
| `PersonalData/Provider/ProductTourExportProvider.php` | Built-in `product_tour` section | 003 FR-005 | Mapped |
| `PersonalData/Eraser/LastActivityEraser.php` | Clears `lastActivityAt` | 003 FR-006 | Mapped |
| `PersonalData/Eraser/UiPreferencesEraser.php` | Resets the UI preference columns | 003 FR-006 | Mapped |
| `PersonalData/Eraser/ProductTourEraser.php` | Clears product-tour progress | 003 FR-006 | Mapped |

## Sessions — session registry and limits (spec [`004-sessions`](../004-sessions/spec.md), since 1.9.0)

FR-SES-003 … FR-SES-010 are defined in [`spec.md`](spec.md#session-registry-spec-004) and map to 004 FR-001 … FR-008.

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `DependencyInjection/SessionsConfiguration.php` | `nowo_user_kit.sessions` config tree (safe defaults: off, IP truncated, pages off, roles never empty) | FR-SES-003, FR-SES-009 | Mapped |
| `DependencyInjection/SessionsLoader.php` | Registers the registry services when `sessions.enabled`; prepends Doctrine mapping and the FormKit profile | FR-SES-009 | Mapped |
| `DependencyInjection/Compiler/SessionsPass.php` | Compile-time guards (SecurityBundle + Doctrine, shared cache pool) and remember-me cookie wiring | FR-SES-004, FR-SES-009 | Mapped |
| `DependencyInjection/Compiler/TwigPathsPass.php` | `@NowoUserKitBundle` namespace with the `templates/bundles/NowoUserKitBundle` override first | FR-SES-005 | Mapped |
| `Sessions/UserSessionManager.php` | Public API: list, sign out one / others / everywhere / a device | FR-SES-003, FR-SES-005, FR-SES-007 | Mapped |
| `Sessions/SessionUserResolver.php` | Owner reference (root entity class + identifier) | FR-SES-003 | Mapped |
| `Sessions/RegistrySessionInvalidator.php` | `SessionInvalidatorInterface` backed by the registry (account disabled) | FR-SES-007 | Mapped |
| `Sessions/Entity/UserSession.php` | Registry row: HMAC of the session id, owner, client, timestamps, revocation | FR-SES-003 | Mapped |
| `Sessions/Doctrine/UserSessionStore.php` | Registry table: ORM reads, one parameterised DBAL statement per write, capped lists | FR-SES-003, FR-SES-004 | Mapped |
| `Sessions/Doctrine/TablePrefixListener.php` | `sessions.doctrine.table_prefix` on bundle-owned tables / indexes only (REQ-ORM-002) | FR-SES-009 | Mapped |
| `Sessions/Client/SessionClientFactory.php` | Client of a request with data minimisation (browser / OS names, truncated or hashed IP) | FR-SES-003 | Mapped |
| `Sessions/Client/DeviceIdResolverInterface.php` | Optional host hook: device id stored with the session (`revokeDevice()`); deprecated since 1.15 | FR-SES-003, FR-SES-007 | Mapped |
| `Sessions/Client/DeviceIdProviderInterface.php` | Framework-free device id hook (`DeviceContext`), 1.15 | FR-SES-003, FR-SES-007 | Mapped |
| `Sessions/SessionHashesInterface.php`, `Sessions/Model/SessionRef.php`, `Sessions/Model/DeviceContext.php` | Framework-free session ports and VOs (REQ-ARCH-001 rule 3), 1.15 | FR-SES-003, FR-SES-007 | Mapped |
| `Sessions/Model/SessionClient.php` | Value object: browser, OS, device id, stored IP form | FR-SES-003 | Mapped |
| `Sessions/Model/SessionUser.php` | Value object: owner class + id | FR-SES-003 | Mapped |
| `Sessions/Model/SessionLimit.php` | Value object: limit of one user (null = unlimited) | FR-SES-006 | Mapped |
| `Sessions/Model/SessionLimitPolicy.php` | Enum `evict_oldest` / `reject_new` | FR-SES-006 | Mapped |
| `Sessions/Model/RevocationReason.php` | Enum of revocation reasons (stored, event, flash key) | FR-SES-004, FR-SES-008 | Mapped |
| `Sessions/EventListener/SessionRegistryListener.php` | Registers sessions at sign-in, touches them, signs revoked / missing / idle sessions out before the firewall, applies the limit, deletes the row at logout | FR-SES-003, FR-SES-004, FR-SES-006 | Mapped |
| `Sessions/EventListener/PasswordChangeListener.php` | Password field change of a profile user signs the other sessions out (`preUpdate` → `postFlush`) | FR-SES-007 | Mapped |
| `Sessions/Revocation/RevocationStoreInterface.php` | Revocation lookup contract (`sessions.revocation.store`) | FR-SES-004 | Mapped |
| `Sessions/Revocation/DatabaseRevocationStore.php` | Default store: registry row lookup, missing row fails closed | FR-SES-004 | Mapped |
| `Sessions/Revocation/CacheRevocationStore.php` | Shared PSR-6 marker per revoked session hash | FR-SES-004 | Mapped |
| `Sessions/Limit/SessionLimitResolverInterface.php` | Per-user limit hook (called at sign-in only) | FR-SES-006 | Mapped |
| `Sessions/Limit/ConfiguredSessionLimitResolver.php` | Default resolver: `limit.max_sessions` / `limit.policy` for everyone | FR-SES-006 | Mapped |
| `Sessions/Security/SessionLimitReachedException.php` | Authentication failure of the `reject_new` policy (translated message) | FR-SES-006 | Mapped |
| `Sessions/Security/SessionsAccessCheckerInterface.php` | Page access contract (REQ-UI-002) | FR-SES-009 | Mapped |
| `Sessions/Security/RoleSessionsAccessChecker.php` | Default checker: `access_roles` / `manage_roles`, anonymous denied | FR-SES-009 | Mapped |
| `Sessions/Security/AllowAllSessionsAccessChecker.php` | Debug-only checker (`allow_unauthenticated`, refused without kernel.debug) | FR-SES-009 | Mapped |
| `Sessions/Security/AccessGuard.php` | Applies the checker; trusts the token only when a firewall matched | FR-SES-009 | Mapped |
| `Sessions/Controller/SessionsController.php` | "My sessions" and administration pages, CSRF-only POST actions, `no-store` | FR-SES-005 | Mapped |
| `Sessions/Routing/SessionsRouteLoader.php` | Opt-in route loader `nowo_user_kit_sessions` (REQ-SF-004 rule 7) | FR-SES-009 | Mapped |
| `Sessions/Ui/SessionsPages.php` | UiKit page contract: templates, layout, `ListPage` view models, confirm dialogs | FR-SES-005 | Mapped |
| `Sessions/Ui/SessionRow.php` | Row view model (no session hash, displayable network only) | FR-SES-005 | Mapped |
| `Sessions/Twig/SessionsTwigExtension.php` | `user_kit_sessions_admin_path(user)` Twig function | FR-SES-005 | Mapped |
| `Sessions/Event/UserSessionStartedEvent.php` | Audit: session registered | FR-SES-008 | Mapped |
| `Sessions/Event/UserSessionsRevokedEvent.php` | Audit: sessions revoked (actor, reason, count) | FR-SES-008 | Mapped |
| `Sessions/Event/SessionLimitReachedEvent.php` | Audit / hook: sign-in hit the limit | FR-SES-006, FR-SES-008 | Mapped |
| `Sessions/Event/UserSessionsPurgedEvent.php` | Audit: retention purge | FR-SES-008 | Mapped |
| `Sessions/Command/PurgeSessionsCommand.php` | Retention purge of rows not seen for `idle_lifetime` + `touch_interval` | FR-SES-003 | Mapped |
| `Sessions/PersonalData/SessionsExportProvider.php` | GDPR export section `sessions` (hash never exported) | FR-SES-010 | Mapped |
| `Sessions/PersonalData/SessionsEraser.php` | GDPR eraser: signs every session out and deletes the rows | FR-SES-010 | Mapped |
| `Sessions/Epoch/SessionEpochStoreInterface.php` | Remember-me epoch store contract (spec [005](../005-session-host-hooks/spec.md)) | 005 FR-002 | Mapped |
| `Sessions/Epoch/DoctrineSessionEpochStore.php` | Default epoch store (DBAL, `user_kit_session_epoch`) | 005 FR-002 | Mapped |
| `Sessions/Epoch/Entity/UserSessionEpoch.php` | Epoch row mapping (only with the default store) | 005 FR-002 | Mapped |
| `Sessions/Epoch/RememberMeEpoch.php` | Bumps the epoch, re-issues the kept session's remember-me cookie | 005 FR-002 | Mapped |
| `Sessions/Epoch/EpochRememberMeHandler.php` | Wraps the built-in remember-me handlers; refuses cookies older than the epoch | 005 FR-002 | Mapped |
| `Sessions/Event/UserSessionsDeletedEvent.php` | Audit: every row of a user deleted | 005 FR-005 | Mapped |
| `Sessions/EventListener/UserRemovalListener.php` | `user_removal.delete_sessions`: rows of removed profile users | 005 FR-005 | Mapped |
| `Resources/views/sessions/index.html.twig` | "My sessions" (UiKit list scaffold, row cells only) | FR-SES-005 | Mapped |
| `Resources/views/sessions/admin.html.twig` | Administration of one user's sessions (UiKit list scaffold) | FR-SES-005 | Mapped |
| `Resources/views/sessions/_cells.html.twig` | Shared row cells (device, network, signed in, last active) | FR-SES-005 | Mapped |

## Translations

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/translations/NowoUserKitBundle.en.yaml` | i18n | FR-I18N-001 | Mapped |
| `Resources/translations/NowoUserKitBundle.es.yaml` | i18n | FR-I18N-001 | Mapped |
| `Resources/translations/NowoUserKitBundle.it.yaml` | i18n | FR-I18N-001 | Mapped |
| `Resources/translations/NowoUserKitBundle.fr.yaml` | i18n | FR-I18N-001 | Mapped |
| `Resources/translations/NowoUserKitBundle.pt.yaml` | i18n | FR-I18N-001 | Mapped |
| `Resources/translations/NowoUserKitBundle.de.yaml` | i18n | FR-I18N-001 | Mapped |
| `Resources/translations/NowoUserKitBundle.nl.yaml` | i18n | FR-I18N-001 | Mapped |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Symfony config & profiles | 6 | 6 |
| PHP — bundle core | 4 | 4 |
| PHP — contracts & traits | 4 | 4 |
| PHP — security & presence | 6 | 6 |
| PHP — Twig | 1 | 1 |
| PHP — UI preferences & product tour (002) | 18 | 18 |
| PHP — personal data / GDPR (003) | 15 | 15 |
| Sessions — session registry and limits (004, 005) | 51 | 51 |
| Translations | 7 | 7 |
| **Total production sources** | **112** | **112** |

Audit command (production count): `git ls-files src | wc -l` → **112** (2026-11-02). Feature specs `002-ui-preferences` and `003-personal-data` are inventoried here (FR ids prefixed with the spec number); `004-sessions` through the baseline FR-SES-003 … FR-SES-010 ids.

## Inventory refresh (2026-11-02)

- 1.10.0 session host hooks (spec `005-session-host-hooks`): 7 new files (`Sessions/Epoch/**`, `UserSessionsDeletedEvent`, `UserRemovalListener`) mapped under the Sessions category; changed files keep their rows.

## Inventory refresh (2026-11-01)

- 1.9.0 session registry: 44 files (`src/Sessions/**`, `SessionsConfiguration`, `SessionsLoader`, `SessionsPass`, `TwigPathsPass`, the three `sessions/*.html.twig` views) mapped under the new Sessions category (REQ-SPECKIT-003 re-audit).

## Inventory refresh (2026-09-25)

- FrankenPHP worker audit (`docs/FRANKENPHP-WORKER-AUDIT.md`); `LastActivitySubscriber` throttle/EM/firewall hardening; PHPStan `ruleset-worker-no-kernel-reset`.

## Inventory refresh (2026-07-29 remedia)

- `Profile/UnknownProfileException.php`
