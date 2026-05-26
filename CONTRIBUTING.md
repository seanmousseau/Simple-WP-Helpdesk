# Contributing to Simple WP Helpdesk

Thanks for your interest in contributing. This page is the **entry point** for code contributions. The full conventions, security rules, and release process live in [`CLAUDE.md`](CLAUDE.md) and `docs/internal/`.

> Before opening a non-trivial PR, please read [`docs/internal/planning-and-release-process.md`](docs/internal/planning-and-release-process.md). It describes the planning, PR-gating, and release process we follow on every change.

---

## What kind of contribution?

| You want to … | Do this |
|---|---|
| Report a bug | Open an issue using the **Bug report** template |
| Request a feature | Open an issue using the **Feature request** template |
| Report a security vulnerability | **Do not** open a public issue. See [SECURITY.md](SECURITY.md) |
| Fix a small bug | Open an issue first if one doesn't exist, then PR with the fix |
| Build a feature | Open / find a tracking issue, get scope confirmed, then PR |
| Improve docs | PR directly is fine for typo / clarity fixes; for restructure, open an issue first |

---

## Local setup

### Requirements

- PHP 8.0+ (8.1+ for the host-PHP test gate)
- Docker + Docker Compose (preferred — avoids needing host PHP)
- Node 18+ if you'll be touching JS source under `simple-wp-helpdesk/assets/src/`
- Python 3.11+ if you'll be running the E2E suite locally

### Clone & install

```bash
git clone https://github.com/seanmousseau/Simple-WP-Helpdesk.git
cd Simple-WP-Helpdesk
composer install
npm install                # only if touching JS source
```

The `composer install` post-script wires up `core.hooksPath .githooks` so the pre-push hook is active.

### Run the test gate locally

```bash
make test-docker           # required before every push (pre-push hook enforces)
make e2e-docker            # E2E suite in self-contained Docker
```

Per-suite execution detail: [`docs/internal/testing-overview.md`](docs/internal/testing-overview.md) and [`docs/internal/testing-guide.md`](docs/internal/testing-guide.md).

---

## PHP development

Pickup points:

- **Coding standards:** [`docs/internal/coding-guide.md`](docs/internal/coding-guide.md)
- **Security conventions:** [`docs/internal/security-model.md`](docs/internal/security-model.md) — nonces, capability checks, escaping, sanitization, token comparison
- **Per-area contracts:** before touching a major functional area, read the matching `docs/internal/contract-*.md`
- **i18n:** every user-facing string must be translatable. See [`docs/internal/i18n.md`](docs/internal/i18n.md)
- **Accessibility:** every UI change must meet the WCAG 2.2 AA bar described in [`docs/internal/accessibility.md`](docs/internal/accessibility.md)

Short version of the security must-haves:

- All admin saves: `current_user_can()` + nonce check.
- All token comparisons: `hash_equals()`, not `==`.
- All untrusted input: `sanitize_*()` on the way in, `esc_*()` on the way out.
- All file operations: paths validated with `realpath()` against the protected upload directory.

---

## JS development

New JS lives in `simple-wp-helpdesk/assets/src/<feature>/index.js` and is built via [`@wordpress/scripts`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/) into `simple-wp-helpdesk/assets/dist/`. The build output is **committed** so end users don't need Node.

```bash
npm run start              # watch mode during development
npm run build              # production build before commit
make js-build              # equivalent via Make
```

Conventions:

- One entrypoint per feature.
- Use `@wordpress/components`, `@wordpress/element`, `@wordpress/i18n` as externals — wp-scripts emits them as `wp.*` globals.
- Bundle size budget: **≤ 40 KB gzip per entrypoint** (excludes WP-shipped externals).
- Before committing JS changes: `npm run build && git add simple-wp-helpdesk/assets/dist/`. The pre-push hook does NOT run the JS build for you.

Full reference: [`docs/internal/js-architecture.md`](docs/internal/js-architecture.md).

---

## Documentation

Every code change must come with documentation updates. The full table is in [`CLAUDE.md`](CLAUDE.md#pr-time-gates), but as a quick reference:

| Touching… | Update |
|---|---|
| A user-visible feature | the matching `docs/*.md` end-user page |
| Settings / options | `docs/internal/config-reference.md` + `docs/internal/data-dictionary.md` |
| A public hook | `docs/internal/api-contract.md` + `docs/hooks.md` |
| Any of the major functional areas | the matching `docs/internal/contract-*.md` |
| Security-sensitive code | `docs/internal/security-model.md` |
| A new dependency | `docs/internal/dependencies.md` (and get approval first) |

---

## Tests

Tests are not optional. Every PR adds or updates tests in the relevant suites:

- **PHP suite** (`make phpunit`): unit + WP-mocked tests in `tests/Unit/`.
- **E2E suite** (`make e2e-docker`): Playwright sections in `testing/scripts/test_helpdesk_pw.py`.
- **Extended test suite**: release-candidate sign-off runbook — [`docs/internal/extended-test-plan.md`](docs/internal/extended-test-plan.md).
- **Manual visual regression**: per-page screenshot suite — [`docs/internal/visual-regression-suite.md`](docs/internal/visual-regression-suite.md). Required before `dev → main`.

Coverage expectations per change type: [`docs/internal/testing-overview.md`](docs/internal/testing-overview.md).

---

## Branch & PR flow

```
feature/step branch → release/vX.Y.Z → dev → main → tag vX.Y.Z
```

Three merge gates (feature → release → dev → main), all non-negotiable, all described in [`docs/internal/planning-and-release-process.md`](docs/internal/planning-and-release-process.md).

### Branch naming

- `feat/<slug>` — new feature
- `fix/<slug>` — bug fix
- `docs/<slug>` — documentation only
- `chore/<slug>` — tooling / refactor
- `release/vX.Y.Z` — release integration branch

### PR

- Use the PR template (auto-populated). Fill out every section that applies.
- Run `make test-docker` and `make e2e-docker` locally before opening.
- Respond to CodeRabbit findings substantively — don't blindly apply. If you push back, get user approval first.

---

## Code of conduct

We follow the [Contributor Covenant](https://www.contributor-covenant.org/version/2/1/code_of_conduct/) v2.1. Be kind, be specific, assume good intent.

Reports of conduct issues go to <sean@seanmousseau.com>.

---

## Questions?

If something here is unclear or seems to contradict another doc — that's a bug in the docs. Open an issue and we'll fix it.
