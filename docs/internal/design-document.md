# Design Document

**Audience:** plugin contributors, AI coding agents.

## Architecture overview

Simple WP Helpdesk is a WordPress plugin that delivers a complete ticketing/helpdesk system **without introducing any custom database tables**. All persistent state is stored in WordPress core primitives:

- **Tickets** are posts of the custom post type `helpdesk_ticket` (`simple-wp-helpdesk/includes/class-installer.php:275`).
- **Replies and internal notes** are comments with `comment_type = 'helpdesk_reply'` (set in `simple-wp-helpdesk/includes/helpers.php` via merge logic and elsewhere in the codebase).
- **Ticket fields** (status, priority, assignee, token, etc.) are post meta keyed by `_ticket_*` and `_swh_*`.
- **Plugin settings** are options under the `swh_*` prefix (see `swh_get_defaults()` at `simple-wp-helpdesk/includes/helpers.php:19-121`).
- **Categories** are a custom taxonomy `helpdesk_category` (`class-installer.php:312`).

The result is a plugin that installs without schema changes, uninstalls cleanly via standard WP option/post/comment deletion, and ports between hosts via any WP-native export/import path.

The repository contains two distinct layers:

- **Repository root** — dev tooling, CI, tests, Docker stack, documentation, packaging scripts. Nothing here ships to end users.
- **`simple-wp-helpdesk/` subdirectory** — the actual plugin. This is what `release.yml` zips for distribution.

## Repository layout

```text
simple-wp-helpdesk/                     # repo root (dev tooling)
├── simple-wp-helpdesk/                 # plugin root (shipped artifact)
│   ├── simple-wp-helpdesk.php          # Bootstrap: constants, requires, lifecycle, AJAX/REST registration
│   ├── includes/
│   │   ├── helpers.php                 # Defaults, statuses, anti-spam, rate limiting, status transitions
│   │   ├── class-installer.php         # Activation, deactivation, uninstall, upgrade, CPT, taxonomy
│   │   ├── class-email.php             # Template parsing, send wrapper, HTML wrapper, inbound webhook
│   │   ├── class-ticket.php            # File proxy, uploads, deletion, comment filters, CSAT AJAX
│   │   ├── class-cron.php              # Auto-close, retention, SLA breach detection
│   │   └── deprecations.php            # Soft-deprecation shims for removed/renamed APIs
│   ├── admin/                          # Loaded only when is_admin() is true
│   │   ├── class-settings.php          # Tabbed settings UI + save handler
│   │   ├── class-ticket-editor.php     # Meta boxes, save_post, conversation UI
│   │   ├── class-ticket-list.php       # Columns, sorting, filters, admin styles
│   │   ├── class-reporting.php         # Reporting AJAX endpoints
│   │   ├── class-reporting-ui.php      # Reports submenu page + Chart.js
│   │   └── class-plugin-action-links.php
│   ├── frontend/
│   │   ├── class-shortcode.php         # [submit_ticket] + [helpdesk_portal] shortcodes
│   │   └── class-portal.php            # Client portal view
│   ├── vendor/                         # Composer + plugin-update-checker (bundled)
│   ├── assets/                         # CSS, JS, icons. Built JS lands in assets/dist/
│   ├── languages/                      # .pot / .po / .mo
│   ├── src/                            # PSR-4 namespaced classes (v3.7+ PoC; additive)
│   ├── composer.json                   # Runtime Composer manifest (autoload only)
│   └── readme.txt                      # WordPress.org-style readme
├── tests/Unit/                         # PHPUnit unit tests (WP_Mock)
├── testing/                            # Playwright E2E + helpers
├── docs/                               # User-facing + developer-facing + internal docs
├── docker/, docker-compose.test.yml    # Local Docker test stack (WP + MySQL + MailHog)
├── .github/workflows/                  # CI: php-tests, e2e, semgrep, coverage, release, claude
├── Makefile                            # test-docker, e2e, e2e-docker, bench, js-build, etc.
├── composer.json                       # Dev-time Composer (PHPCS, PHPStan, PHPUnit, WP_Mock)
├── phpstan.neon, phpunit.xml, .phpcs.xml
└── package.json                        # @wordpress/scripts JS build
```

Plugin-internal constants (defined in `simple-wp-helpdesk/simple-wp-helpdesk.php:19-27`):

| Constant | Purpose |
|---|---|
| `SWH_VERSION` | Single source of truth for the version string (`3.7.0` at HEAD). |
| `SWH_PLUGIN_DIR` | `plugin_dir_path(__FILE__)` of the bootstrap. Module files use this, never `__FILE__`. |
| `SWH_PLUGIN_URL` | `plugin_dir_url(__FILE__)`. |
| `SWH_PLUGIN_FILE` | The bootstrap path. Passed to `register_*_hook()` and `plugin_basename()`. |
| `SWH_ICON_1X`, `SWH_ICON_2X`, `SWH_MENU_ICON` | Bundled brand assets — no CDN dependency. |

## Module map

| File | Responsibility |
|---|---|
| `simple-wp-helpdesk.php` | Bootstrap only. Defines constants, requires modules, registers lifecycle hooks (`register_activation_hook` etc.), configures plugin-update-checker, hosts the ticket-merge AJAX and REST inbound-email registration. |
| `includes/helpers.php` | All `swh_*` global functions: defaults, options getters, status transitions, anti-spam, rate limiting, IP detection, portal link builder, merge, CC parsing, assignment rules, format helpers. |
| `includes/class-installer.php` | Activation (Technician role + cron events), deactivation (cron cleanup), uninstall (full purge gated by `swh_delete_on_uninstall`), upgrade routine, CPT + taxonomy registration. |
| `includes/class-email.php` | `swh_send_email()` template-driven mailer, `swh_wrap_html_email()` HTML wrapper with inlined CSS, inbound email REST callback. |
| `includes/class-ticket.php` | File proxy `swh_serve_file()`, upload handling, ticket deletion (post + files), CSAT AJAX, comment list-table filters. |
| `includes/class-cron.php` | Cron callbacks: auto-close, ticket retention, attachment retention, SLA check. Each guarded by a `swh_lock_*` transient. |
| `includes/deprecations.php` | Soft-deprecation shims kept so old hook names still fire during a deprecation window. |
| `admin/class-settings.php` | Tabbed settings page, render + save handler. Two distinct forms (main settings, Tools) with separate nonces. |
| `admin/class-ticket-editor.php` | Meta boxes (status, priority, assignee), `save_post` handler, conversation rendering. |
| `admin/class-ticket-list.php` | Admin list table customisation: columns, sorting, filters, badges. |
| `admin/class-reporting.php` | `wp_ajax_swh_report_data` endpoint; caches via `swh_report_*` transients. |
| `admin/class-reporting-ui.php` | Reports submenu render + Chart.js enqueue. |
| `admin/class-plugin-action-links.php` | "Settings" link on the Plugins screen row. |
| `frontend/class-shortcode.php` | `[submit_ticket]` and `[helpdesk_portal]` rendering and POST handling. |
| `frontend/class-portal.php` | Token-authenticated portal: conversation view, reply, close/reopen, CSAT prompt. |

## Load-bearing invariants

If you violate one of these, something visible breaks. Cite line ranges before changing the surrounding code.

| # | Invariant | What breaks if violated | Code location | Since |
|---|---|---|---|---|
| 1 | No custom database tables. Tickets, replies, fields, settings all live in WP core tables (`wp_posts`, `wp_comments`, `wp_postmeta`, `wp_commentmeta`, `wp_options`, `wp_term_*`). | Clean-install / clean-uninstall promise; backup/restore portability; the "zero-privilege" plugin install posture. | No `CREATE TABLE` exists anywhere — verified by absence in `class-installer.php`. | 1.0 |
| 2 | All `wp_options` use the `swh_` prefix; all post meta use `_ticket_*` or `_swh_*`; all comment meta use `_is_*` / `_swh_*`. | Uninstall sweep relies on prefix `LIKE` queries (`class-installer.php:228-229`). Mis-prefixed keys persist forever. | `class-installer.php:228-244`; `helpers.php` getters. | 1.0 |
| 3 | Status transitions go through `swh_set_ticket_status()`. It diffs old vs new, fires `swh_ticket_status_changed`, `swh_ticket_closed`, `swh_ticket_reopened`, and is a no-op when old equals new. **Initial-create** sites call `update_post_meta()` directly so `swh_ticket_created` is the single source of truth for creation. | Integrations subscribed to `swh_ticket_closed` miss events; double-fires on no-op writes; spurious "closed" events fire on initial create. | `helpers.php:164-213`. | 3.7.0 |
| 4 | Reply comments are inserted with `comment_type = 'helpdesk_reply'`. The `swh_run_upgrade_routine()` v2 migration overwrites stale comment_type values to repair pre-v2 installs. Internal notes additionally carry `_is_internal_note = 1` comment meta and must be filtered out of frontend rendering. | Replies leak into the site's default comment templates; or staff-only notes leak to the client portal. | `helpers.php:668-684` (merge inserts); upgrade in `class-installer.php:92-105`. | 2.0.0 |
| 5 | `_ticket_token` must be compared with `hash_equals()`, never `==`. Tokens missing `_ticket_token_created` are grandfathered (pre-v1.9.0) and never expire. | Timing oracle on token compare; or every pre-v1.9.0 ticket's portal link breaks the day expiration is enabled. | `helpers.php:336-346` (`swh_is_token_expired`); portal code uses `hash_equals`. | 1.9.0 |
| 6 | Files are served only via `swh_serve_file()` (action `init` priority 1, `class-ticket.php:21`). The proxy validates the resolved path starts inside `wp_get_upload_dir()['basedir']`. Direct upload URLs in the browser must not be linkable to clients. | Authorization bypass / path traversal / unattenuated public reads of attached files. | `class-ticket.php:21-` (file proxy). | 2.0.0 |
| 7 | `swh_get_client_ip()` is the only correct way to read the client IP. It honours `HTTP_CF_CONNECTING_IP` then `HTTP_X_FORWARDED_FOR` then `REMOTE_ADDR`. Never read `$_SERVER['REMOTE_ADDR']` directly. | Rate limiting and anti-spam misattribute when sitting behind a CDN or reverse proxy. | `helpers.php:356-365`. | 2.1.0 |
| 8 | Email always goes through `swh_send_email( $to, $subject_key, $body_key, $data, $attachments, $ticket_id )`. It runs template parsing (`{placeholder}` + `{if key}…{endif key}`), applies the HTML wrapper, and dispatches `swh_email_headers` filter. Never call `wp_mail()` directly from feature code. | Skips branding wrapper; skips conditional-block parsing; bypasses the `swh_email_headers` filter; site-wide template overrides do not apply. | `class-email.php:90-` (send wrapper). | 1.0 |
| 8.1 | The HTML wrapper inlines all CSS. No `<link>` and no `<style>` blocks are sent — webmail clients strip them. Email-client dark mode is opted into via `<meta name="color-scheme" content="light dark">` + an inline `@media (prefers-color-scheme: dark)` block (only Apple Mail / iOS Mail honour it). | Webmail clients render unstyled HTML. | `class-email.php:145-` (`swh_wrap_html_email`). | 3.6.0 |
| 9 | Two distinct settings forms render on the settings page: the main form, and the **Tools** form. They use different nonces. Tools exclusively owns `swh_retention_*` and `swh_delete_on_uninstall`. Mixing fields across forms either silently drops the value or invokes the wrong handler. | Settings save silently no-ops; or destructive Tools options fire from the main form without the Tools nonce. | `admin/class-settings.php` render + save handlers. | 2.2.0 |
| 10 | `swh_save_ticket_data()` in `admin/class-ticket-editor.php` is the only place admin-side ticket edits flow through. It detects changes and dispatches emails. Adding a new ticket field means: (a) add the meta to `data-dictionary.md`, (b) wire save here, (c) handle the change detection if it triggers email. | New ticket fields silently fail to persist or fail to notify. | `admin/class-ticket-editor.php` (`swh_save_ticket_data`). | 1.0 |
| 11 | New cron events use **unique scheduled offsets** so they do not all fire at the same second. Current offsets at activation: `swh_autoclose_event` = now, `swh_retention_tickets_event` = now + 1800, `swh_retention_attachments_event` = now + 3600, `swh_sla_check_event` = now + 5400. Each callback is guarded by a `swh_lock_*` transient. | Concurrent execution thrashes WP option locks; one cron event blocks the others. | `class-installer.php:42-53`; locks at `class-cron.php:56, 145, 280, 330`. | 2.0.0 / 3.0.0 |
| 12 | `pre_get_posts` with a `meta_key` argument implicitly filters out posts lacking that meta. Setting `meta_key` to drive ordering or filtering on the ticket list will silently hide tickets without that meta. Use `meta_query` with `NOT EXISTS` if you need to include nulls. | Admin list silently shows a subset of tickets. | Multiple `pre_get_posts` callers in `admin/class-ticket-list.php`. | 1.0 |
| 13 | Technician restriction (`swh_restrict_to_assigned = yes`) is enforced **only** on the admin list-table query (`pre_get_posts`) and on direct `load-post.php`. Any custom `WP_Query` for `helpdesk_ticket` is **not** filtered. Reporting, AJAX, and any new query path must add the assignee filter explicitly. | Technician sees unassigned tickets via reporting or any new query. | `admin/class-ticket-list.php`; `admin/class-ticket-editor.php`. | 2.4.0 |
| 14 | `swh_get_secure_ticket_link()` requires `_ticket_token` post meta to be present. **Order matters**: always `update_post_meta($id, '_ticket_token', $token)` before calling `swh_get_secure_ticket_link($id)`. If called first the function returns `false` and the email body falls back to a broken or wrong URL. | First confirmation email contains a broken link. | `helpers.php:308-325`. | 1.9.0 |
| 15 | Portal rate-limit keys are **per-action**: `portal_close_`, `portal_reopen_`, `portal_reply_` + ticket id. Sharing a key across actions blocks immediate reopen after close. | Client cannot reopen a ticket they just closed (or vice versa). | `helpers.php:777-794`; portal handlers in `frontend/class-portal.php`. | 2.1.0 |
| 16 | Original filenames live in **two** parallel locations: post meta `_swh_attachment_orignames` (new-ticket uploads, keyed by file URL), and comment meta `_swh_reply_orignames` (reply uploads — one meta entry per comment, keyed by file URL). Pre-v2.3.0 tickets have neither and must fall back to `basename($url)`. | Conversation UI shows hashed upload filenames instead of human-readable ones. | Merge code at `helpers.php:655-660`; ticket save handler. | 2.3.0 |
| 17 | The `_swh_unread` post meta and the `swh_unread_count` 5-minute transient are paired. Whenever code sets or clears `_swh_unread` it MUST `delete_transient('swh_unread_count')`. | Admin unread badge displays a stale count for up to 5 minutes. | `helpers.php:740-764` (read); ticket-editor + portal handlers (write). | 3.1.0 |

## Design decisions

These are embedded rationale notes, not separate ADRs. Each decision answers a recurring "why is it like this?" question.

### No custom DB tables

The plugin promises "zero-privilege install, clean uninstall, WP-native portability". Custom tables would require `dbDelta()` migrations, separate backup/restore paths, and host-level CREATE privileges. Restricting ourselves to CPT + comments + meta + options keeps the install footprint identical to any other plugin, makes any WP backup tool a complete backup of the helpdesk, and reduces the uninstall surface to "delete all rows with our prefix". The trade-off is performance: meta-based filtering does not scale past tens of thousands of tickets. `performance-baseline.md` records the measured limits. Migration to indexed tables is parked for v4.x — see `release_v4.x.x_roadmap.md`.

### Storage map: CPT vs comments vs meta vs options

| Concept | Storage | Why not the other choices |
|---|---|---|
| Ticket | CPT (`helpdesk_ticket`) | Inherits WP's editor, auth model, list table, REST infrastructure. Alternative would be a custom table, rejected per above. |
| Reply / internal note | Comment (`comment_type = 'helpdesk_reply'`) | Inherits WP's threading, moderation, author tracking. CPT-per-reply was rejected as it would explode the post count and break the editor screen. |
| Ticket field (status, priority, etc.) | Post meta | Each ticket has 10+ fields; storing them as columns on a custom table was rejected per above. `register_post_meta` is intentionally not used because show-in-REST is gated separately. |
| Plugin setting | Option | Settings are global, not per-post. The Settings API does not work with meta. |
| Categorization | Taxonomy (`helpdesk_category`) | Hierarchical categories are exactly what custom taxonomies are for. Rolling our own would lose admin column rendering and term-query optimisation. |
| Caching of expensive reads | Transient | TTL-based eviction is exactly what transients give us. Persistent option storage was rejected because it bloats `wp_options`. |
| Rate-limit lock | Option (`swh_rl_*`, not a transient) | The lock value (an epoch timestamp) must survive `wp_cache_flush()` calls and object-cache flushes that some hosts run aggressively. Transients are cleared by these flushes; options are not. |

### `swh_send_email()` is the only mail dispatcher

Templates ship as 16 option pairs (`*_sub` / `*_body`), parsed with placeholder + `{if}/{endif}` substitution, then wrapped in an HTML shell with inlined CSS, then dispatched. Calling `wp_mail()` directly skips all three layers. The `swh_email_headers` filter (`class-email.php:109`) is the supported integration point.

### PSR-4 additive coexistence with `require_once`

v3.7.0 introduces a `src/` PSR-4 namespace (`SWH\…`) as a proof-of-concept while leaving the existing `require_once` calls intact. The autoloader (`vendor/autoload.php`) is required at bootstrap (`simple-wp-helpdesk.php:30-32`). Both styles coexist — new code can land in either form. Full migration is deferred to v4.x.

### Two settings forms, two nonces

The Tools tab houses the destructive options: data retention (which can delete tickets and attachments on a cron) and `delete_on_uninstall` (which permits full data nuke). Keeping these in a physically separate `<form>` with its own nonce prevents accidental save of one when the user thought they were saving the other, and gives PHPCS / Semgrep an easier time tracking the intent of each save handler.

## Release engineering

**Versioning:** SemVer (https://semver.org/). Versions are bumped in **three** places that must all match:

1. `simple-wp-helpdesk/simple-wp-helpdesk.php` — `Version:` header (line 5) and `SWH_VERSION` constant (line 19).
2. `simple-wp-helpdesk/readme.txt` — `Stable tag:` line.
3. `CHANGELOG.md` — new entry at the top.

`docs/` files for any changed behaviour are also updated in the same PR.

**Build:** `.github/workflows/release.yml` triggers on any `v*.*.*` tag push and produces `simple-wp-helpdesk.zip` as a GitHub Release asset. **There is no manual zip step.**

**Process:**

1. Branch `release/vX.Y.Z` from `main`.
2. Bump versions in the three places above and update `CHANGELOG.md`.
3. Run `make test-docker` and `make e2e-docker` locally. Both must pass.
4. Ask the user before opening a PR.
5. Open PR to `main`. Close addressed GitHub issues from the PR.
6. Run CodeRabbit review (`/review`). Address all actionable findings.
7. Merge.
8. Push the tag: `git tag vX.Y.Z && git push origin vX.Y.Z`.
9. `release.yml` builds and publishes the GitHub Release automatically. Do not monitor; wait for user confirmation.

**Auto-updater:** the plugin embeds `yahnis-elsts/plugin-update-checker` (`simple-wp-helpdesk/vendor/plugin-update-checker/`). It polls the GitHub releases of `seanmousseau/Simple-WP-Helpdesk` on the `main` branch and surfaces updates via the standard WP Dashboard → Updates UI.

## Update protocol

Update this doc when:
- A new module is added under `simple-wp-helpdesk/{includes,admin,frontend}/`.
- A new load-bearing invariant is introduced or an existing one is relaxed/removed.
- The repository layout changes at the top-two levels.
- The release engineering pipeline changes (new gate, new artifact, changed branch model).
- A design decision is overturned — strike the old rationale, add the new one.

Cite real file:line references for every claim. If you cannot cite, drop the claim.
