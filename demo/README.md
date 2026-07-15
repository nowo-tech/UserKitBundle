# User Kit Bundle — Symfony 8 demo

FrankenPHP demo for **named configuration profiles**: account status (`UserChecker`), throttled `lastActivityAt`, and online detection.

## Quick start

```bash
make -C demo up
# or
cd demo/symfony8 && make up
```

Open **http://localhost:8023**. Login: `demo@user-kit.test` / `demo`

## What to try

1. Sign in and reload `/` — throttled `lastActivityAt` updates; the page shows the resolved profile (`app_user`).
2. Set `enabled = 0` for the demo user in SQLite — login is blocked by `AccountStatusUserChecker`.
3. Compare online status via `UserPresenceResolver` and Twig `user_is_online()`.

Configuration lives in `demo/symfony8/config/packages/nowo_user_kit.yaml` (`nowo_user_kit.profiles.app_user`). See [Configuration](../docs/CONFIGURATION.md#profiles).

## Commands

| Command | Description |
| ------- | ----------- |
| `make up` | Build, install deps, sync bundle |
| `make down` | Stop containers |
| `make test` | Run demo PHPUnit smoke tests |
| `make link-bundle` | Symlink `/var/user-kit-bundle` (in `demo/symfony8`) |

See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for FrankenPHP dev vs production (worker mode).

## Stack

- PHP 8.2+ · FrankenPHP · Symfony 8.1
- SQLite (`var/data/demo.db`)
- Path repository: bundle root mounted at `/var/user-kit-bundle`
