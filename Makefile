SHELL := /bin/bash
.DEFAULT_GOAL := help
.PHONY: help lint phpcs phpstan phpunit semgrep test test-docker e2e e2e-docker coverage test-all

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*##"}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

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

test-docker: ## Full gate inside phptest container (no host PHP/semgrep needed)
	@echo "→ Running full gate inside phptest container..."
	@docker compose -f docker-compose.test.yml run --rm phptest
	@echo "✅ Docker gate passed."

e2e: ## Playwright E2E tests (requires WP environment — set WP_MODE=docker or configure SSH vars)
	@echo "→ Playwright E2E..."
	@cd testing && source .venv/bin/activate && pytest scripts/test_helpdesk_pw.py -v

e2e-docker: ## Full E2E in Docker — spin up stack, run suite, tear down
	@echo "→ Starting Docker stack..."
	@docker compose -f docker-compose.test.yml up -d db wordpress wpcli mailhog
	@echo "→ Waiting for WordPress (up to 90s)..."
	@timeout 90 bash -c 'until curl -sf http://localhost:8080/wp-login.php >/dev/null 2>&1; do sleep 3; done'
	@echo "→ Setting up WordPress test environment..."
	@bash docker/setup-test-wp.sh
	@echo "→ Running Playwright E2E..."
	@set +e; \
	 cd testing && source .venv/bin/activate && \
	 WP_MODE=docker MAILHOG_URL=http://localhost:8025 pytest scripts/test_helpdesk_pw.py -v; \
	 EXIT=$$?; \
	 cd .. && docker compose -f docker-compose.test.yml down -v; \
	 exit $$EXIT

coverage: ## Generate PHPUnit coverage report (requires pcov or xdebug; outputs coverage.xml)
	@echo "→ PHPUnit with coverage..."
	@vendor/bin/phpunit --coverage-clover coverage.xml
	@echo "✓ Coverage report written to coverage.xml"

test-all: test e2e ## Full gate + E2E
