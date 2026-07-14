# Usage

## Account status checker

When `nowo_user_kit.account_status.enabled: true`, the bundle registers `AccountStatusUserChecker` as a Symfony `UserChecker`.

- Users with `enabled: false` (or the configured field) cannot authenticate — Symfony throws `DisabledException`.
- If the entity implements `AccountStatusInterface`, `isEnabled()` is used.
- Otherwise the configured property is read via the PropertyAccess component.

No application code is required beyond configuration and entity mapping.

## Last activity tracking

When `nowo_user_kit.last_activity.enabled: true`, `LastActivitySubscriber` updates the configured timestamp field on authenticated HTTP requests.

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
```

## Session invalidation on disable

When `account_status.invalidate_sessions_on_disable: true`, `AccountDisabledListener` runs on Doctrine `preUpdate` / `prePersist`.

If `enabled` changes from `true` to `false`, `DefaultSessionInvalidator` clears sessions for that user. Replace `SessionInvalidatorInterface` with a custom implementation if your app stores sessions differently.

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

## AuthKit coexistence

```
Login (AuthKit) → form_login → UserProvider → UserChecker (UserKit) → OK / blocked
```

Install both bundles with the same `user_class`. UserKit does not provide login routes or forms — it complements AuthKit's authentication UI with account status and presence features.
