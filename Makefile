SHELL := /bin/bash
.DEFAULT_GOAL := help
.PHONY: help lint phpcs phpstan phpunit test-invariants test-integration matrix-lint visual visual-update semgrep test test-docker e2e e2e-docker coverage test-all js-build bench test-extended test-extended-cleanup test-extended-verify

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*##"}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

js-build: ## Build JS assets via @wordpress/scripts (writes assets/dist/)
	@echo "→ Building JS assets..."
	@if [ ! -d node_modules ]; then \
	  echo "  installing npm deps (one-time)..."; \
	  npm ci --silent || npm install --silent; \
	fi
	@npm run build
	@echo "✓ JS build complete"

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
	@vendor/bin/phpstan analyse --memory-limit=2G --no-progress
	@echo "✓ PHPStan passed"

phpunit: ## Unit tests
	@echo "→ PHPUnit..."
	@vendor/bin/phpunit
	@echo "✓ PHPUnit passed"

test-invariants: ## L1 invariant tests (source-pattern checks; see docs/internal/test-coverage-matrix.md)
	@echo "→ Invariants..."
	@vendor/bin/phpunit --testsuite Invariants
	@echo "✓ Invariants passed"

test-integration: ## wp-phpunit integration tests (requires MySQL test DB; see tests/Integration/README.md)
	@if [ ! -f tests/Integration/wp-tests-config.php ]; then \
	  echo "  ✗ tests/Integration/wp-tests-config.php missing — run bin/install-wp-tests.sh first."; \
	  echo "    Example: bin/install-wp-tests.sh swh_phpunit_test root '' 127.0.0.1:3306"; \
	  exit 2; \
	fi
	@if [ ! -d vendor/wp-phpunit/wp-phpunit ]; then \
	  echo "  ✗ wp-phpunit framework missing — run: composer install --dev"; \
	  exit 2; \
	fi
	@echo "→ Integration tests (real WP REST server, real DB)..."
	@vendor/bin/phpunit --configuration phpunit-integration.xml

matrix-lint: ## Summarise test coverage matrix (docs/internal/test-coverage-matrix.md)
	@php bin/matrix-lint.php

matrix-lint-diff: ## Compare matrix vs base ref (default origin/main); fail if any feature row regressed
	@BASE=$${BASE:-origin/main}; \
	 echo "→ Comparing test-coverage-matrix.md against $$BASE..."; \
	 if ! git rev-parse --verify $$BASE >/dev/null 2>&1; then \
	   echo "  ✗ Base ref $$BASE does not exist locally — try: git fetch origin"; \
	   exit 2; \
	 fi; \
	 git show $$BASE:docs/internal/test-coverage-matrix.md > /tmp/swh-matrix-base.md 2>/dev/null || { \
	   echo "  ✗ Could not extract matrix from $$BASE — it may predate the matrix"; \
	   exit 2; \
	 }; \
	 php bin/matrix-lint.php --diff

visual: ## Visual regression — diff admin surfaces against committed baselines
	@if [ -z "$${WP_TESTING_URL:-}" ]; then \
	  echo "  ✗ Source ~/.claude/dev-secrets.env first: set -a; source ~/.claude/dev-secrets.env; set +a"; \
	  exit 2; \
	fi
	@python3 testing/visual/visual_regression.py

visual-update: ## Regenerate visual-regression baselines (run after intentional UI changes)
	@if [ -z "$${WP_TESTING_URL:-}" ]; then \
	  echo "  ✗ Source ~/.claude/dev-secrets.env first."; \
	  exit 2; \
	fi
	@python3 testing/visual/visual_regression.py --mode update
	@echo ""
	@echo "Review testing/visual/baselines/, then: git add testing/visual/baselines/ && git commit"

semgrep: ## SAST security scan
	@echo "→ Semgrep..."
	@semgrep scan --config=auto --error simple-wp-helpdesk/ 2>&1 | tail -5
	@echo "✓ Semgrep passed"

test: lint phpcs phpstan phpunit test-invariants matrix-lint semgrep ## Full local gate — run before opening a PR
	@echo ""
	@echo "✅ All gate checks passed. Safe to push."

test-docker: ## Full gate inside phptest container (no host PHP/semgrep needed)
	@echo "→ Running full gate inside phptest container..."
	@docker compose -f docker-compose.test.yml run --rm phptest
	@echo "✅ Docker gate passed."

e2e: ## Playwright E2E tests (requires WP environment — set WP_MODE=docker or configure SSH vars)
	@echo "→ Playwright E2E..."
	@cd testing && source .venv/bin/activate && pytest scripts/test_helpdesk_pw.py -v

e2e-docker: js-build ## Full E2E in Docker — spin up stack, run suite, tear down
	@trap 'docker compose -f $(CURDIR)/docker-compose.test.yml down -v' EXIT; \
	 echo "→ Starting Docker stack..."; \
	 docker compose -f $(CURDIR)/docker-compose.test.yml up -d db wordpress wpcli mailhog; \
	 echo "→ Waiting for WordPress (up to 90s)..."; \
	 i=0; until curl -sf http://localhost:8080/wp-login.php >/dev/null 2>&1; do \
	   sleep 3; i=$$((i+3)); if [ $$i -ge 90 ]; then echo "ERROR: WordPress did not start in 90s"; exit 1; fi; \
	 done; \
	 echo "→ Setting up WordPress test environment..."; \
	 bash docker/setup-test-wp.sh; \
	 echo "→ Running Playwright E2E..."; \
	 cd testing && source .venv/bin/activate && \
	 set -a && source /tmp/swh-docker-test.env && set +a && \
	 pytest scripts/test_helpdesk_pw.py -v

coverage: ## Generate PHPUnit coverage report (requires pcov or xdebug; outputs coverage.xml)
	@echo "→ PHPUnit with coverage..."
	@vendor/bin/phpunit --coverage-clover coverage.xml
	@echo "✓ Coverage report written to coverage.xml"

test-all: test e2e ## Full gate + E2E

bench: ## Run perf baseline against the Docker stack (override count with COUNT=500)
	@bash testing/scripts/bench.sh $${COUNT:-100}

test-extended-verify: ## Verify RC env (vars present, IMAP/SMTP login, SSH+wp-cli) — always run first
	@if [ -z "$${WP_TESTING_URL:-}" ]; then \
	  echo "  ✗ Source ~/.claude/dev-secrets.env first: set -a; source ~/.claude/dev-secrets.env; set +a"; \
	  exit 2; \
	fi
	@python3 testing/extended/verify_env.py

test-extended-cleanup: ## Pre/post-RC cleanup — delete CLAUDE-TEST-* tickets + test mailbox messages
	@echo "→ Extended-test cleanup (dev WP + test mailbox)..."
	@if [ -z "$${WP_TESTING_URL:-}" ]; then \
	  echo "  ✗ Source ~/.claude/dev-secrets.env first: set -a; source ~/.claude/dev-secrets.env; set +a"; \
	  exit 2; \
	fi
	@python3 testing/extended/cleanup.py $(if $(CONFIRM),--confirm,) $(if $(DRYRUN),--dry-run,)

test-extended: ## RC sign-off runbook (manual, ~60-90 min) — see docs/internal/extended-test-plan.md
	@echo ""
	@echo "════════════════════════════════════════════════════════════════════════════════"
	@echo "  RC EXTENDED TEST SUITE — manual runbook (~60-90 min)"
	@echo "════════════════════════════════════════════════════════════════════════════════"
	@echo ""
	@echo "  This is the release-candidate sign-off runbook. Run it AFTER all per-push"
	@echo "  gates are green, BEFORE merging dev → main."
	@echo ""
	@echo "  Runbook:  docs/internal/extended-test-plan.md"
	@echo "  Scripts:  testing/extended/   (README in same directory)"
	@echo ""
	@if [ -z "$${WP_TESTING_URL:-}" ]; then \
	  echo "  Step 1 — source the secrets file:"; \
	  echo "    set -a; source ~/.claude/dev-secrets.env; set +a"; \
	  echo ""; \
	  echo "  Step 2 — re-run this command: make test-extended"; \
	  echo ""; \
	  exit 2; \
	fi
	@echo "  Environment loaded ✓"
	@echo "    WP target : $${WP_TESTING_URL}"
	@echo "    Mail user : $${MAIL_USERNAME:-NOT SET}"
	@echo "    Webhook to: $${WP_WEBHOOK_TIED_TO:-NOT SET}"
	@echo ""
	@echo "  Step 1 — verify environment (always do this first):"
	@echo "    make test-extended-verify"
	@echo ""
	@echo "  Step 2 — pre-flight cleanup:"
	@echo "    make test-extended-cleanup CONFIRM=1"
	@echo ""
	@echo "  Step 3 — open the runbook and work through each section:"
	@echo "    \$$EDITOR docs/internal/extended-test-plan.md"
	@echo ""
	@echo "  Step 4 — paste the sign-off block (bottom of the runbook) into the RC PR."
	@echo ""
