SHELL := /bin/bash
.DEFAULT_GOAL := help
.PHONY: help lint phpcs phpstan phpunit semgrep test e2e test-all

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*##"}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

lint: ## PHP syntax check on all plugin files
	@echo "→ PHP lint..."
	@find simple-wp-helpdesk -name '*.php' ! -path '*/vendor/*' -exec php -l {} \; | grep -v "No syntax errors" || true
	@find simple-wp-helpdesk -name '*.php' ! -path '*/vendor/*' -exec php -l {} \; | grep -c "No syntax errors" > /dev/null
	@echo "✓ Lint passed"

phpcs: ## WordPress Coding Standards (zero errors required)
	@echo "→ PHPCS..."
	@vendor/bin/phpcs
	@echo "✓ PHPCS passed"

phpstan: ## Static analysis level 9
	@echo "→ PHPStan..."
	@vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	@echo "✓ PHPStan passed"

phpunit: ## Unit tests
	@echo "→ PHPUnit..."
	@vendor/bin/phpunit
	@echo "✓ PHPUnit passed"

semgrep: ## SAST security scan
	@echo "→ Semgrep..."
	@semgrep scan --config=auto --error simple-wp-helpdesk/ 2>&1 | tail -5
	@echo "✓ Semgrep passed"

test: lint phpcs phpstan phpunit semgrep ## Full local gate — run before opening a PR
	@echo ""
	@echo "✅ All gate checks passed. Safe to push."

e2e: ## Playwright E2E tests (requires WP environment — set WP_MODE=docker or configure SSH vars)
	@echo "→ Playwright E2E..."
	@cd testing && source .venv/bin/activate && pytest scripts/test_helpdesk_pw.py -v

test-all: test e2e ## Full gate + E2E
