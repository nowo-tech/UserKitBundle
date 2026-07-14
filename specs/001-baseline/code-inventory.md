# Code inventory — traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/user-kit-bundle`  
**Last audited**: 2026-07-14  
**Status**: Implemented

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/services.yaml` | DI wiring | FR-SEC-002, FR-PRES-001 | Mapped |
| `Resources/config/packages/nowo_user_kit.yaml` | Default config template | FR-CFG-002 | Mapped |

## PHP — bundle core

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `NowoUserKitBundle.php` | Bundle entry | FR-BUNDLE-001 | Mapped |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 | Mapped |
| `DependencyInjection/NowoUserKitExtension.php` | DI extension | FR-CFG-002 | Mapped |

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
| `EventSubscriber/LastActivitySubscriber.php` | Request subscriber | FR-PRES-001 | Mapped |
| `Presence/UserPresenceResolver.php` | Online detection | FR-PRES-002 | Mapped |
| `EventListener/AccountDisabledListener.php` | Session invalidation | FR-SES-001 | Mapped |
| `Session/SessionInvalidatorInterface.php` | Extension point | FR-SES-002 | Mapped |
| `Session/DefaultSessionInvalidator.php` | Default strategy | FR-SES-002 | Mapped |

## PHP — Twig

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Twig/UserPresenceExtension.php` | Twig helper | FR-PRES-002, US-09 | Mapped |

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
| Bundle + DI | 3 | 3 |
| Contracts & traits | 4 | 4 |
| Security & presence | 6 | 6 |
| Twig | 1 | 1 |
| Symfony config | 2 | 2 |
| Translations | 7 | 7 |
| **Total production sources** | **23** | **23** |
