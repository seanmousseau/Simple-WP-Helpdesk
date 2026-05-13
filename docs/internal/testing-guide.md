# Testing Guide

**Audience:** plugin contributors.

The pre-push hook calls `make test-docker`. A push without a green gate is rejected.

## Test layers

| Layer | Location | What it covers | Runtime |
|---|---|---|---|
| PHP lint | `make lint` | Syntax errors only. Catches typos before any other tool runs. | sub-second |
| PHPCS (WPCS) | `make phpcs` | WordPress Coding Standards. Zero errors and zero warnings required. | seconds |
| PHPStan | `make phpstan` | Level 9 static analysis. WP stubs via `szepeviktor/phpstan-wordpress`. | tens of seconds |
| PHPUnit | `make phpunit` (`tests/Unit/`) | Pure-PHP logic in isolation. WordPress functions stubbed via `10up/wp_mock`. | seconds |
| Semgrep | `make semgrep` | SAST rules from `--config=auto` and the bundled MCP server. | tens of seconds |
| Coverage | `make coverage` → `coverage.xml` | PHPUnit Clover coverage. Requires pcov or xdebug. | tens of seconds |
| Playwright E2E | `make e2e` / `make e2e-docker` | 64 sections in `testing/scripts/test_helpdesk_pw.py` against a real WP stack. | minutes |

`make test` chains lint → phpcs → phpstan → phpunit → semgrep on the host. `make test-docker` runs the same chain inside the `phptest` container — preferred because no host PHP / Semgrep install is needed.

## When to add what

| Change | Required test |
|---|---|
| New user-facing feature | New numbered Playwright section. |
| Bug fix (regression) | New or extended section that covers the bug scenario. |
| Admin-only UI change | New or extended admin section. |
| Pure logic helper (no WP API surface) | PHPUnit case under `tests/Unit/`. |
| Internal refactor with no UX change | None required — but existing sections must still pass. |
| Security fix | Extend or add a `security`-marked Playwright section. |

If a change touches both logic and UX, both layers get tests.

New Playwright sections continue from the next available number (currently 65). Add the section to the taxonomy table below.

## How to run

### Local gate

```bash
make test-docker         # Required before push. Full PHP gate in Docker.
make e2e-docker          # Self-contained E2E: spin up + setup + test + teardown.
make test-all            # test + e2e (host PHP path; requires WP_MODE=docker or SSH env).
```

### Individual tools

```bash
make lint                # PHP syntax check on all plugin files
make phpcs               # WordPress Coding Standards
vendor/bin/phpcbf && make phpcs   # auto-fix then re-check
make phpstan             # Level 9
make phpunit             # Unit tests
make semgrep             # SAST
make coverage            # → coverage.xml
make bench               # Performance baseline against Docker stack (COUNT=N to override)
```

### Self-contained Docker E2E

```bash
make e2e-docker
```

Or manually:

```bash
docker compose -f docker-compose.test.yml up -d db wordpress wpcli mailhog
bash docker/setup-test-wp.sh
WP_MODE=docker MAILHOG_URL=http://localhost:8025 make e2e
docker compose -f docker-compose.test.yml down -v
```

**MailHog:** when `MAILHOG_URL` is set and `WP_MODE=docker`, `expect_email()` asserts delivery via the MailHog API (`http://localhost:8025`). In SSH mode it falls back to the `EMAIL_CHECKS` summary printed at the end of the run for manual verification.

### CodeRabbit

After opening a PR, run `/review`. Address every actionable finding before merge.

## Playwright test taxonomy

64 sections in `testing/scripts/test_helpdesk_pw.py`. Marks: `smoke`, `security`, `slow`.

| # | Name | Marks |
|---|------|-------|
| 01 | admin_auth | smoke |
| 02 | plugin_verification | smoke |
| 03 | ticket_submission | smoke |
| 04 | admin_locate_ticket | smoke |
| 05 | portal_url | |
| 06 | admin_update_ticket | |
| 07 | technician_workflow | |
| 08 | client_portal | |
| 09 | admin_verify_reply | |
| 10 | portal_close_reopen | |
| 11 | access_control | |
| 12 | ticket_list_filters | |
| 13 | ticket_lookup | |
| 14 | accessibility | |
| 15 | plugin_icons | slow |
| 16 | honeypot_spam | security |
| 17 | form_validation | security |
| 18 | settings_persistence | |
| 19 | canned_responses | |
| 20 | bulk_status_change | |
| 21 | tech2_workflow | |
| 22 | admin_search_and_filters | |
| 23 | file_attachments | slow |
| 24 | portal_token_security | security |
| 25 | xss_escaping | security |
| 26 | subscriber_access_control | security |
| 27 | rate_limiting | security |
| 28 | cleanup | |
| 29 | humanized_timestamps | |
| 30 | resolved_cta_layout | |
| 33 | csat_prompt | |
| 34 | my_tickets_dashboard | |
| 35 | portal_guest_lookup | |
| 36 | shortcode_attrs | |
| 37 | admin_list_filtering | |
| 38 | admin_list_sorting | |
| 39 | ticket_templates | |
| 40 | first_response_time | |
| 41 | cc_watchers | |
| 42 | categories_taxonomy | |
| 43 | ticket_merge | |
| 44 | sla_breach_detection | |
| 45 | assignment_rules | |
| 46 | reporting_dashboard | |
| 47 | inbound_email_webhook | |
| 48 | timestamp_locale | |
| 49 | dedicated_reply_buttons | |
| 50 | unread_badge | |
| 51 | unread_row_highlight | |
| 52 | email_test_button | |
| 53 | ux_a11y | |
| 54 | responsive | |
| 55 | email_branding | |
| 56 | dark_mode | |
| 57 | toast_notifications | |
| 58 | reports_loading_states | |
| 59 | admin_dark_mode | |
| 60 | email_color_scheme | |
| 61 | skip_to_content | |
| 62 | focus_visible_rings | |
| 63 | csat_focus_management | |
| 64 | aria_live_announcements | |

(Section numbers are not consecutive; some are reserved for now-merged work.)

## Test architecture

- **Session-scoped browser.** A single Chromium instance is shared across all tests so login cookies persist. Defined in `testing/scripts/conftest.py`.
- **`check()`.** Soft-fail helper. Failures accumulate and surface after each test via an autouse fixture in `conftest.py`. Prefer `check()` over `assert` for non-blocking expectations.
- **`skip()`.** Records a skip without aborting the section.
- **`as_user(page, user, pass)`.** Context manager — logout, then login, then yield, then logout again.
- **`wpcli(cmd)`.** Runs WP-CLI via SSH+docker exec (default), or `docker compose exec` when `WP_MODE=docker`. Returns the stdout string. **Exits non-zero for absent data** — `wp option get`, `wp post meta get`, `wp comment meta get` all exit 1 when the key is absent. This is a normal result; `wpcli()` returns an empty string and callers handle it.
- **`_clear_rate_limits()`.** Deletes `swh_rl_*` rows from `wp_options` and flushes the object cache. The rate limiter uses `get_option()` which reads cache first, so both must be cleared.
- **`_navigate_settings(page)`.** Navigates to settings and removes `.wp-pointer` elements. Security Ninja and other admin pointers intercept Playwright clicks otherwise.
- **`state` dict.** Carries `ticket_id`, `ticket2_id`, `portal_url`, etc. across sections. Never hardcode an ID.
- **`EMAIL_CHECKS` list.** Printed at end of run for manual verification in SSH mode. In Docker mode MailHog asserts automatically.

## Key gotchas

- **Meta key is `_ticket_status`** (underscore prefix). `wp post meta get` returns empty unless you include the underscore. Use `wp eval 'echo get_post_meta(ID, "_ticket_status", true);'` if the CLI behaves oddly.
- **`required` attributes** must be stripped before JS form submit in validation tests, or HTML5 browser validation intercepts the submit and the POST never reaches the server.
- **WordPress word-level search.** `LIKE '%Ticket%'` matches "Ticket" and "Ticket2" both. Avoid negative assertions based on title substrings.
- **WP admin pointers.** Security Ninja and similar plugins inject `.wp-pointer` overlays that intercept Playwright clicks. Always call `_navigate_settings()` on settings page visits.
- **Bulk action key format.** `sanitize_title('In Progress')` → `in-progress` → action value `swh_status_in-progress`.
- **`expect_navigation()`.** Wrap JS-triggered form submits in `with page.expect_navigation():` to avoid a race between `evaluate` and `page.content()`.
- **Canned response persistence check.** Read `el.value` via `page.evaluate()`. Input values are not in `inner_text()`.
- **File upload form POST + caching plugins.** A page-caching plugin can fire an immediate GET after the file POST, overwriting the success HTML in the DOM. Use `expect_navigation()` + `wait_for_load_state("load")` to let it settle, then verify success via WP-CLI meta rather than `page.content()`.
- **Authorization header stripped in Docker.** Apache strips the `Authorization` header before PHP receives it, regardless of `.htaccess` rules. For section 47 (inbound webhook), bypass HTTP entirely: construct a `WP_REST_Request` and call `swh_handle_inbound_email()` directly via `wp eval`.

## Environment variables

`testing/.env` is gitignored. Copy `testing/.env.example` and fill values. Required keys:

| Variable | Purpose |
|---|---|
| `WP_URL`, `WP_LOGIN_URL`, `WP_ADMIN_URL`, `WP_SUBMIT_PAGE`, `WP_PORTAL_PAGE` | Test target URLs. |
| `WP_ADMIN_USER`, `WP_ADMIN_PASS` | Admin login. |
| `WP_TECH1_EMAIL`, `WP_TECH1_USER`, `WP_TECH1_PASS` | Technician 1. |
| `WP_TECH2_USER`, `WP_TECH2_PASS` | Technician 2. |
| `CLIENT1_NAME`, `CLIENT1_EMAIL` | Client 1 fixtures. |
| `CLIENT2_NAME`, `CLIENT2_EMAIL` | Client 2 fixtures. |
| `WP_MODE` | `ssh` (default) or `docker`. |
| `SSH_HOST`, `WP_CONTAINER`, `WP_PATH` | Required when `WP_MODE=ssh`. |
| `MAILHOG_URL` | MailHog API base, e.g. `http://localhost:8025`. Enables automated email assertions when `WP_MODE=docker`. |

## Anti-patterns

- Skipping the pre-push gate with `--no-verify`.
- Asserting `inner_text()` for form input values (use `el.value` via `evaluate()`).
- Adding network-dependent tests without a fallback path.
- Hardcoding ticket IDs across sections — use the shared `state` dict.
- Raising on non-zero `wpcli()` return — it is a normal result for absent meta.

## CI configuration

`.github/workflows/`:

| File | Purpose |
|---|---|
| `php-tests.yml` | Lint, PHPCS, PHPStan, PHPUnit, Semgrep gate on push and PR. |
| `e2e.yml` | Playwright E2E against the Docker stack on PR. |
| `semgrep.yml` | Standalone Semgrep scan. |
| `coverage.yml` | PHPUnit coverage report. |
| `release.yml` | Builds `simple-wp-helpdesk.zip` and creates a GitHub Release on `v*.*.*` tag push. |
| `claude.yml` | Claude Code automation hooks. |

## Update protocol

Update this doc when:
- A new test layer is added (e.g. visual regression, performance gate).
- A new Playwright section lands — bump the taxonomy table.
- A new helper appears in `conftest.py`.
- A new gotcha bites someone — add it to "Key gotchas" with reproduction.
- An environment variable is added or renamed.
- The CI workflow set changes.
