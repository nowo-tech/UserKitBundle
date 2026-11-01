.PHONY: help up down build install shell logs test test-coverage cache-clear link-bundle update-bundle ensure-up restart release-check test-e2e demo-screenshots

help:
	@echo "Symfony 8 Demo - User Kit Bundle"
	@echo ""
	@echo "  make up               Start the demo (FrankenPHP on port \$${PORT:-8023})"
	@echo "  make down             Stop the demo"
	@echo "  make test             Demo PHPUnit tests"
	@echo "  make test-e2e         Playwright happy path (official Playwright image; demo must be up)"
	@echo "  make demo-screenshots Refresh docs/images/demo/*.webp (demo must be up)"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	$(DOCKER_COMPOSE) up -d --build
	@echo "Waiting for container to be ready..."
	@sleep 5
	@# The entrypoint runs the first composer install and the schema, then starts FrankenPHP: wait for the server
	@# instead of racing that install on the shared vendor/.
	@PORT=$${PORT:-$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r')}; \
	[ -z "$$PORT" ] && PORT=8023; \
	i=0; while [ $$i -lt 300 ] && [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$$PORT 2>/dev/null)" = "000" ]; do i=$$((i+1)); sleep 3; done
	@echo "Installing dependencies..."
	@$(DOCKER_COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@$(MAKE) link-bundle
	@PORT=$${PORT:-$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r')}; \
	[ -z "$$PORT" ] && PORT=8023; \
	code=000; i=0; \
	while [ $$i -lt 90 ]; do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$PORT/ 2>/dev/null || echo "000"); \
		if [ "$$code" = "200" ]; then break; fi; \
		i=$$((i+1)); sleep 2; \
	done; \
	if [ "$$code" != "200" ]; then echo "Demo not ready (HTTP $$code)"; $(DOCKER_COMPOSE) logs --tail=80 php || true; exit 1; fi; \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(DOCKER_COMPOSE) down

restart: ensure-up
	$(DOCKER_COMPOSE) restart

build:
	$(DOCKER_COMPOSE) build --no-cache

ensure-up:
	@if ! $(DOCKER_COMPOSE) exec -T php true 2>/dev/null; then $(DOCKER_COMPOSE) up -d; sleep 3; fi

install: ensure-up
	$(DOCKER_COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	$(DOCKER_COMPOSE) exec php sh

logs:
	$(DOCKER_COMPOSE) logs -f

test: ensure-up link-bundle
	$(DOCKER_COMPOSE) exec -e APP_ENV=test php sh -c "mkdir -p var/data && php bin/console doctrine:schema:update --force"
	$(DOCKER_COMPOSE) exec -e APP_ENV=test php composer test

test-coverage: ensure-up link-bundle
	$(DOCKER_COMPOSE) exec -e APP_ENV=test php composer test-coverage

cache-clear: ensure-up
	$(DOCKER_COMPOSE) exec php php bin/console cache:clear

link-bundle: ensure-up
	$(DOCKER_COMPOSE) exec php sh -c "mkdir -p var/data && rm -rf vendor/nowo-tech/user-kit-bundle && ln -sf /var/user-kit-bundle vendor/nowo-tech/user-kit-bundle && php bin/console doctrine:schema:update --force --complete && php bin/console cache:clear"

update-bundle: ensure-up
	$(DOCKER_COMPOSE) exec php composer update nowo-tech/user-kit-bundle --no-interaction
	@$(MAKE) link-bundle

release-check: ensure-up update-bundle test test-coverage

# REQ-DEMO-013: Playwright e2e + README screenshots, run in the official image pinned to @playwright/test (e2e/package.json).
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.64.1-jammy
# Extra `docker run` flags, e.g. PLAYWRIGHT_DOCKER_ARGS="--cpus 2" on a shared machine.
PLAYWRIGHT_DOCKER_ARGS ?=

test-e2e: ensure-up
	@mkdir -p e2e/node_modules
	@PORT=$${PORT:-$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r')}; PORT=$${PORT:-8023}; \
	docker run --rm --network host $(PLAYWRIGHT_DOCKER_ARGS) \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=chromium'

demo-screenshots: ensure-up
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$${PORT:-$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r')}; PORT=$${PORT:-8023}; \
	docker run --rm --network host $(PLAYWRIGHT_DOCKER_ARGS) \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=screenshots && node scripts/to-webp.mjs /screenshots'
	@echo "Screenshots written under docs/images/demo/ (WebP)"

# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
DOCKER_COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
SERVICE_PHP := php
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
