## Summary
<!-- What does this PR do? One sentence. -->

## Type of change
- [ ] Bug fix
- [ ] New feature / enhancement
- [ ] Refactor / DX improvement
- [ ] Documentation / tooling only

---

## Pre-PR gate — run `make test-docker` (or `make test`) locally before opening

All five steps must exit 0:

- [ ] `make test-docker` — full gate in Docker (preferred; no host PHP/semgrep needed)
- [ ] OR `make test` — fallback if Docker is unavailable (requires host PHP 8.1+, semgrep)

Individual targets if you need to run one at a time: `make lint`, `make phpcs`, `make phpstan`, `make phpunit`, `make semgrep`

---

## E2E coverage

- [ ] `make e2e` — all existing Playwright sections pass
- [ ] New functionality has a new test section in `testing/scripts/test_helpdesk_pw.py`
  - Section number: `##`
  - Marks applied: <!-- smoke / security / slow / none -->
- [ ] OR this is an internal refactor with no UX change (no new section required)

---

## Test coverage matrix (any PR touching a feature surface)

The matrix at `docs/internal/test-coverage-matrix.md` is the definition of done for testing. `make matrix-lint-diff` (which runs in CI) fails if any feature row regressed (a cell went from `✓` / `P` / `N/A` to `R`) without explicit rationale. For every feature this PR touches, confirm:

- [ ] **L1 — Invariant** test under `tests/Unit/Invariants/` exists or this feature has no load-bearing invariant (N/A with reason)
- [ ] **L2 — REST/AJAX contract** test under `tests/Unit/Rest/Contracts/` exists or N/A (e.g. no REST surface)
- [ ] **L3 — Domain** test under `tests/Unit/` covers the state transitions / side effects, or N/A
- [ ] **L4 — Behavior E2E** test in `testing/scripts/test_helpdesk_pw.py` uses semantic selectors (`get_by_role` / `get_by_label` / `get_by_text`), no CSS-class coupling
- [ ] **L5 — Visual regression** baseline added/updated, or N/A (cron, REST, no UI)
- [ ] Matrix doc row updated to reflect new state of each cell
- [ ] `make matrix-lint-diff` is green locally

---

## Release checklist (fill out for version bump PRs only)

- [ ] `CHANGELOG.md` updated under the correct version heading
- [ ] `simple-wp-helpdesk/readme.txt` stable tag and changelog updated
- [ ] New options added to `swh_get_defaults()` in `includes/helpers.php`
- [ ] New meta keys documented in `CLAUDE.md` — Common Pitfalls section
- [ ] Version bumped in `simple-wp-helpdesk/simple-wp-helpdesk.php`
- [ ] ZIP and GitHub Release created automatically by `release.yml` on tag push (no manual ZIP step needed)
