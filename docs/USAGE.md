# Usage

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

When a profile has `last_activity.enabled: true`, `LastActivitySubscriber` updates the configured timestamp field on authenticated HTTP requests for that user class (main request behind a security-enabled firewall). The in-memory throttle is worker-safe under FrankenPHP without kernel reset; see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

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
