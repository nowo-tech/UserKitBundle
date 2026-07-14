# Installation

## Requirements

- PHP 8.2 or higher (< 8.6)
- Symfony 7.x or 8.x
- Doctrine ORM (user entity persistence)
- `symfony/security-bundle`

Optional:

- `symfony/twig-bundle` — for the `user_is_online` Twig helper
- [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) — login/register UI (complementary, not required)

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

See [Demo with FrankenPHP](DEMO-FRANKENPHP.md) and `demo/symfony8/README.md`.

## Symfony Flex recipe

When using Symfony Flex, the recipe at `.symfony/recipe/nowo-tech/user-kit-bundle/1.0/` copies:

- `config/packages/nowo_user_kit.yaml` — default bundle configuration

See `post-install.txt` in the recipe for next steps after `composer require`.

## Next steps

- [Configuration](CONFIGURATION.md)
- [Usage](USAGE.md)
- [AuthKit coexistence](../README.md#authkit-coexistence)
