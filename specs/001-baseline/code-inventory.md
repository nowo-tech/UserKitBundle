# Code inventory — traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/user-kit-bundle`  
**Last audited**: 2026-10-20  
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
| Translations | 7 | 7 |
| **Total production sources** | **61** | **61** |

Audit command (production count): `git ls-files src | wc -l` → **61** (2026-10-20). Feature specs `002-ui-preferences` and `003-personal-data` are inventoried here (FR ids prefixed with the spec number).

## Inventory refresh (2026-09-25)

- FrankenPHP worker audit (`docs/FRANKENPHP-WORKER-AUDIT.md`); `LastActivitySubscriber` throttle/EM/firewall hardening; PHPStan `ruleset-worker-no-kernel-reset`.

## Inventory refresh (2026-07-29 remedia)

- `Profile/UnknownProfileException.php`
