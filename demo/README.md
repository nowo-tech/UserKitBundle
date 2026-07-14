# User Kit Bundle — Demos

Three FrankenPHP demos exercise the bundle against different Symfony / PHP stacks.

| Demo | Symfony | PHP | Default port |
| ---- | ------- | --- | ------------ |
| [symfony7](symfony7/) | 7.4 | 8.2 | 8022 |
| [symfony8](symfony8/) | 8.1 | 8.4 | 8023 |
| [symfony8-php85](symfony8-php85/) | 8.1 | 8.5 | 8024 |

## Quick start

```bash
make -C demo up-symfony8
# or
cd demo/symfony8 && make up
```

Login: `demo@user-kit.test` / `demo`

## What to try

1. Sign in and reload `/` — throttled `lastActivityAt` updates.
2. Set `enabled = 0` for the demo user in SQLite — login is blocked by `AccountStatusUserChecker`.
3. Compare online status via `UserPresenceResolver` and Twig `user_is_online()`.

## Commands (aggregator)

```bash
cd demo
make up-symfony7
make up-symfony8
make up-symfony8-php85
make test-symfony8
make release-check
```

Each demo has its own `Makefile` with `up`, `down`, `test`, and `link-bundle` (symlink `/var/user-kit-bundle`).

## FrankenPHP

Demos use FrankenPHP in Docker (development Caddyfile without worker; production Caddyfile with worker mode). See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).

## Port configuration

Set `PORT` in each demo's `.env` (from `.env.example`) when running multiple demos concurrently.
