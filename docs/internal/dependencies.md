# Dependencies

What we depend on, why, and the rules for adding more.

> **Default position:** hand-roll it. Every dependency is a future security update, breaking change, license review, and supply-chain risk. We add a dependency only when the value clearly outweighs the carrying cost.

---

## Runtime dependencies — PHP

Shipped inside `simple-wp-helpdesk/vendor/`. These travel with the plugin ZIP.

| Package | Purpose | Why we depend on it |
|---|---|---|
| `yahnis-elsts/plugin-update-checker` | GitHub-based auto-updater. Surfaces new releases in the WP plugin dashboard. | Re-implementing the WP update API surface for a private/GitHub-hosted plugin is non-trivial and security-sensitive. Mature, widely-used library. |
| (autoloader) `vendor/composer/*` | PSR-4 autoload for `SWH\` classes. | Generated, not a dependency in itself. |

That is the complete shipped dependency list. **Everything else is hand-rolled inside the plugin** — including:

- Token generation (uses WordPress's `wp_generate_password()`)
- Constant-time comparison (uses PHP core `hash_equals()`)
- Rate limiting (uses `wp_options` + our own keying scheme)
- Anti-spam verification (direct cURL calls to Google / Cloudflare APIs)
- Email template parsing (our own `swh_parse_template()`)
- File upload handling (WordPress's `wp_handle_upload()`)
- Sanitization / escaping (WordPress core)
- Cron scheduling (`wp_schedule_event`)

---

## Runtime dependencies — JavaScript

| Package | Purpose | Notes |
|---|---|---|
| `chart.js` | Reporting dashboard charts. | Bundled, not from npm — loaded via `wp_enqueue_script` from `simple-wp-helpdesk/assets/`. |

No other JS runtime dependencies. The JS source under `simple-wp-helpdesk/assets/src/` is plain ES modules; the build tool is dev-only.

---

## Development dependencies — PHP

Repo-root `composer.json` (`require-dev`):

| Package | Purpose |
|---|---|
| `phpunit/phpunit` ^9.6 | Unit test runner |
| `10up/wp_mock` ^1.0 | Stubbing WP functions in unit tests |
| `yoast/phpunit-polyfills` ^2.0 | PHPUnit cross-version compat |
| `wp-phpunit/wp-phpunit` ^6.0 | WP integration test scaffolding |
| `squizlabs/php_codesniffer` ^3.7 | PHPCS engine |
| `wp-coding-standards/wpcs` ^3.0 | WordPress Coding Standards ruleset |
| `dealerdirect/phpcodesniffer-composer-installer` ^1.0 | Auto-registers PHPCS rulesets |
| `szepeviktor/phpstan-wordpress` ^2.0 | PHPStan + WP stubs |
| `php-stubs/wordpress-stubs` ^6.9 | WP function/class stubs for static analysis |
| `doctrine/instantiator` ^1.5 | Indirect — PHPUnit + WP-Mock |

---

## Development dependencies — JavaScript

Repo-root `package.json` (`devDependencies`):

| Package | Purpose |
|---|---|
| `@wordpress/scripts` ^32.2.0 | Build tooling for `simple-wp-helpdesk/assets/src/` → `dist/`. Wraps webpack, eslint, prettier. |

Entry points: `toast`, `admin-inbox`, `views-settings`, `theme-violet`, `portal-v2`, `reports-v2`.

Build: `npm run build` (or `make js-build`). Output is **committed** under `simple-wp-helpdesk/assets/dist/`.

---

## Test-tooling dependencies (out-of-band)

These don't ship with the plugin but are required for the test suites:

| Tool | Used by | Install |
|---|---|---|
| Docker + Docker Compose | `make test-docker`, `make e2e-docker` | system |
| Python 3.11+ | E2E test runner | system |
| Playwright (Python) | E2E suite | `pip install -r testing/requirements.txt` |
| MailHog | Email assertions in E2E | container (`docker-compose.test.yml`) |
| Semgrep | SAST gate | `make semgrep` / Semgrep MCP server |
| `wp-cli` | Inside the test container | container |

---

## Rules for adding a dependency

Before adding any new dependency — runtime or dev — answer all four:

### 1. Can WordPress core do it?

If the answer is yes, use core. Examples we've stayed on core for:

- HTTP requests → `wp_remote_*`
- Cron → `wp_schedule_event`
- Options → `wp_options` via `get_option`/`update_option`
- Sanitization → `sanitize_*`
- Escaping → `esc_*`
- File uploads → `wp_handle_upload`
- Nonces → `wp_create_nonce`

### 2. Can PHP / browser core do it?

If yes, use core. Examples:

- Constant-time string comparison → `hash_equals()`
- Strong randomness → `random_bytes()` / WP's `wp_generate_password`
- JSON parsing → `json_encode` / `json_decode`
- Date math → `DateTimeImmutable`
- Form data → `fetch()` / `FormData` (no jQuery, no Axios)

### 3. Is the dependency at least as well-maintained as we are?

Check before adding:

- Last release within ~12 months?
- Active issue tracker (responses in days, not months)?
- License compatible with GPLv2-or-later?
- No deprecation warnings in the latest version?

### 4. Does the value clearly outweigh the carrying cost?

A library that saves 50 LoC but adds 2,000 LoC of transitive deps and a yearly security-patch cycle is a bad trade. The bar is high. Be prepared to defend the trade-off in PR review.

### Approval

Adding a runtime dependency requires explicit user approval before the PR opens. Adding a dev dependency requires it to be called out in the release plan.

---

## Dependency hygiene

- **Dependabot is enabled.** Open Dependabot PRs are triaged at the start of every release cycle. Security advisories are merged inside the current cycle, not deferred.
- **npm `overrides` for vulnerable transitives.** When `@wordpress/scripts` ships a vulnerable transitive that upstream hasn't bumped yet, we use the `overrides` block in `package.json` to force a patched version. As of v4.0.7 the override list is `serialize-javascript ^7.0.5`, `minimatch ^10.2.5`, `webpack-dev-server ^5.2.4`, `uuid ^11.1.1`. **Always verify the production build (`npm run build`) succeeds after adding an override** — these are dev tools so they don't ship, but they can break the build if the major bump is incompatible with how `@wordpress/scripts` invokes them. Drop an override once the upstream catches up.
- **Lock file commits.** `composer.lock` and `package-lock.json` are committed. The plugin's bundled `vendor/` is committed (we ship from `main`; we don't `composer install` at runtime).
- **Audit before release.** As part of the `release → dev` documentation review (Gate 2), spot-check that this doc still matches reality. New deps must be added here in the same PR.

---

## Removed dependencies

If we ever drop a dependency, record it here:

| Package | Removed in | Replacement |
|---|---|---|
| _none yet_ | | |
