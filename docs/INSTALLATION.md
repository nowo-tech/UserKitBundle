# Installation

## Requirements

- PHP 8.2 or higher (< 8.6)
- Symfony **7.4+** or **8.x** (minimum supported minors: 7.4, 8.0, 8.1)
- Doctrine ORM (user entity persistence)
- `symfony/security-bundle`

Optional:

- `symfony/twig-bundle` — for the `user_is_online` Twig helper
- [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) — login/register UI (complementary, not required)

## Translations

The bundle ships **`NowoUserKitBundle`** catalogues for **`de`**, **`en`**, **`es`**, **`fr`**, **`it`**, **`nl`**, and **`pt`**. Override keys in your application under `translations/NowoUserKitBundle.<locale>.yaml`. See [Usage — Translation overrides](USAGE.md#translation-overrides-req-i18n-001).

## Composer

```bash
composer require nowo-tech/user-kit-bundle
```

## Enable the bundle

Symfony Flex registers the bundle automatically. Manual registration:

```php
// config/bundles.php
return [
    // ...
    Nowo\UserKitBundle\NowoUserKitBundle::class => ['all' => true],
];
```

## Configuration file

Create `config/packages/nowo_user_kit.yaml`:

```yaml
nowo_user_kit:
    user_class: App\Entity\User
    account_status:
        enabled: true
    last_activity:
        enabled: true
        online_threshold: 300
```

See [Configuration](CONFIGURATION.md) for all options.

When AuthKit is installed, `user_class` may be omitted if `nowo_auth_kit.user_class` is already defined.

## User entity

Implement the bundle contracts or use the optional traits:

```php
use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\Model\EnabledUserTrait;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Model\LastActivityTrait;

class User implements AccountStatusInterface, LastActivityInterface
{
    use EnabledUserTrait;
    use LastActivityTrait;
}
```

Ensure Doctrine maps the `enabled` and `lastActivityAt` fields (or your configured field names).

## Verify

```bash
php bin/console debug:container AccountStatusUserChecker
php bin/console debug:container UserPresenceResolver
```

## Demo

Three FrankenPHP demos are available:

| Demo | Symfony | Default URL |
| ---- | ------- | ----------- |
| `demo/symfony7` | 7.4 | http://localhost:8022 |
| `demo/symfony8` | 8.1 | http://localhost:8023 |
| `demo/symfony8-php85` | 8.1 (PHP 8.5) | http://localhost:8024 |

See [Demo with FrankenPHP](DEMO-FRANKENPHP.md), [demo/README.md](../demo/README.md), and per-demo README files.

## Symfony Flex recipe

When using Symfony Flex, the recipe at `.symfony/recipe/nowo-tech/user-kit-bundle/1.0/` copies:

- `config/packages/nowo_user_kit.yaml` — default bundle configuration

See `post-install.txt` in the recipe for next steps after `composer require`.

## Next steps

- [Configuration](CONFIGURATION.md)
- [Usage](USAGE.md)
- [AuthKit coexistence](../README.md#authkit-coexistence)
