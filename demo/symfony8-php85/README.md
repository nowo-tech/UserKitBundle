# User Kit Bundle — Symfony 8 demo (PHP 8.5)

FrankenPHP demo for account status (`UserChecker`), throttled `lastActivityAt`, and online detection (`UserPresenceResolver` + Twig `user_is_online`).

## Quick start

```bash
cd demo/symfony8-php85
make up
```

Open **http://localhost:8024** (default). Login with `demo@user-kit.test` / `demo`.

## Stack

- PHP 8.5 · FrankenPHP · Symfony 8.1
- SQLite (`var/data/demo.db`)
- Path repository: `../../` mounted at `/var/user-kit-bundle`
