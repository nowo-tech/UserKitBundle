# User Kit Bundle — Symfony 7.4 demo

FrankenPHP demo for account status (`UserChecker`), throttled `lastActivityAt`, and online detection (`UserPresenceResolver` + Twig `user_is_online`).

## Quick start

```bash
cd demo/symfony7
make up
```

Open **http://localhost:8022** (default). Login with `demo@user-kit.test` / `demo`.

## Stack

- PHP 8.2 · FrankenPHP · Symfony 7.4
- SQLite (`var/data/demo.db`)
- Path repository: `../../` mounted at `/var/user-kit-bundle`
