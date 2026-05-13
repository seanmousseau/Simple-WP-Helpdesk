# Contributing to Simple WP Helpdesk

Thanks for your interest in contributing. Please read `CLAUDE.md` for the full project conventions, security rules, and release process.

## JS development (v3.7.0+)

New JS code goes in `simple-wp-helpdesk/assets/src/` and is built via [`@wordpress/scripts`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/) to `simple-wp-helpdesk/assets/dist/`. The build output is **committed to the repo** so end users installing from the WordPress.org ZIP do not need Node.

### Local development

```bash
npm install            # one-time
npm run start          # watch mode during development
npm run build          # production build before commit
```

### Conventions

- One entrypoint per feature: `assets/src/<feature>/index.js` (passed as `<name>=<feature>/index.js` to `wp-scripts build` in `package.json`).
- Use `@wordpress/components`, `@wordpress/element`, `@wordpress/i18n` as external dependencies — wp-scripts emits them as `wp.*` globals and lists them in the auto-generated `*.asset.php` file. The PHP enqueue side reads that file to wire dependencies + cache-busting hashes automatically.
- Bundle size budget: **≤40 KB gzip per entrypoint** (excludes WP-shipped externals).
- Legacy `assets/swh-admin.js` migrates piece-by-piece across v4.x — for v3.7 only the toast notification renderer is ported.

### Before committing

```bash
npm run build
git add simple-wp-helpdesk/assets/dist/
```

The pre-push hook does not run `npm run build` — you must commit fresh dist files when you change `assets/src/`. CI (`make e2e-docker`) rebuilds before running E2E, so a stale dist will still be caught by tests.

## PHP development

See `CLAUDE.md` for security conventions (nonces, capability checks, escaping, sanitization) and the full test gate. Short version:

```bash
make test-docker   # full PHP gate in Docker (lint, phpcs, phpstan, phpunit, semgrep)
make e2e-docker    # full Playwright E2E in Docker
```
