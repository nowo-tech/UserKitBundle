# User Kit Bundle — Symfony 8 demo

FrankenPHP demo for account status (`UserChecker`), throttled `lastActivityAt`, and online detection (`UserPresenceResolver` + Twig `user_is_online`).

## Quick start

```bash
cd demo/symfony8
make up
```

Open **http://localhost:8023** (default). Login with `demo@user-kit.test` / `demo`.

## Try it

1. Login and reload `/` — `lastActivityAt` updates (throttled).
2. Set `enabled = 0` for the demo user in SQLite — login is blocked.
3. Observe online status via resolver and Twig helper.

## Commands

| Command | Description |
| ------- | ----------- |
| `make up` | Build, install deps, sync bundle, run schema update |
| `make down` | Stop containers |
| `make test` | Run demo PHPUnit smoke tests |
| `make link-bundle` | Symlink `/var/user-kit-bundle` and refresh schema |

## Stack

- PHP 8.4 · FrankenPHP · Symfony 8.1
- SQLite (`var/data/demo.db`)
- Path repository: `../../` mounted at `/var/user-kit-bundle`
