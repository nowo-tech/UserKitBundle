# User Kit Bundle — Symfony 8 demo

FrankenPHP demo for **named configuration profiles**: account status (`UserChecker`), throttled `lastActivityAt`, and online detection (`UserPresenceResolver` + Twig `user_is_online`).

## Quick start

```bash
cd demo/symfony8
make up
```

Open **http://localhost:8023** (default). Login with `demo@user-kit.test` / `demo`.

## Configuration

The demo uses the **profiles** layout (`nowo_user_kit.profiles.app_user`) in `config/packages/nowo_user_kit.yaml`:

- **`default_profile`:** `app_user`
- **`user_class`:** `App\Entity\User`
- **`account_status`:** enabled checker on field `enabled`
- **`last_activity`:** enabled, threshold 300s, throttle 30s

A commented `staff` profile in the same file shows how to add a second user entity with different field names and thresholds.

## Try it

1. Login and reload `/` — `lastActivityAt` updates (throttled); the page shows the resolved profile name.
2. Set `enabled = 0` for the demo user in SQLite — login is blocked.
3. Compare **UserPresenceResolver** vs Twig `user_is_online()` (both resolve the profile from the entity class).

## Commands

| Command | Description |
| ------- | ----------- |
| `make up` | Build, install deps, sync bundle, run schema update |
| `make down` | Stop containers |
| `make test` | Run demo PHPUnit smoke tests |
| `make link-bundle` | Symlink `/var/user-kit-bundle` and refresh schema |

## Stack

- PHP 8.2+ · FrankenPHP · Symfony 8.1
- SQLite (`var/data/demo.db`)
- Path repository: `../../` mounted at `/var/user-kit-bundle`
- **`FRANKENPHP_MODE`:** `worker` (default) or `classic` — see `.env.example` and [docs/DEMO-FRANKENPHP.md](../../docs/DEMO-FRANKENPHP.md)
