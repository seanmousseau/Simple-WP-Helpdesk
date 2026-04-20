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

## Release checklist (fill out for version bump PRs only)

- [ ] `CHANGELOG.md` updated under the correct version heading
- [ ] `simple-wp-helpdesk/readme.txt` stable tag and changelog updated
- [ ] New options added to `swh_get_defaults()` in `includes/helpers.php`
- [ ] New meta keys documented in `CLAUDE.md` — Common Pitfalls section
- [ ] Version bumped in `simple-wp-helpdesk/simple-wp-helpdesk.php`
- [ ] ZIP and GitHub Release created automatically by `release.yml` on tag push (no manual ZIP step needed)
