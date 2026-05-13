# `docs/internal/` — Developer & Agent Documentation

This directory is the developer- and agent-facing knowledge base for Simple WP Helpdesk. It documents **why** the code is the way it is, what constraints it preserves, and how to extend it safely.

It is **not** end-user documentation.

## What lives here vs `docs/` proper

| Location | Audience | Content |
|---|---|---|
| `docs/` (root of) | Operators, site admins, integrators using the plugin | How to install, configure, use, troubleshoot. Public hook reference. |
| `docs/developer/` | Integrators writing PHP against the plugin | Public action/filter hook signatures and deprecation policy. |
| `docs/internal/` | Plugin contributors and AI coding agents | Architecture, invariants, conventions, internal data model, test strategy. |

When in doubt: if the reader is *using* the plugin, it belongs in `docs/`. If the reader is *modifying* the plugin, it belongs here.

## Reading order for a new contributor

1. **[design-document.md](design-document.md)** — architecture, repository layout, load-bearing invariants.
2. **[coding-guide.md](coding-guide.md)** — naming, security, escaping, comments, PR-time gates.
3. **[testing-guide.md](testing-guide.md)** — test layers, how to run, when to add what.
4. **[security-model.md](security-model.md)** — trust boundaries and threat model.
5. **[api-contract.md](api-contract.md)** — what is public and what may change.
6. **[data-dictionary.md](data-dictionary.md)** — every meta key, option, transient, capability.
7. **[config-reference.md](config-reference.md)** — operator-tunable options.
8. **[design-guide.md](design-guide.md)** — UX/UI principles. Defers component-level detail to `component-inventory.md`.

## Routing — when in doubt

| You are about to … | Read first | Then update |
|---|---|---|
| Add a new option / setting | `config-reference.md` | `config-reference.md` + `data-dictionary.md` |
| Add/rename/remove a post-meta key | `data-dictionary.md`, `design-document.md` invariants | `data-dictionary.md` + upgrade routine in `class-installer.php` |
| Add/rename/remove a hook (`do_action`/`apply_filters`) | `api-contract.md`, `docs/developer/hooks.md` | `docs/developer/hooks.md` + `api-contract.md` |
| Add a REST endpoint | `api-contract.md`, `security-model.md` | both |
| Add a Playwright section | `testing-guide.md` | `testing-guide.md` taxonomy table |
| Touch ticket status logic | `design-document.md` invariant on `swh_set_ticket_status` | none if invariant respected |
| Touch file upload / serving | `security-model.md` | `security-model.md` if posture changes |
| Add a UI primitive / token | `component-inventory.md` | `component-inventory.md` |
| Add JS | `js-architecture.md` | `js-architecture.md` |
| Answer "is X already known to be a bug?" | Memory MCP (`search_nodes`) | n/a |
| Operator-facing question ("how do I configure X?") | `docs/configuration.md` | `docs/` proper, not here |

## All internal docs

### New in this set (authoritative starting point)

- `README.md` (this file) — index + routing.
- `design-document.md` — architecture, module map, load-bearing invariants, release engineering.
- `coding-guide.md` — naming, security, escaping, comments, PR-time gates.
- `testing-guide.md` — PHPUnit, Playwright, CI, environment variables.
- `security-model.md` — trust boundaries, threats, capability matrix.
- `api-contract.md` — public surface, versioning policy, breaking-change taxonomy.
- `data-dictionary.md` — every WP storage primitive the plugin owns.
- `config-reference.md` — `swh_get_defaults()` exhaustive reference.
- `design-guide.md` — UX/UI principles; defers to `component-inventory.md`.

### Pre-existing authoritative docs (do not duplicate)

- `component-inventory.md` — UI primitives, CSS classes, design tokens. Source of truth for UI vocabulary.
- `js-architecture.md` — `@wordpress/scripts` build, vanilla JS conventions, toast module.
- `performance-baseline.md` — measured baselines for ticket list / portal / settings.
- `release_v3.x.x_roadmap.md` — planned work for the v3.x line.
- `release_v4.x.x_roadmap.md` — planned work for the v4.x line.
- `parked_features.md` — explicitly deferred ideas.
- `lessons-learned.md` — incident retrospectives and "don't do this again" notes.
- `new_features_discussion.md` — open design questions.
- `plan_v3.7.0.md`, `v3.7.0-discovery.md`, `v3.7.0-issue-snapshots/` — v3.7.0 release-planning artifacts; historical, not maintained going forward.

## Update protocol

Update this file when:
- A new doc is added to `docs/internal/`.
- A doc is renamed or its scope materially changes.
- A new "routing" trigger surfaces (e.g. "every time I want to do X I have to remember which doc covers it").

This is the table of contents. If it falls out of date the rest of the set decays. Treat broken routing entries as bugs.
