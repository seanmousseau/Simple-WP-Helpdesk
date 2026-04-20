# CLAUDE.md

## Project Overview

Simple WP Helpdesk — a WordPress helpdesk/ticketing plugin. No custom DB tables; uses CPT (`helpdesk_ticket`), comments, post meta, and `wp_options`.

- **Version:** 3.3.0 | **WP:** 5.3+ | **PHP:** 7.4+ | **Repo:** seanmousseau/Simple-WP-Helpdesk

## Repository Structure

```
simple-wp-helpdesk/
├── simple-wp-helpdesk.php              # Bootstrap: constants, requires, lifecycle hooks
├── includes/
│   ├── helpers.php                     # Defaults, statuses, anti-spam, rate limiting
│   ├── class-installer.php             # Activation, deactivation, uninstall, upgrade, CPT
│   ├── class-email.php                 # Template parsing, email sending, HTML wrapping
│   ├── class-ticket.php                # File proxy, uploads, deletion, comment filters
│   └── class-cron.php                  # Auto-close, retention (tickets + attachments)
├── admin/
│   ├── class-settings.php              # Settings page render + save handler
│   ├── class-ticket-editor.php         # Meta boxes, save_post, conversation UI
│   ├── class-ticket-list.php           # Columns, sorting, filters, admin styles
│   ├── class-reporting.php             # Reporting AJAX endpoints (status, resolution, trend, first-response)
│   └── class-reporting-ui.php          # Reports submenu page render + Chart.js enqueue
├── frontend/
│   ├── class-shortcode.php             # [submit_ticket] + [helpdesk_portal] shortcodes
│   └── class-portal.php                # Client portal view
├── vendor/plugin-update-checker/       # GitHub auto-updater library
├── assets/ (CSS, JS)
├── languages/ (.pot/.po/.mo)
└── testing/                            # Test scripts and screenshots
```

Constants: `SWH_PLUGIN_DIR`, `SWH_PLUGIN_URL`, `SWH_PLUGIN_FILE` — use these instead of `__FILE__` in module files.

## Making Changes

1. Place new code in the appropriate module file. Bootstrap should stay thin.
2. All functions: `swh_` prefix. Classes: `SWH_` prefix.
3. New options → `swh_get_defaults()` in `includes/helpers.php`. They auto-register.
4. New email templates → both `_sub` and `_body` variants. Support `{if key}...{endif key}` conditionals.
5. New cron events → register in `swh_activate()`, clear in `swh_deactivate()`.
6. Schema changes → bump version, add upgrade path in `swh_run_upgrade_routine()`.
7. Admin-only code stays in `admin/` (gated by `is_admin()` in bootstrap).
8. i18n: wrap UI strings with `__()`, `esc_html__()`. Do NOT wrap admin-editable defaults.

## Security Conventions

- **Nonces:** All forms use `wp_verify_nonce()`.
- **Capability checks:** `manage_options` for admin, `edit_post` for ticket saves.
- **Sanitization:** `sanitize_text_field()`, `sanitize_email()`, `wp_kses_post()` for HTML fields.
- **Escaping:** `esc_html()`, `esc_attr()`, `esc_url()` on all output.
- **Tokens:** Always `hash_equals()`, never `==`.
- **Files:** Validate path starts with uploads dir. Serve via `swh_serve_file()` proxy, never direct URLs.
- **Rate limiting:** Use `swh_get_client_ip()`, never `$_SERVER['REMOTE_ADDR']` directly.
- **Anti-spam:** Use `swh_check_antispam( $check_captcha )`.

## Common Pitfalls

- **Two settings forms** — main form and Tools form use different nonces. Tools exclusively owns `swh_retention_*` and `swh_delete_on_uninstall`.
- **Settings save on `admin_init`** — `swh_handle_settings_save()` redirects; never put redirects in the render callback.
- **Email on ticket save** — `swh_save_ticket_data()` detects changes and sends emails. Use `swh_send_email()` for all mail, never `wp_mail()` directly.
- **Static cache in `swh_get_defaults()`** — cached per request via `static $defaults`.
- **Comment isolation** — helpdesk replies use `comment_type = 'helpdesk_reply'`. Internal notes have `_is_internal_note = 1` and must be filtered from frontend.
- **Cron offsets** — new crons need different offsets to avoid simultaneous execution.
- **`pre_get_posts` and `meta_key`** — setting it implicitly filters out posts lacking that meta.
- **Technician restriction** — only filters list query (`pre_get_posts`) and direct access (`load-post.php`). Custom `WP_Query` is NOT filtered.
- **Token expiration** — pre-v1.9.0 tickets without `_ticket_token_created` are grandfathered (no expiration).
- **Portal URL** — `swh_get_secure_ticket_link()` uses `swh_ticket_page_id` setting when set (via `get_permalink()`), falling back to `_ticket_url` post meta. Returns `false` if neither is available (token missing or no page configured and no stored meta).
- **Portal URL ordering** — always store `_ticket_token` in meta *before* calling `swh_get_secure_ticket_link()`; calling it first returns `false` and the fallback URL may point to the wrong page.
- **Rate limiting keys** — portal actions use per-action keys (`portal_close_`, `portal_reopen_`, `portal_reply_` + ticket_id). Never use a shared key across actions or close will block immediate reopen.
- **Original filenames** — upload filenames are stored in two parallel locations: `_swh_attachment_orignames` post meta on the ticket (new-ticket uploads, array keyed by file URL) and `_swh_reply_orignames` comment meta on each reply comment (reply/reopen uploads, array keyed by file URL — one meta entry per comment). Fall back to `basename($url)` if missing (pre-v2.3.0 tickets).
- **CSAT meta** — satisfaction rating stored as `_ticket_csat` (integer 1–5) on the ticket post. Not set if client skips the prompt. AJAX handler registered on `wp_ajax_nopriv_swh_submit_csat`.
- **My Tickets dashboard** — portal URL without a token shows a ticket table for logged-in WP users (matching `_ticket_email`) or the lookup form for guests. The `swh_render_lookup_form()` helper is shared between the submission shortcode and this view.
- **Shortcode attributes** — `[submit_ticket]` and `[helpdesk_portal]` accept `show_priority`, `default_priority`, `default_status`, `show_lookup`. Both shortcodes share the same `swh_submit_ticket_shortcode()` handler.
- **v3.0.0 meta keys** — `_ticket_first_response_at` (Unix timestamp of first staff reply), `_ticket_sla_status` (`warn` or `breach`), `_ticket_cc_emails` (comma-separated CC addresses), `_ticket_template` (label of selected request type at submission).
- **v3.1.0 meta keys** — `_swh_unread` (`1` when ticket has an unread client reply; cleared when admin opens ticket in editor). Paired with `swh_unread_count` transient (5-min TTL) for badge count performance. Always `delete_transient('swh_unread_count')` after setting or clearing `_swh_unread`.
- **`helpdesk_category` taxonomy** — registered in `class-installer.php`, hierarchical, `show_admin_column => true`, no REST, no rewrite. Use `wp_set_post_terms()` / `wp_get_post_terms()` to assign/read.
- **Inbound webhook** — `POST /wp-json/swh/v1/inbound-email`. Validates `Authorization: Bearer <swh_inbound_secret>`. Parses `[TKT-XXXX]` from subject, validates sender vs `_ticket_email` via `hash_equals`. Strips `>`-prefixed quoted lines.
- **Assignment rules** — stored as JSON in `swh_assignment_rules` option (array of `{category_term_id, assignee_user_id}`). Applied at ticket creation via `swh_apply_assignment_rules()`. First matching rule wins; falls back to `swh_default_assignee`.
- **SLA cron** — `swh_sla_check_event` runs hourly. Lock transient: `swh_lock_sla`. Open statuses filtered via `swh_sla_open_statuses` filter hook.
- **Reporting transients** — `swh_report_{type}` cached for `HOUR_IN_SECONDS`. Types: `status_breakdown`, `avg_resolution_time`, `weekly_trend`, `first_response_time`.

## Release Process

1. Determine version using [SemVer](https://semver.org/).
2. Bump `Version:` header and `SWH_VERSION` in `simple-wp-helpdesk.php`.
3. Update `CHANGELOG.md` ([Keep a Changelog](https://keepachangelog.com/) format).
4. Update `simple-wp-helpdesk/readme.txt` stable tag and changelog.
5. Update `docs/` files for any changed behaviour or new features.
6. **Run full test suite** (all four must pass — see Test Suite below).
7. **Ask the user before creating a PR** — never open one autonomously.
8. PR from `release/vX.Y.Z` to `main`. Close addressed GitHub issues.
9. **Run CodeRabbit review** on the PR (`/review`). Address all actionable findings before merge.
10. Merge to `main`, then push the version tag: `git tag vX.Y.Z && git push origin vX.Y.Z`
11. `release.yml` workflow triggers automatically — builds `simple-wp-helpdesk.zip` and creates the GitHub Release with CHANGELOG notes attached.
12. **After pushing, do not monitor CI run status.** Wait for the user to report results before taking further action.

> ZIP is built by `release.yml` on every `v*.*.*` tag push — no manual zip command needed.

## Test Suite

The full test suite must pass before any release. Use `make` targets (requires `composer install` first):

### Local gate (required before opening any PR)
```bash
make test-docker  # full gate inside Docker — required (no host PHP fallback)
make e2e          # Playwright E2E — 56 sections (set WP_MODE=docker or configure SSH vars)
make e2e-docker   # fully self-contained E2E: up + setup + test + teardown in one command
make test-all     # make test + make e2e
```

> **Docker required.** The pre-push hook calls `make test-docker` and aborts if Docker is unavailable. There is no host-PHP fallback.

### Individual tools
```bash
make lint        # PHP syntax check on all plugin files
make phpcs       # WordPress Coding Standards (zero errors/warnings required)
vendor/bin/phpcbf && make phpcs   # auto-fix then re-check
make phpstan     # PHPStan level 9 (PHP 8.1+ required)
make phpunit     # PHPUnit unit tests
make semgrep     # Semgrep SAST scan
make coverage    # PHPUnit coverage → coverage.xml (requires pcov or xdebug)
```

### Docker test stack (local or CI)

```bash
# Fully self-contained — one command spins up, runs all 54 E2E sections, tears down
make e2e-docker

# Or manually:
docker compose -f docker-compose.test.yml up -d db wordpress wpcli mailhog
bash docker/setup-test-wp.sh
WP_MODE=docker MAILHOG_URL=http://localhost:8025 make e2e
docker compose -f docker-compose.test.yml down -v
```

**MailHog:** When `MAILHOG_URL` is set and `WP_MODE=docker`, `expect_email()` calls in the test suite assert email delivery automatically via the MailHog API (`http://localhost:8025`). In SSH mode, `expect_email()` falls back to the manual `EMAIL_CHECKS` summary printed after the run.

### 5. CodeRabbit — AI code review (on PR)
Run `/review` after opening the PR. Address all actionable findings before merge.

## Development Commands

```bash
# Quick smoke check (auth, submit, locate)
pytest testing/scripts/test_helpdesk_pw.py -m smoke

# Security tests only
pytest testing/scripts/test_helpdesk_pw.py -m security

# Single section
pytest testing/scripts/test_helpdesk_pw.py -k "test_19"

# Visible browser / slow-motion debug
pytest testing/scripts/test_helpdesk_pw.py --headed --slowmo 500

# Run against local Docker stack instead of SSH dev server
WP_MODE=docker pytest testing/scripts/test_helpdesk_pw.py -v
```

## Playwright Test Suite

**Location:** `testing/scripts/test_helpdesk_pw.py`
**Config:** `testing/pytest.ini`, `testing/scripts/conftest.py`
**Requirements:** `testing/requirements.txt` (playwright 1.58, pytest 9, pytest-playwright 0.7.2)
**Screenshots:** `testing/screenshots/`

### 54 test sections (34 original + 11 v3.0.0 + 7 v3.1.0 + 1 v3.2.0 + 1 v3.3.0)

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
| 28 | cleanup | |

### Architecture

- **Session-scoped browser** — single Chromium instance shared across all tests (login cookies persist)
- **`check()`** — soft-fail helper; failures accumulate and surface after each test via `conftest.py` autouse fixture
- **`skip()`** — records a skip without aborting
- **`as_user(page, user, pass)`** — context manager: logout → login → yield → logout
- **`wpcli(cmd)`** — runs WP-CLI via SSH+docker exec (default) or `docker compose exec` when `WP_MODE=docker`
- **`_clear_rate_limits()`** — deletes `swh_rl_*` rows from wp_options + flushes object cache (rate limiter uses `get_option()` which checks cache first)
- **`_navigate_settings(page)`** — navigates to settings page and removes `.wp-pointer` elements (Security Ninja and other admin pointers intercept clicks)
- **`state` dict** — carries `ticket_id`, `ticket2_id`, `portal_url` etc. across sections
- **`EMAIL_CHECKS` list** — printed at end of run; manually verify via Gmail MCP

### Key gotchas

- **Meta key is `_ticket_status`** (underscore prefix) — `wp post meta get` returns empty; use `wp eval 'echo get_post_meta(ID, "_ticket_status", true);'`
- **`required` attributes** — strip before JS form submit in validation tests or the POST never reaches the server (HTML5 browser validation intercepts it)
- **WordPress word-level search** — `LIKE '%Ticket%'` matches both "Ticket" and "Ticket2"; avoid negative assertions based on title substrings
- **WP admin pointers** — Security Ninja and other plugins inject `.wp-pointer` overlays that intercept Playwright clicks; `_navigate_settings()` removes them on every settings page visit
- **Bulk action key format** — `sanitize_title('In Progress')` → `in-progress` → action value `swh_status_in-progress`
- **`expect_navigation()`** — wrap JS-triggered form submits in `with page.expect_navigation():` to avoid race between evaluate and `page.content()`
- **Canned response persistence check** — use `el.value` via `page.evaluate()`, not `inner_text()` (input values aren't in innerText)
- **File upload form POST** — a page caching plugin fires an immediate GET after the file POST, overwriting the success HTML in the DOM. Use `expect_navigation()` + `wait_for_load_state("load")` to let it settle, then verify success via WP-CLI meta rather than `page.content()`
- **`wpcli()` exits non-zero for absent data** — `wp option get`, `wp post meta get`, `wp comment meta get` all exit 1 when the key/meta is absent. This is a normal result (not an infrastructure error); `wpcli()` returns empty string and callers handle it via `check()`. Do NOT raise on non-zero in the helper.
- **Authorization header stripped in Docker** — Apache drops the `Authorization` header before PHP receives it, regardless of `.htaccess` passthrough rules. For test_47 (inbound webhook), bypass HTTP entirely: construct a `WP_REST_Request` and call `swh_handle_inbound_email()` directly via `wp eval`.

### Environment variables (testing/.env)

Copy `testing/.env.example` to `testing/.env` and fill in values. See the example file for descriptions of every variable.

```
WP_URL, WP_LOGIN_URL, WP_ADMIN_URL, WP_SUBMIT_PAGE, WP_PORTAL_PAGE
WP_ADMIN_USER, WP_ADMIN_PASS
WP_TECH1_EMAIL, WP_TECH1_USER, WP_TECH1_PASS
WP_TECH2_USER, WP_TECH2_PASS
CLIENT1_NAME, CLIENT1_EMAIL
CLIENT2_NAME, CLIENT2_EMAIL
WP_MODE          — "ssh" (default, local dev server) or "docker" (local/CI Docker stack)
SSH_HOST, WP_CONTAINER, WP_PATH   — required when WP_MODE=ssh
MAILHOG_URL      — MailHog API base URL (e.g. http://localhost:8025); enables automated email assertions when WP_MODE=docker
```

### Test update policy

Every PR that introduces user-facing changes **must** include a new or updated Playwright test section.

| Change type | Required test |
|-------------|---------------|
| New user-facing feature | New numbered section in `test_helpdesk_pw.py` |
| Bug fix (regression) | New or extended section covering the bug scenario |
| Admin-only UI change | New or extended admin section |
| Internal refactor, no UX change | None required — existing sections must still pass |
| Security fix | Extend or add a `security`-marked section |

New sections continue from the next available number (currently 53). Add the section to the table above.

## Dev Tools

### Static Analysis & Test Gate

| Tool | Command | Notes |
|------|---------|-------|
| **Local gate (Docker)** | `make test-docker` | Full gate inside phptest container — no host PHP/semgrep needed (preferred) |
| **Local gate (host)** | `make test` | Runs lint → phpcs → phpstan → phpunit → semgrep; requires host PHP 8.1+, semgrep |
| **E2E** | `make e2e` | Playwright suite; set `WP_MODE=docker` or configure SSH vars |
| **E2E (self-contained)** | `make e2e-docker` | Spins up Docker stack + setup + E2E + teardown in one command |
| PHPStan | `make phpstan` | Level 9, WP stubs via `szepeviktor/phpstan-wordpress` |
| PHPUnit | `make phpunit` | Unit tests in `tests/Unit/`; WP-Mock (`10up/wp_mock`) for WordPress function stubs |
| Coverage | `make coverage` | PHPUnit + pcov → `coverage.xml` (Clover); requires pcov or xdebug |
| Semgrep | `make semgrep` / MCP `semgrep_scan` | SAST; also runs in CI via `.github/workflows/semgrep.yml` |
| Docker stack | `docker compose -f docker-compose.test.yml up -d` | Local WP + MySQL + MailHog; `bash docker/setup-test-wp.sh` to configure |

### LSP (Language Intelligence)

Configured in `.vscode/settings.json` and `testing/pyrightconfig.json`.

| Language | Server | Binary | Notes |
|----------|--------|--------|-------|
| PHP | Intelephense | `intelephense` (npm global) | WP stubs from `vendor/php-stubs/wordpress-stubs/` via `intelephense.environment.includePaths` |
| Python | Pyright | `pyright` (npm global) | Venv: `testing/.venv`; configured via `testing/pyrightconfig.json` |
| TypeScript | typescript-language-server | `typescript-language-server` (npm global) | |

LSP operations available: `goToDefinition`, `findReferences`, `hover`, `documentSymbol`, `workspaceSymbol`, `goToImplementation`, `prepareCallHierarchy`, `incomingCalls`, `outgoingCalls`.

### MCP Servers

| Server | Purpose |
|--------|---------|
| `playwright` (npx) | Browser automation |
| `github` | GitHub API — issues, PRs, code search |
| Docker MCP gateway | Aggregates 11 servers (see below) |

**Docker MCP gateway servers:**

| Name | Purpose |
|------|---------|
| `github-official` | GitHub (OAuth) |
| `context7` | Up-to-date library/framework docs |
| `microsoft-learn` | Microsoft/Azure docs |
| `memory` | Knowledge graph persistent memory |
| `playwright` | Browser automation (Docker instance) |
| `aws-documentation` | AWS docs search |
| `node-code-sandbox` | Node.js sandbox execution |
| `sqlite-mcp-server` | SQLite as a tool |
| `dockerhub` | Docker Hub |
| `mcp-python-refactoring` | Python refactoring assistant |
| `next-devtools-mcp` | Next.js dev tools |

### Plugins & Slash Commands

| Command | Plugin | Purpose |
|---------|--------|---------|
| `/commit` | commit-commands | Create a git commit |
| `/commit-push-pr` | commit-commands | Commit + push + open PR |
| `/clean_gone` | commit-commands | Delete gone branches |
| `/code-review` | code-review | Review a PR |
| `/review` | coderabbit | CodeRabbit AI review |
| `/review-pr` | pr-review-toolkit | Multi-agent PR review |
| `/feature-dev` | feature-dev | Guided feature development |
| `/revise-claude-md` | claude-md-management | Update CLAUDE.md from session |

### Plugin-provided agents (via Agent tool)

| Agent | Plugin | Purpose |
|-------|--------|---------|
| `pr-review-toolkit:code-reviewer` | pr-review-toolkit | Code quality + style |
| `pr-review-toolkit:silent-failure-hunter` | pr-review-toolkit | Error handling gaps |
| `pr-review-toolkit:code-simplifier` | pr-review-toolkit | Simplify after writing code |
| `pr-review-toolkit:comment-analyzer` | pr-review-toolkit | Comment accuracy |
| `pr-review-toolkit:pr-test-analyzer` | pr-review-toolkit | Test coverage gaps |
| `pr-review-toolkit:type-design-analyzer` | pr-review-toolkit | Type design quality |
| `feature-dev:code-explorer` | feature-dev | Trace execution paths |
| `feature-dev:code-architect` | feature-dev | Design feature blueprints |
| `coderabbit:code-reviewer` | coderabbit | Full CodeRabbit review |

## GitHub Auto-Updater

Uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (bundled in `vendor/`). Initialized in bootstrap with `PucFactory::buildUpdateChecker()`. Branch: `main`. Supports release assets and API token auth.

# Compact instructions

When using compact, focus on: code changes made, errors encountered, current task progress, and file paths being modified. Drop verbose tool output and exploration results.
