.PHONY: help up-symfony7 down-symfony7 test-symfony7 \
	up-symfony8 down-symfony8 test-symfony8 test-coverage-symfony8 \
	up-symfony8-php85 down-symfony8-php85 test-symfony8-php85 \
	up down test test-coverage release-check

help:
	@echo "User Kit Bundle — Demos"
	@echo ""
	@echo "Symfony 7.4 (port 8022): up-symfony7 down-symfony7 test-symfony7"
	@echo "Symfony 8.1   (port 8023): up-symfony8 down-symfony8 test-symfony8"
	@echo "Symfony 8.1 + PHP 8.5 (8024): up-symfony8-php85 down-symfony8-php85 test-symfony8-php85"
	@echo ""
	@echo "  release-check  Run demo smoke tests (symfony8)"

up-symfony7:
	@$(MAKE) -C symfony7 up

down-symfony7:
	@$(MAKE) -C symfony7 down

test-symfony7:
	@$(MAKE) -C symfony7 test

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

up-symfony8-php85:
	@$(MAKE) -C symfony8-php85 up

down-symfony8-php85:
	@$(MAKE) -C symfony8-php85 down

test-symfony8-php85:
	@$(MAKE) -C symfony8-php85 test

up: up-symfony8

down: down-symfony8

test: test-symfony8

test-coverage: test-coverage-symfony8

release-check:
	@$(MAKE) -C symfony8 release-check

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
