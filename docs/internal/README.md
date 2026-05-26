# `docs/internal/` — Developer & Agent Documentation

This directory is the developer- and agent-facing knowledge base for Simple WP Helpdesk. It documents **why** the code is the way it is, what constraints it preserves, and how to extend it safely.

It is **not** end-user documentation, and it is **excluded from the published GitHub Pages site** (`docs/_config.yml` → `exclude:`).

## What lives here vs `docs/` proper

| Location | Audience | Content |
|---|---|---|
| `docs/` (root) | Operators, site admins, integrators using the plugin | Install, configure, use, troubleshoot, integrate (including the public **Hooks Reference** and **Inbound Email Webhook** guide). |
| `docs/developer/` | Integrators writing PHP against the plugin | Deprecation policy (versioning rules for hook removal). |
| `docs/internal/` | Plugin contributors and AI coding agents | Architecture, invariants, conventions, internal data model, test strategy, release process, per-area contracts. |

When in doubt: if the reader is *using* the plugin, it belongs in `docs/`. If the reader is *modifying* the plugin, it belongs here.

## Read every session

Two docs frame everything else. Skim at the start of every session:

1. **[planning-and-release-process.md](planning-and-release-process.md)** — the non-negotiable planning, PR-gating, and release process.
2. **[README.md](README.md)** (this file) — the routing table for every other doc.

## Reading order for a new contributor

1. **[design-document.md](design-document.md)** — architecture, repository layout, load-bearing invariants.
2. **[planning-and-release-process.md](planning-and-release-process.md)** — how work moves through the pipeline.
3. **[coding-guide.md](coding-guide.md)** — naming, security, escaping, comments, PR-time gates.
4. **[testing-overview.md](testing-overview.md)** → per-suite docs (`testing-guide.md`, `extended-test-plan.md`, `visual-regression-suite.md`).
5. **[security-model.md](security-model.md)** — trust boundaries and threat model.
6. **[api-contract.md](api-contract.md)** — what is public and what may change.
7. **[data-dictionary.md](data-dictionary.md)** — every meta key, option, transient, capability.
8. **[config-reference.md](config-reference.md)** — operator-tunable options.
9. **[design-guide.md](design-guide.md)** + **[component-inventory.md](component-inventory.md)** + **[accessibility.md](accessibility.md)** + **[i18n.md](i18n.md)** — UI / UX / a11y / strings.
10. **The relevant contract doc** for the area you're working in (see Per-area contracts below).

## Per-area contracts

These are **the** authoritative description of each major functional area. Read the matching contract before touching code in that area.

| Area | Contract |
|---|---|
| Ticket lifecycle, status machine, hooks fired | [contract-ticket-lifecycle.md](contract-ticket-lifecycle.md) |
| Client portal (token auth, reply / close / reopen / CSAT) | [contract-portal.md](contract-portal.md) |
| Email pipeline, templates, dispatch | [contract-email-templates.md](contract-email-templates.md) |
| Inbound email webhook | [contract-inbound-webhook.md](contract-inbound-webhook.md) |
| Anti-spam (honeypot, reCAPTCHA, Turnstile) | [contract-anti-spam.md](contract-anti-spam.md) |
| Reporting (KPIs, charts, caching) | [contract-reporting.md](contract-reporting.md) |
| File uploads, storage, serving, retention | [contract-file-uploads.md](contract-file-uploads.md) |
| Settings page (10 tabs, two-form save) | [contract-settings.md](contract-settings.md) |
| Scheduled work (auto-close, retention, SLA check) | [contract-cron.md](contract-cron.md) |

## Routing — when in doubt

| You are about to … | Read first | Then update |
|---|---|---|
| Kick off a release | `planning-and-release-process.md` + `roadmap.md` + `release_v4.x.x_roadmap.md` | `release_v4.x.x_roadmap.md` |
| Write a plan | `superpowers:writing-plans` + planning-and-release-process.md | the plan + this README if new docs land |
| Touch ticket status / merge / close / reopen | `contract-ticket-lifecycle.md`, `design-document.md` invariants | the contract |
| Touch portal forms / auth / rate limiting | `contract-portal.md`, `security-model.md` | both |
| Touch email send / template render | `contract-email-templates.md` | the contract + `data-dictionary.md` if new option keys |
| Touch inbound webhook | `contract-inbound-webhook.md`, end-user `docs/inbound-webhook.md`, `security-model.md` | the contract + end-user doc |
| Touch anti-spam | `contract-anti-spam.md`, `security-model.md` | the contract + `config-reference.md` |
| Touch reports | `contract-reporting.md`, `performance-baseline.md` | the contract |
| Touch file uploads / serving | `contract-file-uploads.md`, `security-model.md` | the contract + `data-dictionary.md` |
| Add / change a setting | `contract-settings.md`, `config-reference.md`, `data-dictionary.md` | all three + the relevant tab in end-user `docs/configuration.md` |
| Add a hook | `api-contract.md`, end-user `docs/hooks.md` | both |
| Add a REST endpoint | `api-contract.md`, `security-model.md` | both |
| Add a Playwright section | `testing-overview.md` → `testing-guide.md` | `testing-guide.md` taxonomy table |
| Add a PHPUnit case | `testing-overview.md` → `testing-guide.md` | n/a |
| Run RC sign-off | `extended-test-plan.md` | sign-off block in the RC PR |
| Run visual regression | `visual-regression-suite.md` | sign-off block in the `dev → main` PR |
| Add a UI primitive / token | `component-inventory.md`, `design-guide.md`, `accessibility.md` | `component-inventory.md` |
| Localize a new string | `i18n.md` | `i18n.md` checklist + regenerate `.pot` at release |
| Add a runtime or dev dependency | `dependencies.md` | the doc + get user approval before opening the PR |
| Defer something | If indefinitely: `parked_features.md`. Otherwise: open a GH issue tagged to a future milestone | the matching doc |
| Answer "is X already known to be a bug?" | Memory MCP (`search_nodes`) | n/a |
| Operator-facing question ("how do I configure X?") | `docs/configuration.md` | `docs/` proper, not here |

## All internal docs

### Process

- `README.md` (this file) — index + routing.
- `planning-and-release-process.md` — planning, kickoff, implementation, PR gates, release gate, non-negotiables.
- `doc-style-guide.md` — voice, structure, formatting rules for every doc in this repo.
- `glossary.md` — locked terminology used across docs and code.
- `adr/` — Architectural Decision Records (the small set of decisions everything else hangs from).

### Architecture, design, conventions

- `design-document.md` — architecture, module map, load-bearing invariants.
- `coding-guide.md` — naming, security, escaping, comments, PR-time gates.
- `design-guide.md` — UX/UI principles.
- `component-inventory.md` — UI primitives, CSS classes, design tokens.
- `accessibility.md` — WCAG 2.2 AA target + hard rules.
- `i18n.md` — text domain, translation functions, `.pot`, RTL.

### Contracts (per functional area)

- `contract-ticket-lifecycle.md`
- `contract-portal.md`
- `contract-email-templates.md`
- `contract-inbound-webhook.md`
- `contract-anti-spam.md`
- `contract-reporting.md`
- `contract-file-uploads.md`
- `contract-settings.md`
- `contract-cron.md`

### Data, config, public surface

- `data-dictionary.md` — every WP storage primitive the plugin owns.
- `config-reference.md` — `swh_get_defaults()` exhaustive reference.
- `api-contract.md` — public hooks/REST/AJAX, versioning, breaking-change taxonomy.
- `security-model.md` — trust boundaries, threats, capability matrix.

### Testing

- `testing-overview.md` — top-level map across all suites.
- `testing-guide.md` — PHP + E2E suite execution detail.
- `extended-test-plan.md` — RC sign-off runbook (Gate 2).
- `visual-regression-suite.md` — manual visual regression (Gate 3, pre `dev → main`).
- `test-coverage-matrix.md` — feature → test mapping.
- `performance-baseline.md` — measured baselines.

### Build & dependencies

- `js-architecture.md` — `@wordpress/scripts` build, vanilla JS conventions.
- `dependencies.md` — runtime + dev deps; rules for adding more.

### Planning & retrospective

- `roadmap.md` — north-star living roadmap.
- `release_v4.x.x_roadmap.md` — current per-version execution roadmap.
- `parked_features.md` — explicitly deferred ideas.
- `new_features_discussion.md` — unapproved brainstorm.
- `lessons-learned.md` — incident retrospectives and "don't do this again" notes.

### Archived (historical, not maintained)

`archive/` contains shipped planning artifacts kept for context:

- `archive/release_v3.x.x_roadmap.md` — completed v3.x roadmap.
- `archive/plan_v3.7.0.md` — v3.7.0 implementation plan.
- `archive/v3.7.0-discovery.md` — v3.7.0 discovery notes.
- `archive/v3.7.0-issue-snapshots/` — v3.7.0 issue snapshots.
- `archive/v3.7.0-ship-audit.md` — v3.7.0 release-readiness audit.
- `archive/v4.0.0-foundation-discovery.md` — v4.0.0 foundation work discovery.

## Update protocol

Update this file when:
- A new doc is added to `docs/internal/`.
- A doc is renamed, archived, or its scope materially changes.
- A new "routing" trigger surfaces.

This is the table of contents. If it falls out of date the rest of the set decays. Treat broken routing entries as bugs.
