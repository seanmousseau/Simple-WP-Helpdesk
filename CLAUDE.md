# CLAUDE.md

Working-memory for AI agents on Simple WP Helpdesk. Hot invariants, dev commands, and routing pointers — full project docs live in `docs/internal/`.

## Project Overview

Simple WP Helpdesk — a WordPress helpdesk/ticketing plugin. No custom DB tables; uses CPT (`helpdesk_ticket`), comments, post meta, and `wp_options`.

- **Version:** 3.7.0 | **WP:** 5.3+ | **PHP:** 7.4+ | **Repo:** seanmousseau/Simple-WP-Helpdesk
- **Plugin lives in:** `simple-wp-helpdesk/` subdir. Repo root holds dev tooling only.

## Where to read

Before working on anything non-trivial, route via `docs/internal/README.md`. Common intents:

| Intent | Read |
|---|---|
| Architecture / "why is the code shaped this way?" | `docs/internal/design-document.md` |
| Load-bearing invariants (BEFORE changing anything) | `docs/internal/design-document.md` — invariant table |
| Conventions, naming, security hot list | `docs/internal/coding-guide.md` |
| Testing — when to add what, commands, gotchas | `docs/internal/testing-guide.md` |
| Threat model, capability matrix, attack surfaces | `docs/internal/security-model.md` |
| Hooks, REST, AJAX, shortcodes — the public API | `docs/internal/api-contract.md` |
| Post meta / comment meta / transients / options layout | `docs/internal/data-dictionary.md` |
| Operator-tunable settings (the 7-group taxonomy) | `docs/internal/config-reference.md` |
| UI/UX patterns, dark mode wiring | `docs/internal/design-guide.md` + `docs/internal/component-inventory.md` |
| JS build, `@wordpress/scripts`, bundle budget | `docs/internal/js-architecture.md` |
| Perf numbers + `make bench` | `docs/internal/performance-baseline.md` |
| Release roadmaps | `docs/internal/release_v3.x.x_roadmap.md`, `docs/internal/release_v4.x.x_roadmap.md` |
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
| A setting (add/change/remove) | `config-reference.md` |
| A hook, REST endpoint, AJAX, or shortcode attribute | `api-contract.md` |
| Untrusted-input handling, auth, files, tokens | `security-model.md` |
| A shared CSS/JS primitive or design token | `component-inventory.md` |
| User-facing feature or bug-fix | A new or extended Playwright section (see `testing-guide.md`) |

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
