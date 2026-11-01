DEMOS := symfony8

.PHONY: help up down test test-coverage release-check up-symfony8 down-symfony8 test-symfony8 test-coverage-symfony8 demo-smoke test-e2e demo-screenshots

help:
	@echo "User Kit Bundle — Symfony 8 demo"
	@echo ""
	@echo "  up / up-symfony8     Start demo (http://localhost:8023)"
	@echo "  down / down-symfony8 Stop demo"
	@echo "  test / test-symfony8 Run demo tests"
	@echo "  release-check        Demo smoke tests"
	@echo "  demo-smoke           REQ-TEST-011: boot the demo, expect HTTP 200 on /, stop it"
	@echo "  test-e2e             REQ-DEMO-013: Playwright e2e (demo must be up)"
	@echo "  demo-screenshots     REQ-DEMO-013: refresh docs/images/demo/*.webp (demo must be up)"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

up: up-symfony8

down: down-symfony8

test: test-symfony8

test-coverage: test-coverage-symfony8

release-check:
	@$(MAKE) -C symfony8 release-check

demo-smoke:
	@PORT=$${PORT:-$$(grep "^PORT=" symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r')}; \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8023; \
	echo "Demo smoke: symfony8 (http://127.0.0.1:$$PORT)"; \
	$(MAKE) -C symfony8 up || exit 1; \
	code=$$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" || true); \
	[ -z "$$code" ] && code=000; \
	$(MAKE) -C symfony8 down 2>/dev/null || true; \
	if [ "$$code" != "200" ]; then echo "symfony8 failed with HTTP $$code"; exit 1; fi; \
	echo "symfony8 OK (HTTP $$code)"

test-e2e:
	@$(MAKE) -C symfony8 test-e2e

demo-screenshots:
	@$(MAKE) -C symfony8 demo-screenshots

update-bundle:
	@$(MAKE) -C symfony8 update-bundle

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
