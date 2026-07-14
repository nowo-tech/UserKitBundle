DEMOS := symfony8

.PHONY: help up down test test-coverage release-check up-symfony8 down-symfony8 test-symfony8 test-coverage-symfony8

help:
	@echo "User Kit Bundle — Symfony 8 demo"
	@echo ""
	@echo "  up / up-symfony8     Start demo (http://localhost:8023)"
	@echo "  down / down-symfony8 Stop demo"
	@echo "  test / test-symfony8 Run demo tests"
	@echo "  release-check        Demo smoke tests"

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

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
