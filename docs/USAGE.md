# Usage

## Table of contents

- [Configuration profiles](#configuration-profiles)
- [Account status checker](#account-status-checker)
- [Last activity tracking](#last-activity-tracking)
- [Online presence](#online-presence)
- [Twig helper](#twig-helper)
- [Session invalidation on disable](#session-invalidation-on-disable)
- [Entity traits (optional)](#entity-traits-optional)
- [UI preferences](#ui-preferences)
  - [`<html>` data attributes](#html-data-attributes)
- [Product tour progress](#product-tour-progress)
- [Personal data export and erasure (GDPR)](#personal-data-export-and-erasure-gdpr)
  - [Export](#export)
  - [Erasure](#erasure)
- [Translation overrides (REQ-I18N-001)](#translation-overrides-req-i18n-001)
  - [Override in the application](#override-in-the-application)
- [AuthKit coexistence](#authkit-coexistence)
- [Forms in Twig (REQ-TWIG-005)](#forms-in-twig-req-twig-005)

## Configuration profiles

Since **1.1.0**, settings can be grouped under named **profiles** when the application has more than one user entity. Each profile maps a `user_class` to its own `account_status` and `last_activity` options.

The bundle resolves the profile automatically from the entity class at runtime. You can also pass a profile name explicitly to presence checks.

See [Configuration — Profiles](CONFIGURATION.md#profiles) for the YAML structure.

## Account status checker

When a profile has `account_status.enabled: true`, `AccountStatusUserChecker` applies to authenticated users of that profile's `user_class`.

The checker runs on **both** `checkPreAuth` and `checkPostAuth`, so disabled accounts are blocked for:

- Standard form / authenticator login (post-auth)
- AuthKit flows that call `Security::login()` — magic login, social, QR (pre-auth only)

The checker is attached automatically to **every security-enabled firewall** (no `user_checker` firewall option needed). At compile time the bundle tags it `security.user_checker.<firewall>` and points the firewall checker at Symfony’s per-firewall chain (`security.user_checker.chain.<firewall>`). If a firewall already sets its own `user_checker`, that service is kept in the same chain, so both run. Firewalls with `security: false` are untouched. Set `account_status.enabled: false` on every profile to opt out.

- Users with `enabled: false` (or the configured field) cannot authenticate — Symfony throws `DisabledException`.
- If the entity implements `AccountStatusInterface`, `isEnabled()` is used.
- Otherwise the configured property is read via the PropertyAccess component.

No application code is required beyond configuration and entity mapping.

## Last activity tracking

When a profile has `last_activity.enabled: true`, `LastActivitySubscriber` updates the configured timestamp field on authenticated HTTP requests for that user class (main request behind a security-enabled firewall). The column is written with a DQL `UPDATE` by identifier (no `flush()`): pending changes of other entities stay pending, and Doctrine lifecycle callbacks / entity listeners are not called for this write. When the field is not a mapped Doctrine column, the subscriber falls back to `flush()`. The in-memory throttle is worker-safe under FrankenPHP without kernel reset; see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

Writes are throttled by `update_throttle` (seconds) to reduce database load.

## Online presence

Inject `UserPresenceResolver`:

```php
use Nowo\UserKitBundle\Presence\UserPresenceResolver;

final class UserDirectoryController
{
    public function __construct(
        private readonly UserPresenceResolver $presenceResolver,
    ) {
    }

    public function show(User $user): Response
    {
        $online = $this->presenceResolver->isOnline($user);
        // Or with an explicit profile name:
        // $online = $this->presenceResolver->isOnline($user, 'admin');

        // ...
    }
}
```

Returns `true` when `now - lastActivityAt <= online_threshold`; `false` when the timestamp is missing or the threshold is exceeded.

## Twig helper

When `twig: true` and `symfony/twig-bundle` is installed:

```twig
{% if user_is_online(user) %}
    <span class="badge bg-success">Online</span>
{% else %}
    <span class="badge bg-secondary">Offline</span>
{% endif %}

{# Optional explicit profile: #}
{# {% if user_is_online(admin, 'admin') %} ... {% endif %} #}
```

## Session invalidation on disable

When a profile sets `account_status.invalidate_sessions_on_disable: true`, `AccountDisabledListener` is registered as a Doctrine **entity listener** on that profile’s `user_class` (event `postUpdate`). When the configured enabled field changes from `true` to `false` in a flush, it calls `SessionInvalidatorInterface::invalidateSessionsForUser($user)`.

The default implementation, `DefaultSessionInvalidator`, is a **no-op**: Symfony has no generic API to find and destroy another user’s sessions, so nothing is cleared out of the box. The disabled user can no longer log in (see the checker above), but an already-open session stays valid until it expires unless you plug in a real invalidator, for example:

- **Database / Redis session store** — store the user identifier next to each session (e.g. a `user_id` column on a `PdoSessionHandler` table, or a Redis set per user) and delete those rows/keys.
- **Remember-me** — with persistent tokens, delete the user’s rows in `rememberme_token`.

```php
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SessionInvalidatorInterface::class)]
final class PdoSessionInvalidator implements SessionInvalidatorInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function invalidateSessionsForUser(object $user): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute(['id' => $user->getId()]);
    }
}
```

The invalidator runs inside Doctrine `postUpdate`: do not call `flush()` from it.

Without a session store, you can still log disabled users out on their next request by making the user entity implement Symfony’s `EquatableInterface` and returning `false` from `isEqualTo()` when the refreshed user’s enabled flag differs from the one stored in the session; the firewall then drops the stale token.

## Entity traits (optional)

```php
use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\Model\EnabledUserTrait;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Model\LastActivityTrait;

#[ORM\Entity]
class User implements UserInterface, AccountStatusInterface, LastActivityInterface
{
    use EnabledUserTrait;
    use LastActivityTrait;
}
```

Customize field names via configuration (`account_status.field`, `last_activity.field`) when not using the default trait property names.

## UI preferences

Since **1.4.0**. Generic display preferences stored as columns on the user table: preferred locale, theme, content width, density, motion, font scale, contrast, sidebar and footer.

| Column | Type | Length | Nullable | PHP default | Choices (enum) |
| --- | --- | --- | --- | --- | --- |
| `preferred_locale` | string | 8 | yes | `null` (getter falls back to `en`) | free (trimmed, lower-cased) |
| `preferred_theme` | string | 8 | yes | `light` | `Theme`: `light`, `dark` |
| `preferred_content_width` | string | 8 | yes | `content` | `ContentWidth`: `content`, `full` |
| `preferred_ui_density` | string | 16 | yes | `comfortable` | `UiDensity`: `comfortable`, `compact` |
| `preferred_motion` | string | 16 | yes | `system` | `Motion`: `system`, `reduce`, `full` |
| `preferred_font_scale` | string | 8 | yes | `md` | `FontScale`: `sm`, `md`, `lg` |
| `preferred_contrast` | string | 8 | yes | `system` | `Contrast`: `system`, `more` |
| `preferred_sidebar` | string | 16 | yes | `expanded` | `SidebarMode`: `expanded`, `rail`, `collapsed` |
| `preferred_footer` | string | 16 | yes | `scroll` | `FooterMode`: `scroll`, `fixed` |

Column names are explicit (independent of the Doctrine naming strategy) and there is no database default. The enums live in `Nowo\UserKitBundle\Model\Preference\` and expose `DEFAULT`, `values()` and `normalize()`.

**Option A — ready-made embeddable** (requires the [Doctrine mapping entry](CONFIGURATION.md#doctrine-mapping-for-the-kit-embeddables)):

```php
use Nowo\UserKitBundle\Model\UiPreferences;

#[ORM\Entity]
class User
{
    #[ORM\Embedded(class: UiPreferences::class, columnPrefix: false)]
    private UiPreferences $uiPreferences;

    public function __construct()
    {
        $this->uiPreferences = new UiPreferences();
    }

    public function getUiPreferences(): UiPreferences
    {
        return $this->uiPreferences;
    }
}
```

**Option B — trait in your own embeddable or entity**, next to host-specific columns (same column names, so switching between A and B needs no migration):

```php
use Nowo\UserKitBundle\Model\UiPreferencesInterface;
use Nowo\UserKitBundle\Model\UiPreferencesTrait;

#[ORM\Embeddable]
class UserUiPreferences implements UiPreferencesInterface
{
    use UiPreferencesTrait;

    #[ORM\Column(options: ['default' => true])]
    private bool $pushNotificationsEnabled = true; // host-only column
}
```

API (from `UiPreferencesTrait`):

- `getPreferred*()` always returns a valid choice; `getPreferred*Raw()` returns the stored value (nullable).
- `setPreferred*(Enum|string|null)` trims and lower-cases strings; unknown values are stored as `null` (no exception), so raw form / AJAX input can be passed as-is. Setters return `static`.
- `applyMissingUiPreferences(string $defaultLocale = 'en')` fills null columns with the defaults (call it from a `prePersist` listener so new accounts store concrete values).
- `resetUiPreferences()` clears every column (GDPR erasure).

### `<html>` data attributes

`user_ui_html_attributes(subject, {resolved: false})` prints the attributes read by a pre-paint theme boot script. `subject` is a `UiPreferencesInterface` or a user exposing `getUiPreferences()`; guests (`null`) or users without preferences print nothing.

```twig
<html lang="{{ app.request.locale }}" {{ user_ui_html_attributes(app.user) }}>
```

| Attribute | Value |
| --- | --- |
| `data-footer-fixed` | `1`, only when the footer preference is `fixed` |
| `data-theme`, `data-user-theme` | theme |
| `data-user-density` | density |
| `data-user-motion` | motion |
| `data-user-font-scale` | font scale |
| `data-user-contrast` | contrast |
| `data-user-sidebar` | sidebar mode |
| `data-user-footer` | footer mode |
| with `resolved: true` also `data-ui-density`, `data-motion`, `data-font-scale`, `data-contrast`, `data-content-width` | the resolved values, for pages rendered without the boot script |

The boot script reads the `data-user-*` inputs and writes the resolved attributes (`data-theme`, `data-ui-density`, `data-motion`, `data-font-scale`, `data-contrast`) that CSS selects on; the names match the existing Beacon contract and the planned UiKit theme boot. Outside Twig, use the `UiPreferencesHtmlAttributes` service (`build()` returns the array, `render()` the escaped string).

## Product tour progress

Since **1.4.0**. Seen-page tracking for product tours (the tour driver and step content stay in the application):

| Column | Type | Nullable |
| --- | --- | --- |
| `product_tour_seen_at` | `datetime_immutable` | yes — set when every tour is done or suppressed |
| `product_tour_seen_pages` | `json` | yes — list of completed tour page ids |

Use the ready-made `ProductTourProgress` embeddable (`#[ORM\Embedded(class: ProductTourProgress::class, columnPrefix: false)]`) or `ProductTourProgressTrait` in your own class. Tour page ids are strings or backed enums. The host catalog is passed as `$knownPages`, or declared once by overriding `productTourPages()` in the class using the trait:

```php
#[ORM\Embeddable]
class UserUiPreferences implements UiPreferencesInterface, ProductTourProgressInterface
{
    use ProductTourProgressTrait;
    use UiPreferencesTrait;

    protected function productTourPages(): array
    {
        return ProductTourPage::cases(); // host enum
    }
}

$preferences->markPageSeen(ProductTourPage::Dashboard); // sets product_tour_seen_at once every page is seen
$preferences->hasSeenPage('dashboard');
$preferences->getEnabledPages();                         // pages whose tour should still auto-start
$preferences->syncEnabledPages($form->get('pages')->getData()); // settings form: "replay these tours"
$preferences->markProductTourSeen();                     // suppress every tour
$preferences->clearProductTourSeen();
```

Without a catalog, `markPageSeen()` / `hasSeenPage()` accept any id; `getEnabledPages()` and `syncEnabledPages()` need one and throw `LogicException` otherwise.

`ModelResolver::uiPreferences($user)` and `ModelResolver::productTour($user)` find the models on a user (the user itself, or `getUiPreferences()` / `getProductTourProgress()` / `getProductTour()`).

## Personal data export and erasure (GDPR)

Since **1.5.0**, with `nowo_user_kit.personal_data.enabled: true`. The kit ships the orchestration (SPI, exporter, transactional eraser, guard hook); controllers, routes, confirmation forms and domain guards stay in the application.

| Tag (autoconfigured from the interface) | Interface | Role |
| --- | --- | --- |
| `nowo_user_kit.personal_data.export_provider` | `PersonalDataExportProviderInterface` | One section of the export document. |
| `nowo_user_kit.personal_data.eraser` | `PersonalDataEraserInterface` | Deletes / anonymises one kind of data. |
| `nowo_user_kit.personal_data.erasure_guard` | `ErasureGuardInterface` | Vetoes an erasure with a reason. |

Order is the tag priority (higher first; `#[AsTaggedItem(priority: …)]` or `tags: [{ name: …, priority: … }]`). Host services default to `0`; the built-ins use `-100` and below, so they come after host sections / erasers.

### Export

```php
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

final class AccountSectionProvider implements PersonalDataExportProviderInterface
{
    public function getSectionKey(): string { return 'account'; }

    public function supports(object $user): bool { return $user instanceof User; }

    public function export(object $user): array
    {
        return ['email' => $user->getEmail(), 'created_at' => $user->getCreatedAt()];
    }
}
```

`PersonalDataExporter::export($user)` returns (`exportJson()` encodes it):

```json
{
    "schema": "nowo-user-kit/personal-data",
    "version": 1,
    "exported_at": "2026-10-13T10:00:00+00:00",
    "sections": {
        "account": {"email": "jane@example.com", "created_at": "2026-01-01T00:00:00+00:00"},
        "account_status": {"enabled": true},
        "last_activity": {"last_activity_at": "2026-10-13T09:58:00+00:00"},
        "ui_preferences": {"preferred_locale": "en", "preferred_theme": "dark", "...": "..."},
        "product_tour": {"seen_at": null, "seen_pages": ["dashboard"]}
    }
}
```

Nested `DateTimeInterface` values become ATOM strings and enums their value (backed) or name. Section keys must be unique (`LogicException` otherwise); providers whose `supports()` returns false are skipped.

Built-in sections: `account_status` (`AccountStatusInterface` or the profile `account_status.field`), `last_activity` (`LastActivityInterface` or `last_activity.field`), `ui_preferences` and `product_tour` (the 1.4.0 models, found on the user or through `getUiPreferences()` / `getProductTourProgress()`).

### Erasure

```php
use Nowo\UserKitBundle\PersonalData\ErasureGuardInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\ErasureVeto;

final class LastAdminGuard implements ErasureGuardInterface
{
    public function check(ErasureRequest $request): ?ErasureVeto
    {
        return $this->isLastAdmin($request->user) ? new ErasureVeto('last_admin') : null;
    }
}
```

```php
// Pre-flight for the privacy page: list<ErasureVeto> (nothing erased)
$vetoes = $eraser->check($user, $actor);

try {
    $erased = $eraser->erase($user, $actor, ['scope' => 'self']); // list of eraser keys that ran
} catch (ErasureVetoedException $e) {
    $this->addFlash('error', 'privacy.'.$e->getReasons()[0]);
}
```

`erase()` runs every guard first (any veto throws `ErasureVetoedException` with all vetoes, before anything changes), then every supporting eraser in priority order inside **one** Doctrine transaction (`wrapInTransaction()` on the entity manager of the user class), flushed once. An exception from an eraser rolls everything back. Erasers must not call `flush()` or open transactions; DBAL statements on the same connection join the transaction.

`ErasureRequest` carries `user`, `actor` (nullable) and `context` (free array); `isSelfService()` is true without an actor or when the actor is the user. `ErasureVeto` has `reason` (stable code), `message` and `details` (e.g. the blocking projects).

Built-in erasers: `last_activity` (`clearLastActivityAt()`, provided by `LastActivityTrait` since 1.5.0), `ui_preferences` (`resetUiPreferences()` from `UiPreferencesTrait`), `product_tour` (`clearProductTourSeen()`). Account status is not changed by the kit: disabling / anonymising the account itself is a host eraser.

## Translation overrides (REQ-I18N-001)

Domain: **`NowoUserKitBundle`**

The bundle ships `de`, `en`, `es`, `fr`, `it`, `nl`, and `pt` in `src/Resources/translations/`.

### Override in the application

```yaml
# translations/NowoUserKitBundle.es.yaml
account:
    disabled: 'Tu cuenta no está disponible. Contacta con soporte.'
```

Symfony uses app translations first; missing keys fall back to the bundle.

## AuthKit coexistence

```
Login (AuthKit) → form_login → UserProvider → UserChecker (UserKit) → OK / blocked
```

Install both bundles with the same `user_class`. UserKit does not provide login routes or forms — it complements AuthKit's authentication UI with account status and presence features.

## Forms in Twig (REQ-TWIG-005)

Every UI form — bundle views and the demo apps — is a PHP Symfony Form Type (FormKit types when FormKit is in the stack) rendered with `form_start`, the REQ-TWIG-003 child loop and `form_end`; submit / cancel buttons may stay outside the loop:

```twig
{{ form_start(form) }}
    {% for child in form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button type="submit">Save</button>
{{ form_end(form) }}
```

CSRF-only actions (delete, logout-style buttons, toggles) use an empty Form Type too, and login forms map `_username` / `_password` / `_csrf_token` (token id `authenticate`) on a Form Type. Host overrides and demos must not reintroduce raw `<form>` / `<input>` / `<textarea>` / `<select>` markup: `make check-no-raw-html-form` (part of `make release-check`) fails when it appears.
