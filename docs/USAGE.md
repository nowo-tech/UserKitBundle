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
- [Session registry and limits](#session-registry-and-limits)
  - [How it works](#how-it-works)
  - [Pages](#pages)
  - [PHP API](#php-api)
  - [Audit events](#audit-events)
  - [Concurrent-session limit](#concurrent-session-limit)
  - [Device ids](#device-ids)
  - [Automatic sign-outs](#automatic-sign-outs)
  - [Sessions that are not the request's own (1.11)](#sessions-that-are-not-the-requests-own-111)
  - [AuthKit hook points](#authkit-hook-points)
  - [Retention purge](#retention-purge)
  - [Personal data](#personal-data)
  - [Remember-me epoch](#remember-me-epoch)
  - [Host-defined revocation reasons](#host-defined-revocation-reasons)
  - [Signed-out JSON clients](#signed-out-json-clients)
  - [Deleting users](#deleting-users)
  - [Host with its own session UI](#host-with-its-own-session-ui)
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
| `preferred_content_width` | string | 8 | yes | `content` | `ContentWidth`: `narrow`, `content`, `wide`, `wider`, `full` |
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

## Session registry and limits

Since **1.9.0**, opt-in (`sessions.enabled: true`, see [CONFIGURATION](CONFIGURATION.md#session-registry-sessions)).
One row per signed-in browser session: users see where they are signed in and sign sessions out, administrators do the
same for any user, and an optional concurrent-session limit applies at sign-in. Revocation works with **any session
handler** (files, Redis, database, Memcached…): the registry is the source of truth, the session only carries a small
marker.

### How it works

```mermaid
sequenceDiagram
    participant B as Browser
    participant L as SessionRegistryListener
    participant R as Registry (user_kit_session)
    B->>L: POST /login (credentials OK)
    L->>R: reject_new? count live sessions (CheckPassportEvent)
    L->>R: evict_oldest? revoke oldest; insert row (LoginSuccessEvent)
    B->>L: GET /page (before the firewall)
    L->>R: revoked / gone / idle? → invalidate session, drop remember-me, flash
    B->>L: GET /page (after the firewall)
    L->>R: touch last_seen_at (at most once per touch_interval)
    Note over B,R: Revoking (page, API, password change…) marks the row;<br/>the session ends on its next request
```

- **Sign-in** (`LoginSuccessEvent`, stateful firewalls only): the previous row of the session goes, the limit is
  applied, the new session is registered (browser / OS names, network, optional device id).
- **Each request, before the firewall**: a session whose row is revoked (or gone), or that stayed idle longer than
  `idle_lifetime`, is signed out — session invalidated, token dropped, remember-me cookies removed from the request and
  cleared on the response, `sessions.signed_out.<reason>` flash (on `_stateless` routes too). The request goes on as a guest, so your firewall
  answers as usual (login redirect, 401…).
- **Each request, after the firewall**: `last_seen_at` is written at most once per `touch_interval`. A session that was
  signed in before the feature was enabled is registered on its next request.
- **Logout** (`LogoutEvent`): the row is deleted.
- Stateless firewalls are ignored. `_stateless` routes of a stateful firewall are **checked** (revocation, idle
  expiry) before the firewall, without counting as a session use, but never write the session: activity there does
  not refresh `last_seen_at`. While impersonating (`_switch_user`) the original user's session is the one tracked.
- One marker per firewall context and one row per (session, owner): a session signed in on two firewalls with
  separate contexts shows up for both owners, and revoking either row ends the whole session.
- Remember-me cookies: the cookie of every firewall is read from its `remember_me` configuration (name, path,
  domain, secure, SameSite) and cleared with those options; `sessions.remember_me_cookies` adds extra ones.

### Pages

Enable `sessions.web_ui.enabled` and import the routes (see [CONFIGURATION](CONFIGURATION.md#routes-and-access-control)):

- **My sessions** (`{path_prefix}`): device (browser on OS), network, signed in, last active, *This session* badge;
  *Sign out* on every other row and *Sign out all other sessions* in the header.
- **Administration** (`{admin_path_prefix}/{profile}/{user}`): one user's sessions, *Sign out* per row and
  *Sign out everywhere*. Link to it from your user page:

```twig
{% set sessions_url = user_kit_sessions_admin_path(user) %}
{% if sessions_url %}<a href="{{ sessions_url }}">{{ 'Sessions'|trans }}</a>{% endif %}
```

Both pages are UiKit list scaffolds; every action is a POST with a FormKit CSRF-only form (one token per action and
row) behind the UiKit confirm dialog, checked server-side (invalid or missing token → 403). Templates, view models and
overrides: [TEMPLATES](TEMPLATES.md).

### PHP API

`Nowo\UserKitBundle\Sessions\UserSessionManager` (public service):

```php
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\UserSessionManager;

final class AccountSecurity
{
    public function __construct(private readonly UserSessionManager $sessions) {}

    public function afterTwoFactorChange(User $user, Request $request): void
    {
        // Everything but the current browser.
        $this->sessions->revokeOthers($user, $this->sessions->currentHash($request));
    }

    public function suspend(User $user, User $admin): void
    {
        $this->sessions->revokeAll($user, $admin);                              // reason "admin"
        // or: $this->sessions->revokeAll($user, null, RevocationReason::AccountChanged);
    }
}
```

| Method | Effect |
| --- | --- |
| `listFor($user)` | Live sessions (`Sessions\Entity\UserSession`), most recent first (≤ 200). |
| `revoke($user, $publicId, $actor = null, ?string $customReason = null, array $context = [], bool $invalidateRememberMe = false)` | One session (reason `signed_out`, or `admin` when `$actor` is someone else); with `invalidateRememberMe`, bumps the remember-me epoch too (see [Remember-me epoch](#remember-me-epoch)). |
| `revokeOthers($user, $currentHash, $actor = null, ?string $customReason = null, array $context = [])` | Every session but `$currentHash` (reason `others`); bumps the remember-me epoch when enabled. |
| `revokeAll($user, $actor = null, $reason = Everywhere, $except = null, ?string $customReason = null, array $context = [])` | Every session (reason `everywhere`, `admin` when `$actor` is someone else, or yours); bumps the remember-me epoch when enabled. |
| `revokeDevice($user, $deviceId, $except = null, $actor = null, ?string $customReason = null, array $context = [], bool $invalidateRememberMe = false)` | The sessions of one device (reason `device`); `invalidateRememberMe` as for `revoke()`. |
| `currentHash($requestOrSession)` | Registry hash of the current session (null before it is registered). |
| `limitFor($user)` | `SessionLimit` from the configured resolver (`maxSessions`, `policy`, `maxTrustedDevices`, `extra`). |
| `invalidatesRememberMe()` | Whether the epoch is enabled (revokeAll / revokeOthers, and revoke / revokeDevice with `invalidateRememberMe`, void remember-me cookies). |
| `forgetUser($user)` / `forgetOwner(SessionUser $owner, $user = null)` | Revoke, then delete every row of a user; the epoch is bumped, not deleted. |
| `forgetEpoch(SessionUser $owner)` | Delete the epoch, only once the user is gone (old cookies would work again). |

`RegistrySessionInvalidator` implements the existing `SessionInvalidatorInterface`: with `sessions.enabled` it becomes
the default invalidator (unless you aliased your own), so `account_status.invalidate_sessions_on_disable` really signs
a disabled account out.

### Audit events

Every mutation dispatches an event **after** the rows are written (the session id is never part of an event):

| Event | When |
| --- | --- |
| `Sessions\Event\UserSessionStartedEvent` | A session was registered (`atLogin` true at sign-in). |
| `Sessions\Event\UserSessionsRevokedEvent` | Sessions were revoked: `owner`, `user`, `actor` (null for automatic ones), `reason`, `publicIds`. Explicit user actions dispatch it even when nothing matched. |
| `Sessions\Event\SessionLimitReachedEvent` | A sign-in hit the limit (`isRejected()`, `evicted` public ids). |
| `Sessions\Event\UserSessionsPurgedEvent` | The retention purge ran (`before`, `deleted`, `revokedBefore`). |
| `Sessions\Event\UserSessionsDeletedEvent` | Every row of a user was deleted (`forgetUser()` / user removal): `owner`, `user` (null after a Doctrine removal), `deleted`. |

`UserSessionsRevokedEvent` also carries `customReason`, `context` (host data of the call; never stored or logged by
the bundle) and `rememberMeInvalidated`; `reasonCode()` is the value stored on the rows.

```php
#[AsEventListener]
final class AuditSessionRevocations
{
    public function __construct(private readonly UserActionRecorder $audit) {}

    public function __invoke(UserSessionsRevokedEvent $event): void
    {
        $this->audit->record('sessions.revoked', $event->actor, $event->user, ['reason' => $event->reason->value, 'count' => $event->count()]);
    }
}
```

### Concurrent-session limit

`sessions.limit.max_sessions` / `policy` apply to everyone; for per-user limits implement
`SessionLimitResolverInterface` and set `sessions.limit.resolver` (called at sign-in only, never per request):

```php
final class PlanSessionLimits implements SessionLimitResolverInterface
{
    public function resolve(object $user): SessionLimit
    {
        return $user instanceof User && $user->isOnFreePlan()
            ? new SessionLimit(1, SessionLimitPolicy::RejectNew)
            : SessionLimit::unlimited();
    }
}
```

- `evict_oldest`: the sign-in succeeds; the least recently active sessions are revoked (reason `evicted`, their browsers
  see "You were signed out because you signed in elsewhere").
- `reject_new`: after the credentials are checked, the authenticator fails with `SessionLimitReachedException` (an
  already translated message, shown by `error.messageKey|trans(error.messageData, 'security')`). Signing in again from
  an already registered browser replaces its own session and is not refused. A `SessionLimitReachedEvent` listener may
  e-mail the user a signed link that calls `revokeAll()`, and replace the message with `setMessage()`.

Two sign-ins at exactly the same time may both pass the check (no lock); the next sign-in applies the limit again.

**Trusted devices.** UserKit keeps no trusted devices, so it does not enforce `maxTrustedDevices`: the value travels in
`SessionLimit` so that one resolver serves both limits. Return it from your resolver (or set
`limit.max_trusted_devices`) and check it where the host (or AuthKit) trusts a new device; `extra` carries any other
host-defined value:

```php
return new SessionLimit(5, SessionLimitPolicy::EvictOldest, maxTrustedDevices: 3, extra: ['plan' => 'pro']);

// where a device is about to be trusted:
$limit = $this->sessions->limitFor($user);
if ($limit->limitsTrustedDevices() && $this->devices->countTrusted($user) >= $limit->maxTrustedDevices) {
    // refuse, or untrust the oldest device and call $this->sessions->revokeDevice($user, $oldestId)
}
```

### Device ids

Implement `DeviceIdResolverInterface` (for example from a device-fingerprinting bundle) and set
`sessions.client.device_id_resolver`; then `revokeDevice($user, $deviceId)` ends that device's sessions (e.g. when its
trust is revoked). A device id is never a credential.

### Automatic sign-outs

- **Password change** (`sessions.password_change.revoke_sessions`, default on): a Doctrine `preUpdate` on the profile
  user classes notices the password field change (account page, reset by e-mail, admin, CLI); after the flush every
  other session of that user is revoked (reason `password_changed`); the session that changed it stays.
- **Account disabled**: with `account_status.invalidate_sessions_on_disable: true`, every session (reason
  `account_disabled`).
- **Anything else** (roles removed, membership suspended…): call `revokeAll($user, null, RevocationReason::AccountChanged)`.

### Sessions that are not the request's own (1.11)

AuthKitBundle's account switcher (1.34, `multi_account`) keeps one session per signed-in account in a browser and
parks the inactive ones. Each parked session keeps its own registry row (*My sessions* lists it as another session of
the same browser), its idle expiry, limits and revocations. The API it uses, also available to hosts:

| Method | Effect |
| --- | --- |
| `sessionState($sessionId, $user)` | `SessionState` (`status`: `live`, `revoked` + `reason`, `expired`, `unknown`; `lastSeenAt`). `expired` follows the early check (idle longer than `idle_lifetime` + `touch_interval`). Never exposes the hash |
| `sessionStates([[$sessionId, $user], …])` | The same for several sessions (one query per owner), in input order |
| `endSession($sessionId, $user)` | Signs a session out like a logout: the owner's row is deleted, no event. The caller destroys the session data |
| `rekey($session, $previousId)` | After `$session->migrate()` outside a sign-in, moves the rows of the session's markers to the new id at once |

Revoking a parked session (one row, *sign out everywhere*, password change, disable) makes it `revoked`: the switcher
drops that account only. A session id is a secret: never log it or store it in clear (AuthKit seals it).

### AuthKit hook points

AuthKit needs no change for the core features: its form login / remember-me / logout go through the Symfony security
events the registry listens to, and its password reset changes the password field (automatic sign-out). Optional
integrations an AuthKit (or host) release can add on top:

- after enabling / disabling 2FA, offer "sign out the other sessions" → `revokeOthers($user, $currentHash)`;
- on `SessionLimitReachedEvent` (`reject_new`), render "you are signed in on N devices" with a signed e-mail link whose
  controller calls `revokeAll($user)`;
- when a trusted device is revoked, `revokeDevice($user, $deviceId)`.

### Retention purge

Run the purge daily (cron or Symfony Scheduler); it deletes the rows not seen for `idle_lifetime` + `touch_interval`
(expired sessions, and revoked sessions that never came back), in batches:

```bash
php bin/console nowo:user-kit:sessions:purge --batch-size=1000
```

With `sessions.purge.revoked_retention` (seconds), revoked rows follow their own rule: deleted once revoked for that
long, whatever their last activity — longer to keep a "recently signed out" audit view, or `0` to drop them on the next
run. A purged revoked row still signs its browser out (database store: a missing row fails closed).

### Personal data

With `personal_data.enabled`, the export gets a `sessions` section (browser, OS, stored IP form, device id, dates,
revocation; never the hash) and the eraser `sessions` signs every session out and deletes the rows. The remember-me
epoch row (owner type, id, a timestamp) is kept while the user exists, so old remember-me cookies stay refused.

### Remember-me epoch

By default a copied or long-lived remember-me cookie signs back in after a revocation (it starts a **new**, visible
session). With `sessions.remember_me_epoch.enabled: true`, `revokeAll()` and `revokeOthers()` (pages, password change,
account disabled, your own calls) — and `revoke()` / `revokeDevice()` called with `invalidateRememberMe: true` — move
the user's epoch to the next whole second, and every remember-me cookie issued before it is refused — also for a
browser whose registry row was already purged or garbage-collected:

- the built-in remember-me handler of every firewall is wrapped by `Sessions\Epoch\EpochRememberMeHandler`. Signature
  cookies: issue time = signed expiry − `remember_me.lifetime`; when that lies more than 60 s in the future (the cookie
  was signed under a longer lifetime than today's) the issue time is unknown and the cookie is refused once the user
  has an epoch. Persistent cookies (`token_provider`): the token's server-side `lastUsed`. A refused cookie is an
  authentication failure, so Symfony clears it;
- the epoch is the **next whole second** (cookies carry whole seconds), so a cookie issued earlier in the revocation's
  second is refused too. Keep the servers' clocks in sync (NTP): skew widens or narrows that edge;
- the kept session (`revokeOthers`, `revokeAll(..., except: $hash)`, or the caller's current session for
  `revoke()` / `revokeDevice()` with `invalidateRememberMe`) gets a fresh remember-me cookie from the epoch second on:
  on the same response when that second has passed, else on its next request. Only when its request carries the
  firewall's cookie, and only for the firewall of that request (the epoch spans firewalls: the user's cookies of other
  firewalls are refused, and those browsers sign in again);
- **sign out one device so that it sticks**: `revoke($user, $publicId, invalidateRememberMe: true)` (or
  `revokeDevice(..., invalidateRememberMe: true)`). Plain `revoke()` leaves the revoked device's remember-me cookie
  valid for a cookie-only replay (it cannot be told apart from the user's other cookies). With the flag, the cookies of
  the user's **other** devices are voided too: those devices keep their current sessions but must sign in again once
  their sessions expire. Use it for "this wasn't me" / "sign out this device"; per-device remember-me revocation (token
  series linked to the session row, persistent handler only) is planned for 1.11;
- custom `remember_me.service` handlers are not wrapped: add a changing property to `signature_properties` instead;
- the epoch lives in `user_kit_session_epoch` (one row per user), or in your `SessionEpochStoreInterface` service
  (`remember_me_epoch.store`) — e.g. a `sessionEpoch` column of your user, which you may also list in
  `remember_me.signature_properties`.

Lowering `remember_me.lifetime` partially (e.g. one year to six months) still lets a pre-change cookie issued shortly
before an epoch look newer than it is (its derived issue time is shifted by the difference but not into the future).
After lowering the lifetime because of an incident, rotate the remember-me `secret` as well (every signature cookie
becomes invalid).

### Host-defined revocation reasons

The built-in reasons (`RevocationReason`) cover the bundle's own sign-outs. Pass a host reason and free-form context to
any revoke method; the reason is stored instead of the built-in one (32 characters, `^[a-z][a-z0-9_]{0,31}$`, not a
built-in value) and the signed-out browser gets `sessions.signed_out.<reason>` when you translate it (else the generic
message):

```php
$this->sessions->revokeAll($user, $actor, RevocationReason::AccountChanged, customReason: 'link', context: ['provider' => 'github']);
```

```yaml
# translations/NowoUserKitBundle.en.yaml
sessions:
    signed_out:
        link: 'You were signed out because your account was linked to another one.'
```

`UserSessionsRevokedEvent::$reason` stays the built-in category (`account_changed` above); `customReason`, `context` and
`reasonCode()` carry the rest. Keep secrets out of `context`: listeners may log it.

### Signed-out JSON clients

By default the early check signs a revoked session out and lets the request go on as a guest, so the firewall answers
(login redirect, or your entry point). With `sessions.early_check.json_response: true`, XHR requests and requests that
prefer JSON (`Accept: application/json`, `_format: json`) get `401 {"error":"session_signed_out"}` right away; HTML
requests are unchanged.

### Deleting users

The registry table has no foreign key to your user tables. When you delete a user, either call
`UserSessionManager::forgetUser($user)` before the removal (revokes the live sessions — `UserSessionsRevokedEvent`,
reason `account_changed` — deletes every row, bumps the epoch, dispatches `UserSessionsDeletedEvent`; call
`forgetEpoch($owner)` once the user is gone), or enable `sessions.user_removal.delete_sessions`: a Doctrine
`preRemove` entity listener on each profile user class remembers the owner and the rows go after the flush that really
removed the user (a cleared unit of work or an entity persisted again cancels it), and the epoch goes too. Without either, the rows are purged
after `idle_lifetime` + `touch_interval` and their sessions are signed out (the user cannot be refreshed).

### Host with its own session UI

Hosts that render their own "Devices / sessions" screens keep `web_ui.enabled: false` (no route, no template) and use
the PHP API:

```php
final class DevicesController
{
    public function __construct(private readonly UserSessionManager $sessions) {}

    public function list(Request $request, #[CurrentUser] User $user): Response
    {
        $current = $this->sessions->currentHash($request);
        $rows    = array_map(static fn (UserSession $s): array => [
            'id'        => $s->getPublicId(),               // opaque, safe in URLs and forms
            'browser'   => $s->getBrowser(),
            'os'        => $s->getOs(),
            'ip'        => $s->hasDisplayableIp() ? $s->getIp() : null,
            'last_seen' => $s->getLastSeenAt(),
            'current'   => $current !== null && hash_equals($s->getSessionHash(), $current),
        ], $this->sessions->listFor($user));
        // never pass the session hash (or the entity) to the template
        // ...render $rows
    }

    public function signOut(Request $request, #[CurrentUser] User $user, string $id): Response
    {
        // your CSRF check (one token id per action and row), then
        // ("this wasn't me" / "sign out this device" that sticks: also void the remember-me cookies of the user,
        //  this browser gets a fresh one; the user's other devices sign in again when their sessions expire):
        $this->sessions->revoke($user, $id, invalidateRememberMe: true);   // false → 404
    }

    public function signOutOthers(Request $request, #[CurrentUser] User $user): Response
    {
        // your CSRF check, then (also refreshes this browser's remember-me cookie with the epoch):
        $this->sessions->revokeOthers($user, $this->sessions->currentHash($request), customReason: 'link');
    }
}
```

Checklist for such hosts: scope every action to the signed-in user (the manager already filters by owner), one CSRF
token per action and row, `Cache-Control: no-store` on the pages, never expose `getSessionHash()`, enforce
`limitFor($user)->maxTrustedDevices` where devices are trusted, listen to `UserSessionsRevokedEvent` for the audit log,
and enable `remember_me_epoch` so "sign out everywhere" also voids remember-me cookies. Without `invalidateRememberMe`,
signing out one device does not void its remember-me cookie (see [Remember-me epoch](#remember-me-epoch)).

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

Install both bundles with the same `user_class`. UserKit does not provide login routes or forms — it complements AuthKit's authentication UI with account status, presence and (opt-in) the session registry pages.

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
