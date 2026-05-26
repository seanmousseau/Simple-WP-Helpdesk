# Testing Overview

High-level map of every test suite we run against Simple WP Helpdesk. Each suite has its own dedicated doc; this page tells you which one to read and when.

> Testing is non-negotiable. Every feature, functionality change, and bug fix must come with updates to the relevant suites — see `planning-and-release-process.md`.

---

## Suite map

| Suite | Doc | Trigger | Audience |
|---|---|---|---|
| **PHP suite** (lint, PHPCS, PHPStan, PHPUnit, Semgrep) | `testing-guide.md` | Every push (pre-push hook enforces `make test-docker`) | Automated |
| **E2E suite** (Playwright + pytest) | `testing-guide.md` | Per PR + every push to feature/step branches | Automated |
| **Extended test suite** (RC sign-off runbook) | `extended-test-plan.md` | Once per release candidate, before `release → dev` | Human-driven, scripted |
| **Manual visual regression suite** | `visual-regression-suite.md` | Once per release, before `dev → main` | Human, scripted via Playwright MCP |
| **Performance baseline** | `performance-baseline.md` | On demand or after perf-affecting work | Automated (`make bench`) |
| **Test coverage matrix** | `test-coverage-matrix.md` | Reference only — maps features → tests | n/a |

---

## When to update which suite

Match the change to the suites that need to cover it:

| Change type | PHP | E2E | Extended | Visual regression |
|---|:-:|:-:|:-:|:-:|
| New REST/AJAX endpoint | ✅ | ✅ | maybe | — |
| New WP hook / filter | ✅ | — | — | — |
| New setting in admin UI | ✅ | ✅ | — | ✅ |
| New frontend feature (portal, form, etc.) | ✅ | ✅ | ✅ | ✅ |
| New email event or template change | ✅ | ✅ | ✅ | ✅ (preview render) |
| Inbound webhook change | ✅ | ✅ | ✅ | — |
| Bug fix that user could see | ✅ | ✅ | maybe | maybe |
| Internal refactor with no UX impact | ✅ | — | — | — |
| Dependency bump | ✅ | ✅ | — | — |
| Migration / installer change | ✅ | ✅ | ✅ | — |
| Security-sensitive change | ✅ | ✅ | ✅ | — |
| Performance-affecting change | ✅ | — | — | — (run `make bench`) |

A tick means "must add or update a test in this suite". A blank means "not required, but allowed if useful."

---

## Running everything locally

```bash
# Full PHP gate inside Docker (required before push — pre-push hook enforces this)
make test-docker

# Full E2E (self-contained — spins stack, runs sections, tears down)
make e2e-docker

# Coverage report → coverage.xml (Clover)
make coverage

# Performance baseline (COUNT=N optional, defaults to 100)
make bench
```

Per-suite execution detail lives in the suite-specific docs above.

---

## CI

- **PR CI** — runs PHP suite (lint, PHPCS, PHPStan, PHPUnit, Semgrep) and E2E suite on every push to a PR. Workflows: `.github/workflows/*.yml`.
- **Semgrep** — additional dedicated workflow `.github/workflows/semgrep.yml`. Also available locally via `make semgrep` and the Semgrep MCP server.
- **Release workflow** — `release.yml` triggers on tag push (`vX.Y.Z`); builds the plugin ZIP and creates the GitHub Release.

CI failures block all merge gates — see `planning-and-release-process.md`.

---

## What does not have a test suite

These exist intentionally outside the automated suites:

- **The release process itself** — process compliance is reviewed at merge gates, not unit-tested.
- **The Memory MCP graph** — that is session/agent state, not project code.
- **Operator documentation accuracy** — verified manually at release time during doc review (Gate 2).
