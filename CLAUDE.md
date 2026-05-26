# CLAUDE.md

Working-memory for AI agents on Simple WP Helpdesk. Hot invariants, dev commands, and routing pointers — full project docs live in `docs/internal/`.

## Project Overview

Simple WP Helpdesk — a WordPress helpdesk/ticketing plugin. No custom DB tables; uses CPT (`helpdesk_ticket`), comments, post meta, and `wp_options`.

- **Version:** 4.0.6 | **WP:** 6.0+ | **PHP:** 8.0+ | **Repo:** seanmousseau/Simple-WP-Helpdesk
- **Plugin lives in:** `simple-wp-helpdesk/` subdir. Repo root holds dev tooling only.

## Session start — read these every session

These two docs set the rails for everything else. Re-skim at the start of every fresh session:

1. **`docs/internal/planning-and-release-process.md`** — the non-negotiable planning, PR-gating, and release process. Applies to every cycle.
2. **`docs/internal/README.md`** — table of contents + routing table for every other internal doc.

## Where to read

Route via `docs/internal/README.md`. Common intents:

| Intent | Read |
|---|---|
| **Planning / release process** | `planning-and-release-process.md` |
| **Living roadmap (north-star)** | `roadmap.md` |
| Architecture / "why is the code shaped this way?" | `design-document.md` |
| Load-bearing invariants (BEFORE changing anything) | `design-document.md` — invariant table |
| Conventions, naming, security hot list | `coding-guide.md` |
| **Testing — top-level map** | `testing-overview.md` → per-suite docs |
| Per-suite: PHP / E2E | `testing-guide.md` |
| Per-suite: extended (RC sign-off) | `extended-test-plan.md` |
| Per-suite: manual visual regression | `visual-regression-suite.md` |
| Threat model, capability matrix, attack surfaces | `security-model.md` |
| Hooks, REST, AJAX, shortcodes — public surface | `api-contract.md` + end-user `docs/hooks.md` |
| **Per-area contracts** | `contract-ticket-lifecycle.md`, `contract-portal.md`, `contract-email-templates.md`, `contract-inbound-webhook.md`, `contract-anti-spam.md`, `contract-reporting.md`, `contract-file-uploads.md`, `contract-settings.md`, `contract-cron.md` |
| **Locked architectural decisions (ADRs)** | `adr/` (no custom tables, comments-as-replies, GitHub updates, two-form save, bearer auth on webhook, token-only portal) |
| **Glossary** | `glossary.md` — locked terminology |
| **Doc style** | `doc-style-guide.md` — voice, structure, formatting |
| Post meta / comment meta / transients / options layout | `data-dictionary.md` |
| Operator-tunable settings | `config-reference.md` |
| UI/UX patterns, dark mode wiring | `design-guide.md` + `component-inventory.md` |
| **Accessibility (WCAG 2.2 AA)** | `accessibility.md` |
| **Internationalization (text domain, .pot, RTL)** | `i18n.md` |
| **Dependencies — what we depend on and rules for adding more** | `dependencies.md` |
| JS build, `@wordpress/scripts`, bundle budget | `js-architecture.md` |
| Perf numbers + `make bench` | `performance-baseline.md` |
| Roadmap (per-version execution) | `release_v4.x.x_roadmap.md` (current), `archive/release_v3.x.x_roadmap.md` (shipped) |
| Lessons learned / retrospectives | `lessons-learned.md` |
| Parked / indefinitely deferred features | `parked_features.md` |
| Open design questions (not yet approved) | `new_features_discussion.md` |
| Operator / end-user docs | `docs/index.md` and the rest of `docs/*.md` (NOT `docs/internal/`) |

## Hot invariants (must hold in working memory)

These are the ones that bite most often. The full numbered table is in `docs/internal/design-document.md`. If you violate one, something visible breaks.

1. **`swh_set_ticket_status()` is the only correct way to mutate `_ticket_status`** (v3.7+). Direct `update_post_meta()` bypasses lifecycle hooks. Initial-create sites are the only exception.
2. **`swh_send_email()` is the only correct mail dispatcher.** Never `wp_mail()` directly — skips template parser, `{if}` blocks, and the HTML wrapper.
3. **Token comparison uses `hash_equals()`, never `==`.** Applies to portal tokens and inbound-webhook sender check.
4. **Portal URL ordering:** store `_ticket_token` meta BEFORE calling `swh_get_secure_ticket_link()`. Otherwise it returns false and the email body falls back to a wrong URL.
5. **Rate-limit keys are per-action with ticket_id:** `portal_close_`, `portal_reopen_`, `portal_reply_` + id. Shared keys block immediate reopen after close.
6. **No custom DB tables.** All storage is CPT + meta + comments + options + transients. See `docs/internal/data-dictionary.md`.
7. **Comment isolation:** `comment_type='helpdesk_reply'` segregates ticket activity; internal notes carry `_is_internal_note=1` and must be filtered from frontend.

## Dev commands

| Command | What it does |
|---|---|
| `make test-docker` | **Required before push** — full PHP gate (lint, PHPCS, PHPStan L9, PHPUnit, Semgrep) inside Docker. Enforced by pre-push hook. |
| `make e2e-docker` | Self-contained Playwright — spins stack, runs 64 sections, tears down. ~5 min. |
| `make e2e` | Playwright against an existing stack (`WP_MODE=docker` or SSH vars). |
| `make js-build` | Build `simple-wp-helpdesk/assets/dist/` from `assets/src/` via `@wordpress/scripts`. |
| `make bench` | Perf baseline against the Docker stack (`COUNT=N`, default 100). |
| `make phpcs` / `phpstan` / `phpunit` / `semgrep` | Individual gates. |
| `vendor/bin/phpcbf && make phpcs` | Auto-fix style then re-check. |

Full test-strategy reference and Playwright section taxonomy: `docs/internal/testing-guide.md`.

## PR-time gates

If your PR touches any of these, update the corresponding internal doc:

| Touching… | Update |
|---|---|
| Schema-ish state (options, post meta, comment meta, transients, CPT, capabilities) | `data-dictionary.md` |
| A setting (add/change/remove) | `config-reference.md` + `contract-settings.md` |
| A hook, REST endpoint, AJAX, or shortcode attribute | `api-contract.md` + end-user `docs/hooks.md` |
| Untrusted-input handling, auth, files, tokens | `security-model.md` |
| Inbound webhook | `contract-inbound-webhook.md` + end-user `docs/inbound-webhook.md` |
| Ticket lifecycle / status / merge | `contract-ticket-lifecycle.md` |
| Portal forms / token auth / rate limiting | `contract-portal.md` |
| Email events / templates / wrapper | `contract-email-templates.md` |
| Anti-spam methods | `contract-anti-spam.md` |
| Reports / KPIs / charts | `contract-reporting.md` |
| File uploads / serving / retention | `contract-file-uploads.md` |
| Settings page UI / save handler | `contract-settings.md` |
| Cron / scheduled work (auto-close, retention, SLA check) | `contract-cron.md` |
| Architectural decision worth recording | new ADR under `adr/` + this README |
| Any new term that appears in 2+ docs | `glossary.md` |
| Writing or editing any doc | follow `doc-style-guide.md` |
| A shared CSS/JS primitive or design token | `component-inventory.md` |
| Anything user-visible | `accessibility.md` checklist |
| Any user-facing string | `i18n.md` checklist |
| Add a runtime or dev dependency | `dependencies.md` (and get user approval first) |
| User-facing feature or bug-fix | A new or extended Playwright section (see `testing-overview.md`) |
| Anything that changes how a release ships | `planning-and-release-process.md` |

**Release-time** documentation review covers, at minimum: end-user `docs/`, all touched internal docs, `CLAUDE.md`, `README.md`, `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md`. See `planning-and-release-process.md` Gate 2.

## Dev environment

### Static analysis & test gate

| Tool | Command | Notes |
|---|---|---|
| Local gate (Docker) | `make test-docker` | Preferred — no host PHP/semgrep needed |
| Local gate (host) | `make test` | Requires host PHP 8.1+, semgrep |
| PHPStan | `make phpstan` | Level 9, WP stubs via `szepeviktor/phpstan-wordpress` |
| PHPUnit | `make phpunit` | `tests/Unit/`; WP-Mock for WP stubs |
| Coverage | `make coverage` | Clover XML; requires pcov or xdebug |
| Semgrep | `make semgrep` / MCP `semgrep_scan` | SAST; also CI via `.github/workflows/semgrep.yml` |

### LSP (Language Intelligence)

Configured in `.vscode/settings.json` and `testing/pyrightconfig.json`.

| Language | Server | Binary | Notes |
|---|---|---|---|
| PHP | Intelephense | `intelephense` (npm global) | WP stubs from `vendor/php-stubs/wordpress-stubs/` via `intelephense.environment.includePaths` |
| Python | Pyright | `pyright` (npm global) | Venv: `testing/.venv`; `testing/pyrightconfig.json` |
| TypeScript | typescript-language-server | (npm global) | |

LSP operations: `goToDefinition`, `findReferences`, `hover`, `documentSymbol`, `workspaceSymbol`, `goToImplementation`, `prepareCallHierarchy`, `incomingCalls`, `outgoingCalls`.

### MCP Servers

`playwright` (npx) · `github` · Docker MCP gateway (aggregates 11: `github-official`, `context7`, `microsoft-learn`, `memory`, `playwright`, `aws-documentation`, `node-code-sandbox`, `sqlite-mcp-server`, `dockerhub`, `mcp-python-refactoring`, `next-devtools-mcp`).

### Plugins & Slash Commands

| Command | Plugin | Purpose |
|---|---|---|
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
|---|---|---|
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

Uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (bundled in `simple-wp-helpdesk/vendor/`). Initialized in bootstrap with `PucFactory::buildUpdateChecker()`. Branch: `main`. Supports release assets and API token auth.

# Compact instructions

When using compact, focus on: code changes made, errors encountered, current task progress, and file paths being modified. Drop verbose tool output and exploration results.
