# Feature Specification: UI preferences and product-tour progress

**Feature Branch**: `002-ui-preferences`  
**Created**: 2026-10-13  
**Status**: Implemented (v1.4.0)

**Package**: `nowo-tech/user-kit-bundle`  
**Configuration root**: `nowo_user_kit` (no new keys; `twig` also gates the new Twig function)  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Origin**: Symfony Beacon spec `138-kit-medium-extractions` FR-030 (KIT-UI-PREFS) — the generic part of Beacon's `UserUiPreferences` embeddable moves into the kit; issue-panel, push and alert columns stay in Beacon.

---

## Summary

| Unit | Purpose |
| ---- | ------- |
| `Model\UiPreferencesTrait` + `Model\UiPreferencesInterface` | Nine nullable `preferred_*` string columns with validated setters and defaulting getters. |
| `Model\UiPreferences` | Ready-made `#[ORM\Embeddable]` using the trait. |
| `Model\Preference\*` | Backed enums for the choices (`Theme`, `ContentWidth`, `UiDensity`, `Motion`, `FontScale`, `Contrast`, `SidebarMode`, `FooterMode`). |
| `Model\ProductTourProgressTrait` + `Model\ProductTourProgressInterface` | `product_tour_seen_at` / `product_tour_seen_pages` with `markPageSeen()`, `hasSeenPage()`, `syncEnabledPages()`. |
| `Model\ProductTourProgress` | Ready-made `#[ORM\Embeddable]` using the trait. |
| `Model\ModelResolver` | Finds the models on a user (the user itself or `getUiPreferences()` / `getProductTourProgress()` / `getProductTour()`). |
| `Preference\UiPreferencesHtmlAttributes` + Twig `user_ui_html_attributes()` | `<html>` `data-*` attributes for the pre-paint theme boot. |

Everything is opt-in: no configuration key, no schema change for applications that do not use the models.

---

## User Scenarios

### US-01 — Adopt without a migration (P1)

**Given** an application whose user table already has the `preferred_*` and `product_tour_seen_*` columns (Beacon layout),  
**When** it embeds `UiPreferences` / `ProductTourProgress` with `columnPrefix: false`, or uses the traits in its own embeddable,  
**Then** `doctrine:schema:validate` reports no difference and `make:migration` is empty.

### US-02 — Host columns next to the kit columns (P1)

**Given** a host embeddable that `use`s `UiPreferencesTrait` and `ProductTourProgressTrait` and declares its own columns,  
**When** the entity is mapped,  
**Then** kit and host columns live in one embeddable, with unchanged names.

### US-03 — Untrusted input (P1)

**Given** a form or AJAX request posting `" DARK "` or `"sepia"` as theme,  
**When** `setPreferredTheme()` is called with the raw value,  
**Then** `dark` is stored for the first and `null` for the second (the getter returns `light`); nothing throws.

### US-04 — Pre-paint attributes (P1)

**Given** a logged-in user with preferences,  
**When** the layout prints `{{ user_ui_html_attributes(app.user) }}` on `<html>`,  
**Then** `data-theme`, `data-user-theme`, `data-user-density`, `data-user-motion`, `data-user-font-scale`, `data-user-contrast`, `data-user-sidebar`, `data-user-footer` (and `data-footer-fixed="1"` when fixed) are printed, escaped; guests get an empty string.

### US-05 — Tour pages (P2)

**Given** a tour catalog `[dashboard, issues, admin]`,  
**When** the user completes each page tour (`markPageSeen()`),  
**Then** the pages are stored, and `product_tour_seen_at` is set once every catalog page is seen; `syncEnabledPages()` stores the complement of the pages the user wants to replay.

---

## Functional requirements

- **FR-001**: Column mapping (explicit names, no DB default):

  | Column | Type | Length | Nullable |
  | --- | --- | --- | --- |
  | `preferred_locale` | string | 8 | yes |
  | `preferred_theme` | string | 8 | yes |
  | `preferred_content_width` | string | 8 | yes |
  | `preferred_ui_density` | string | 16 | yes |
  | `preferred_motion` | string | 16 | yes |
  | `preferred_font_scale` | string | 8 | yes |
  | `preferred_contrast` | string | 8 | yes |
  | `preferred_sidebar` | string | 16 | yes |
  | `preferred_footer` | string | 16 | yes |
  | `product_tour_seen_at` | datetime_immutable | — | yes |
  | `product_tour_seen_pages` | json | — | yes |

- **FR-002**: PHP defaults match Beacon's `UserDisplayPreferenceDefaults`: locale `null` (getter `en`), theme `light`, width `content`, density `comfortable`, motion `system`, font scale `md`, contrast `system`, sidebar `expanded`, footer `scroll`.
- **FR-003**: Setters accept `Enum|string|null`, trim and lower-case strings, store `null` for unknown values; getters return the enum `DEFAULT` for null / unknown stored values; `*Raw()` getters return the stored value.
- **FR-004**: `applyMissingUiPreferences($defaultLocale)` fills nulls; `resetUiPreferences()` clears all nine columns.
- **FR-005**: Product tour: catalog per call (`$knownPages`, strings or backed enums) or via the overridable `productTourPages()` hook; empty catalog accepts any id for `markPageSeen()` / `hasSeenPage()`; `getEnabledPages()` / `syncEnabledPages()` require a catalog (`LogicException`).
- **FR-006**: Twig `user_ui_html_attributes(subject, {resolved})` (HTML-safe output) registered with `twig: true` when Twig is installed; `resolved: true` adds `data-ui-density`, `data-motion`, `data-font-scale`, `data-contrast`, `data-content-width`.
- **FR-007**: The ready-made embeddables need a Doctrine mapping entry for `Nowo\UserKitBundle\Model` (documented); the traits do not.

## Tests

- `tests/Integration/UiPreferencesMappingKernelTest.php` — SQLite: column names / types / lengths / nullability for the kit embeddables and for a host embeddable using the traits, create-schema SQL, persist / reload round trips.
- `tests/Unit/Model/UiPreferencesTest.php`, `ProductTourProgressTest.php`, `ModelResolverTest.php`, `tests/Unit/Preference/UiPreferencesHtmlAttributesTest.php`.

## Host adoption (Beacon)

Beacon keeps a single `UserUiPreferences` embeddable (`columnPrefix: false`) and replaces its nine generic properties and the two tour properties with `use UiPreferencesTrait; use ProductTourProgressTrait;`, overriding `productTourPages()` with `ProductTourPage::cases()`. Issue-panel, push and alert columns stay in that class. Column names are unchanged, so no migration. Method renames: `markTourPageSeen()` → `markPageSeen()`, `hasSeenTourPage()` → `hasSeenPage()`, `getEnabledProductTourPages()` → `getEnabledPages()`, `syncEnabledProductTourPages()` → `syncEnabledPages()`; `resetForAnonymize()` calls `resetUiPreferences()` + `clearProductTourSeen()` and resets the host columns.
