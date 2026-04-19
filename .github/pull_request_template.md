## Summary
<!-- What does this PR do? One sentence. -->

## Type of change
- [ ] Bug fix
- [ ] New feature / enhancement
- [ ] Refactor / DX improvement
- [ ] Documentation / tooling only

---

## Pre-PR gate — run `make test` locally before opening

All five steps must exit 0:

- [ ] `make lint` — PHP syntax check passes
- [ ] `make phpcs` — zero PHPCS errors/warnings
- [ ] `make phpstan` — PHPStan level 9 passes
- [ ] `make phpunit` — all unit tests pass
- [ ] `make semgrep` — no Semgrep findings

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
- [ ] ZIP built: `zip -r releases/vX.Y.Z/simple-wp-helpdesk.zip simple-wp-helpdesk/`
