# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
starting from the next release after 1.8.

---

## [Unreleased]

### Changed

- **RC tooling — bumped `paramiko` 3.5.1 → 5.0.0** (Dependabot). Verified end-to-end on dev: `verify_env.py` PASS, `webhook_roundtrip.py` PASS (full ticket-create / SMTP / inbound-webhook / comment-poll path). The 2-major jump removes GSSAPI support and SHA1-based kex methods — neither of which we use; our dev sshd uses modern kex algorithms.

## [4.0.5] — 2026-05-25

### Fixed

- **Settings: tab bar spillover creates orphan row + visually detached active state** (#479). With 10 tabs, the bar wrapped to a second row at typical WP admin viewports and the wrapper's bottom border drew between rows, leaving any tab on the last row visually detached. `#swh-tabs` is now `display: flex; flex-wrap: wrap` with per-tab bottom borders so every row gets its own continuous underline. Active state uses a 2px primary-color underline that sits on the row's separator regardless of wrap depth.
- **RC tooling — `email_delivery_check.py` now decodes MIME-Q-encoded Subject headers before substring matching.** Long outbound subjects ride in `=?us-ascii?Q?...?=` segments (spaces become `_`); the previous `--subject-contains` match ran against the raw encoded header and silently missed delivered messages during v4.0.2 RC verification of the assignee-email listener. Falls back to the raw value if decoding throws.

### Changed

- **Settings: consistent group headings across tabs** (#482). General, Portal, and Integrations already used `<fieldset class="swh-settings-group"><legend>` sections; Assignment & Routing, Messages, and Anti-Spam rendered flat row lists. Wrapped them to match — Assignment & Routing now has Routing / SLA Breach Alerts / Auto-Assignment Rules groups; Messages splits into Success Messages / Error Messages; Anti-Spam wraps the table in a Spam Detection group.
- **Settings: removed duplicate Theme controls from Assignment & Routing** (#480). v4.0.3 (#471) moved the Frontend Portal Theme + Admin Theme controls to the new Portal tab but didn't remove the legacy copies. Both wrote to the same option keys (`swh_portal_theme`, `swh_admin_theme`), so this is pure UI cleanup — no data migration. Operators no longer see the same conceptual setting in two places.
- **Settings: Anti-Spam Turnstile rows now hide when Spam Prevention isn't "Turnstile"** (#481). The existing JS toggle handled `.swh-recaptcha-row` visibility tied to the `swh_spam_method` dropdown but never touched the Turnstile rows. Added `.swh-turnstile-row` class to both Turnstile inputs and extended `swhUpdateSpamRows()` to mirror the reCAPTCHA pattern.
- **RC tooling — paramiko-based SSH executor in extended-test scripts** (#447). `verify_env.py` and `_wpcli.py` no longer shell out to `ssh` via `subprocess.run`; both now use a shared paramiko helper at `testing/extended/_ssh.py`. Talks directly to the remote sshd over TCP, so the local Python process never launches a subprocess and Semgrep OSS's `dangerous-subprocess-use-tainted-env-args` rule stops re-flagging the call sites on every edit. Auth chain: SSH agent first, then default key files. Remote argv is still `shlex.join()`-quoted before transmission. Adds `paramiko==3.5.1` to `testing/requirements.txt`. The related #446 (remove the JSON-prefix stripper) is recommended for close-as-won't-fix — the smoke-test during this refactor confirmed `WP_CLI_PHP_ARGS=-d error_reporting=…` does NOT suppress the wp-cli phar's deprecation noise (the deprecation fires during the phar's own bootstrap, before PHP applies the override).

### Documentation

- **Internal docs refresh — five lessons captured during the v4.0.2/v4.0.3 RC extended-test sessions** (#441–#445). `api-contract.md` notes that the `helpdesk_ticket` CPT is intentionally not exposed via core REST and that `/swh/v1` endpoints reject HTTP Basic Auth with regular passwords (use application passwords). `security-model.md` adds a REST authentication subsection. `data-dictionary.md` documents the `comments_clauses` short-circuit gotcha (`--post_id` vs `--post__in`) with a cross-reference in the `CommentIsolationInvariantTest` docblock. `extended-test-plan.md` + `testing/extended/README.md` document the SMTP relay 5.7.1 sender restriction. `lessons-learned.md` records the env-file `$`-must-be-single-quoted convention.

### Security (build tooling only)

- **Dependabot alert refresh** (#453). `npm install` + `npm audit fix` (non-forcing) bumped `qs` 6.15.1 → 6.15.2, closing alert #14 (DoS via crafted comma-format arrays). Added `uuid` to `.github/dependabot.yml`'s npm ignore list — alert #12 (`uuid<11.1.1` buffer-bounds) is grand-transitive via `sockjs → webpack-dev-server`, which is already ignored, and the autofix path would `--force` downgrade `@wordpress/scripts` to 19.2.4. `npm audit --omit=dev` is still **0 vulnerabilities**; nothing ships to end users. Lessons-learned updated with the 2026-05-25 refresh.

## [4.0.4] — 2026-05-25

### Fixed

- **Portal v2 CSAT widget never rendered** (#477). After v4.0.3 added operator-configurable CSAT controls, RC verification surfaced that portal v2 didn't emit the widget at all — the `portal.csat_enabled` toggle was a functional no-op for portal v2 users. The widget was only emitted by the legacy shortcode close branch (POST-back), but portal v2's close path is an `admin-post.php` handler that ends in `wp_safe_redirect()`. The GET-back didn't re-enter the inline-render branch. `swh_render_client_portal()` now emits the CSAT widget inside `#swh-portal-app` whenever the ticket is closed, CSAT is enabled, and the client hasn't rated yet (`_ticket_csat` post meta absent) — Option C from the issue triage, friendliest for clients who don't rate immediately.
- **Frontend submissions with `swh_default_assignee` set via wp-cli didn't send the assignee email** (#476). `swh_apply_assignment_rules()` reads from the v4.0 grouped key `routing.default_assignee`, but the legacy flat option `swh_default_assignee` is only mirrored to grouped storage when written through `swh_set_option()` (which the settings UI uses). Sites that set the option via `wp option update`, the test setup script, or pre-4.0 installs that never re-saved settings hit a silent gap: the assignment fired but the listener never saw an assignee. Added a legacy-flat fallback when grouped storage is empty.

### Changed

- **Email-templates settings tab — label consistency and grouping** (#439, #440). Every subject field is now labelled `(Subject)` (the abbreviated `(Sub)` variants in the Tech/Admin section are gone). The "Ticket Merged" template moves from the now-removed "Emails Sent to Client (System)" section into the main Client section next to Auto-Closed. UI-only — no option keys change, persistence is unaffected.

## [4.0.3] — 2026-05-25

### Added

- **v4.0.3 — Portal settings tab** (#471). New admin tab surfacing the previously-unreachable `swh_settings_portal` storage group: portal/admin theme pickers plus operator-configurable CSAT widget controls (`csat_enabled` bool, `csat_prompt`, `csat_thanks`, `csat_skip_label`). Previously the CSAT widget rendered with hardcoded strings and no way to disable it. When `csat_enabled` is false, no DOM is emitted and no AJAX surface is exposed — the close-success message renders alone.
- **v4.0.3 — Integrations settings tab** (#472). New admin tab surfacing the `swh_settings_integrations` storage group. Today: the inbound webhook shared secret (already in defaults, never had a UI). Placeholders preview what v4.1 (REST API keys), v4.2 (signed outbound webhooks), and v4.3 (Slack/Teams/Discord) will populate.

### Changed

- **v4.0.3 — Closes the v4.0.0 storage-group ↔ UI gap** (#470). v4.0.0 reorganized settings into 7 grouped options (#356) but the admin UI tabs were never updated to match — leaving two groups (`portal`, `integrations`) with no entry point. Net effect: features that the plugin advertised in docs had zero way to configure. v4.0.3 closes that gap. No data-shape changes; settings move from "implicit defaults" to "operator-tunable" without altering storage. Notifications tab remains deferred — that group is intentionally unseeded.

### Decisions deferred

- reCAPTCHA stays on the **Anti-Spam** tab. Moving it to **Integrations** was discussed but would have fragmented a cohesive panel (JS-driven row visibility tied to `spam_method`). Revisit in v4.1 alongside REST API key management, when a real "third-party credentials" pattern lands.

### Renumbered milestones

- v4.0.4 (was v4.0.3) — Milestone #25. Email-templates wording tweaks (#439, #440).
- v4.0.5 (was v4.0.4) — Milestone #26. Docs + dev-experience cleanup.

## [4.0.2] — 2026-05-25

### Added

- **v4.0.2 — Per-user default inbox view** (#437). Operator can pin any view (built-in `all` / `unassigned` / `my_open` / `high_priority` / `resolved_this_week`, or any personal/global saved view) as their personal default. On inbox load, the saved default is auto-selected before the first ticket fetch. UI: a small ☆/★ button appears next to each view link on hover; click toggles the default. Storage: per-user meta key `swh_default_view`. REST: new `PUT /swh/v1/views/default` endpoint, plus the existing `GET /swh/v1/views` response now carries `default` alongside `views`. Stale defaults (e.g. a deleted personal view) auto-resolve to null at read time so the system default takes over gracefully.

### Fixed

- **v4.0.2 — Drawer + bulk assignments did not send the assignee email** (RC follow-up to #434/#435). The assignee notification email was inlined in the legacy admin editor save path (`class-ticket-editor.php`), so although v4.0 surfaces correctly fired `do_action('swh_ticket_assigned', …)`, there was no listener — drawer and bulk reassignments were silent. Found while running the RC extended-test suite against the merged #463 fix. Fix: extract the email-dispatch into a new `swh_send_assignment_email( $ticket_id, $old, $new )` listener hooked to `swh_ticket_assigned`, and remove the inline send from the legacy editor (the existing `do_action` at the same site now routes through the same listener). Single dispatch path means every assignment trigger — legacy editor, drawer PATCH, bulk action, automatic assignment rule — sends the same email. RC-verified end-to-end: assigning ticket 901 to user 4 via `do_action('swh_ticket_assigned', …)` delivered `[TKT-0901] … Has Been Assigned to You` within 5 seconds.
- **v4.0.2 — Drawer had no unread-reply indicator** (#436). The `_swh_unread` post-meta flag is set by the reply paths (portal, webhook), but the v4.0 drawer never read it. Fix: `TicketController::get_item` now includes `unread: bool`; `DrawerHeader` renders an aria-labelled red dot before the ticket title when set; a 1.5s `setTimeout` after open dispatches `patchMeta({ mark_read: true })` so the operator sees the badge briefly before it auto-clears. PATCH endpoint accepts `mark_read: true` and intentionally does NOT bump the version counter (clearing read-state is a per-viewer concern, not a content change other clients reconcile against). New L2 contract test pins the no-bump semantics. Inbox-row unread highlighting is a follow-up — the legacy admin list already does this via `helpers.php`'s `_swh_unread` meta_query; the React inbox doesn't yet.
- **v4.0.2 — Webhook-inserted reply comments had no per-message author attribution** (#438). `swh_handle_inbound_email` already extracted the email address from `Name <email@example.com>` From headers, but discarded the display name and wrote the ticket-level `_ticket_name` + `_ticket_email` into `wp_insert_comment` as `comment_author` / `comment_author_email`. Visually fine for single-sender threads; broken for CC'd parties or forwarded mail, where every webhook-inserted message showed the same author. Fix: parse the display name alongside the address and use the parsed values (falling back to the bare address if no display name) when inserting the comment. Drawer's activity log now shows the actual reply author per message. The token-comparison invariant allow-list was updated to track the line-number shift caused by the parser change.

### Docs

- **v4.x roadmap — patch lane locked.** `docs/internal/release_v4.x.x_roadmap.md` now documents the agreed sequencing: v4.0.2 → v4.0.3 → v4.0.4 → comprehensive doc review (incl. GitHub Pages site) → finish deferred selector sweep (#458) → v4.1. Includes per-patch milestone links, scope hints, and rationale for the doc-review timing.

### Fixed

- **v4.0.2 — Drawer assignment was non-functional and assignment-rule matches showed as "Unassigned"** (#434, #435). Root cause was a two-meta-key split: v4.0.0's REST controllers (`TicketController`, `BulkController`, `InboxController`) wrote and read `_ticket_assigned`, while the rest of the plugin (assignment-rule writer in `swh_apply_assignment_rules()`, lifecycle action `swh_ticket_assigned`, assignee email dispatcher, legacy editor + list-table, admin list filters) used the canonical `_ticket_assigned_to`. Result: every drawer/inbox/bulk assignment write went to a key nobody else read, and every rule-driven assignment write went to a key the drawer ignored — so the drawer always rendered "Unassigned" and PATCHes never fired the assignee email. Fix: standardise on `_ticket_assigned_to` across the three REST controllers, fire `swh_ticket_assigned` from both the drawer PATCH and bulk-assign paths so the email + integrators trigger, and ship a one-shot upgrade migration (`class-installer.php`) that copies orphaned `_ticket_assigned` values to `_ticket_assigned_to` (only when the canonical key is empty, so manual reassignments aren't clobbered) and deletes the orphan rows. Also adds the missing UI: drawer's MetaRow now exposes a labelled Assignee SelectControl (was a read-only `#<id>` span) backed by a new `GET /swh/v1/technicians` endpoint that returns the assignable users (administrators + `swh_technician` role) scoped to `edit_posts`. RC-verifiable via `email_delivery_check.py` after a drawer assignment change. Adds L2 contract test `test_update_item_assignee_writes_canonical_meta_and_fires_action` regression-pinning the canonical write + lifecycle hook.

## [4.0.1] — 2026-05-25

### Fixed

- **Default email subject templates missing `[ ]` around `{ticket_id}` broke inbound webhook reply parsing** (#433). The inbound-email webhook (`swh_handle_inbound_email`) parses the target ticket UID via `/\[(TKT-\d+)\]/i` — it requires literal square brackets around the ticket id. 14 of 15 default subject templates in `swh_get_defaults()` shipped without those brackets (or with the id absent entirely), so when a client clicked "reply" on a notification email, the resulting inbound message had no parseable UID, the webhook returned `{"success": false, "error": "no_ticket_id"}`, and the reply silently never landed on the ticket. Defaults are now updated to wrap `[{ticket_id}]` in every transactional subject (client + tech templates, including the merged-ticket notification). Existing installs are NOT affected — the option-seeding pattern only seeds defaults if the option is absent. Surfaced end-to-end while building the RC extended-test suite.

### Added

- **Testing — E2E selector conversions, batch 5 (toast)** (PR 4.5 of the testing overhaul). Converts `test_57_toast_notifications` onto the semantic-selector helper library. The toast element already exposed `role="status"` + `aria-live="polite"` and the dismiss button already had `aria-label="Dismiss"` — pure test-side refactor with zero JSX changes. Tests now locate the toast via `get_by_role("status")` and dismiss via `get_by_role("button", name="Dismiss")` instead of `.swh-toast.swh-toast--visible` / `.swh-toast__dismiss` class selectors. The `--visible` modifier class is implicitly handled by Playwright's `wait_for(state="visible")` against the same locator. Remaining class-coupled tests (settings, canned responses, templates, dark-mode wrapper, a11y skip-links, SLA badge, alert banners) are queued for follow-up — most are pre-React PHP surfaces that need their own a11y companion patches and are lower-traffic than the inbox/drawer/portal flows already covered.
- **Testing — E2E selector conversions, batch 4 (inbox + drawer)** (PR 4.4 of the testing overhaul). Converts the two heaviest admin tests onto the semantic-selector helper library: `test_65_inbox_renders_and_switches_views` and `test_66_drawer_opens_and_edits_status`. Small JSX a11y patches: `LeftRail` view buttons now expose `aria-current="page"` on the active view (replaces the `.is-active` CSS class as the active-state hook — and is the correct ARIA pattern for current navigation item); `DrawerHeader` ticket title is promoted from `<div>` to `<h2>` so `get_by_role("heading", level=2)` works (both an a11y improvement and the missing piece for `drawer_title()`). New helpers `inbox_title()`, `inbox_view_link(name)`, `inbox_active_view()`. Drawer status edit now uses `drawer_status_select()` (labelled SelectControl) instead of nth-child select hunting. The remaining `.swh-inbox-{list,empty,loading,error}` selector cluster in test_65 is kept as a documented exception — these are mutually-exclusive UI states with no a11y equivalent, scoped to `inbox_root` for safety. Next sweep (4.5) covers settings/canned/templates surfaces; tracked in `docs/internal/test-coverage-matrix.md`.
- **Testing — E2E selector conversions, batch 3 (portal v2 sweep)** (PR 4.3 of the testing overhaul). Converts the remaining portal-class-coupled tests onto the helper library: `test_29_humanized_timestamps` (uses semantic `<time datetime>` scoped to `portal_thread`), `test_30_resolved_cta_layout`, `test_33_csat_prompt` (both close-via-menu flows), `test_63_csat_focus_management`, and `test_72_portal_v2_renders_conversation` (the heaviest — full conversion to `portal_root`, `portal_title`, `portal_message("customer")`, `portal_reply_box`, `portal_send_button`, `portal_menu_trigger`). New helper `portal_title()` returns the portal header h1 by role. Only one class selector remains in test_72 (`.swh-portal-v2-menu` for `[hidden]` visibility check) — documented inline; there's no a11y equivalent for "is this menu currently displayed via the HTML hidden attribute." Net result: zero `.swh-portal-v2-menu-trigger` references and zero `.swh-portal-v2-menu button[data-action]` references in the test file. Next sweep (4.4) covers admin-list, dark mode, settings tests; tracked in `docs/internal/test-coverage-matrix.md`.
- **Testing — E2E selector conversions, batch 2 (portal v2)** (PR 4.2 of the testing overhaul). Converts `test_08_client_portal` and `test_10_portal_close_reopen` — the two most class-coupled tests in the portal-v2 surface — onto the semantic-selector helper library. Small PHP a11y/test-hook patch: portal-v2 wrapper gains `id="swh-portal-app"` (matches the documented `portal_root()` anchor); message bubble template gains `data-message-author="customer|staff"` (explicit, stable hook documented to E2E callers). Helper additions: `portal_thread()`, `portal_message(author)`, `portal_menu_trigger()`; existing `portal_send_button()` realigned to the actual aria-label `"Send reply"`. Tests now locate the reply textarea by `aria-label="Reply"`, the send button by `aria-label="Send reply"`, the ⋮ menu by `aria-label="Ticket actions"`, and the Close/Reopen actions by their visible text — zero new class selectors. The remaining `.swh-msg-sent` assertion (verifying the optimistic-send-confirmed UI state) is kept as a documented exception: there's no a11y equivalent for "the fetch resolved successfully and the bubble visually flipped to sent." Next sweeps (4.3–4.5) convert the remaining ~70 sections; tracked in `docs/internal/test-coverage-matrix.md`.
- **Testing — E2E selector conversions, batch 1** (PR 4.1 of the testing overhaul). Proof-of-pattern conversion for the three flaky tests called out in #421 — `test_28_cleanup`, `test_69_bulk_bar_closes_selected_tickets`, `test_70_create_personal_view_and_switch` — plus a small a11y companion patch that unlocks the semantic anchors the conversions rely on. JSX: `BulkBar` gains `role="toolbar" aria-label="Bulk actions"` (one new attribute pair, no behavior change, +a11y); the selector-helper library's `drawer()` is updated to match the existing dynamic `Ticket #N` aria-label via regex (no JSX change required). Tests: `test_69` now uses `inbox_root()`, `ticket_row()`, `bulk_bar()` and locates the per-row select control by its existing aria-label instead of `.swh-inbox-row-check`; `test_28` replaces brittle WP admin-list row-action clicking (susceptible to the WP "Are you sure?" confirmation page — root cause of the #421 flake) with `wp post delete` via wp-cli; `test_70` keeps its early-return assertion (saved-views deferred to v4.1). Closes #421 at the test level. Next sweeps (4.2–4.5) convert the remaining ~70 sections; tracked in `docs/internal/test-coverage-matrix.md`.
- **Testing — Visual regression infrastructure** (PR 5 of the testing overhaul). `testing/visual/visual_regression.py` — Playwright captures full-page screenshots of admin surfaces (inbox, settings, reports) across light / dark / auto themes, then pixel-diffs against committed baselines via Pillow with explicit tolerance knobs (`PIXEL_TOLERANCE`, `MISMATCH_RATIO`). New Makefile targets: `make visual` (diff against baselines), `make visual-update` (regenerate baselines after intentional UI changes). Diff PNGs land under `testing/visual/diffs/<timestamp>/` (gitignored) for human review. `testing/visual/README.md` documents the operator workflow end-to-end. **Caveat:** baselines themselves are NOT committed in this PR — the operator captures from their known-good dev environment in a follow-up. CI integration deferred until we have a deterministic Docker-stack initial state.
- **Docs — PR 4.x selector-sweep paused at PR 4.5**, with deferred follow-ups for ~10 legacy-PHP tests documented in the test-coverage matrix. Tracking issue #458. All interactive elements across React/interactive surfaces (inbox, drawer, portal v2, bulk bar, toast) now use semantic selectors; remaining class usage is structural scoping wrappers only.
- **Testing — wp-phpunit integration harness foundation** (PR 7 of the testing overhaul, foundation only). Adds `wp-phpunit/wp-phpunit` + `yoast/phpunit-polyfills` as dev deps. New `bin/install-wp-tests.sh` provisions the throwaway test DB and writes `tests/Integration/wp-tests-config.php` (gitignored). New `tests/Integration/bootstrap.php` boots the real WP test framework and activates the plugin. New `phpunit-integration.xml` config restricts the new suite to `tests/Integration/` and the `integration` group. Proof-of-pattern test `tests/Integration/Rest/PermissionCallbackTest.php` (4 cases) exercises the real WP REST server + real auth state to cover what mocked Contract tests structurally can't reach: anonymous-rejection, admin-pass, subscriber-rejection, and the load-bearing restricted-to-assigned per-ticket gate. New `make test-integration` Makefile target with clear pre-flight diagnostics. `tests/Integration/README.md` documents operator setup, writing-tests pattern, and the rationale for keeping mocked + integration tests both. CI integration deferred to PR 7.3 (needs a MySQL service container in the workflow). Closes the PR 7 foundation; sweeps tracked in `docs/internal/test-coverage-matrix.md`.

### Changed

- **Testing — bump pytest-playwright 0.7.2 → 0.8.0** (Dependabot PR #408). Picks up `expect.soft()` soft-assertion support, useful for the upcoming E2E rewrite (PR 4 of the testing overhaul). No behavior change for existing tests.

### Added

- **Testing — Diff-aware matrix-lint enforcement** (PR 6 of the testing overhaul). `bin/matrix-lint.php` gains `--diff[=<file>]` mode that compares the matrix at HEAD against a base version and fails if any feature row regressed (a cell went from ✓ / P / N/A to R) without rationale. New `make matrix-lint-diff` Makefile target prepares the base from a git ref (default `origin/main`). New CI job "Test Coverage Matrix — no regression" runs on every PR, validates the base ref against a strict allowlist (defense-in-depth vs. workflow-injection), and fails the PR on any regression. PR template grows a "Test coverage matrix" section with the five-checkbox L1–L5 block — every PR touching a feature surface confirms each layer is green or N/A. Closes PR 6 of the migration plan in `docs/internal/test-coverage-matrix.md`.
- **Testing — RC extended-test suite (manual sign-off runbook + 6 helper scripts)** (PR #432). Introduces `docs/internal/extended-test-plan.md`, a 60–90 minute release-candidate sign-off runbook that runs **once per RC, before promoting dev → main**, against the real dev WordPress instance and a real mail server (covering surfaces that Docker E2E can't simulate: live SMTP, IMAP, the inbound webhook end-to-end, multi-technician permission interactions, dark-mode visual sanity, accessibility spot-check). Six Python helpers under `testing/extended/`, all proven on real infrastructure end-to-end before merge: (1) `verify_env.py` — runs section 0 of the runbook in ~5s; checks every required var, cross-references password lengths against the literal file values (catches shell-expansion mangling — the `$1xyz` → empty issue), and live-tests SSH+wp-cli + IMAP + SMTP login; (2) `webhook_roundtrip.py` — SMTP-send → poll WP for the helpdesk_reply comment via wp-cli over SSH (the plugin's CPT isn't exposed via standard REST), with smart `sender_mismatch` mode that auto-picks a different authorized alias (since the SMTP relay rejects external MAIL FROM at 5.7.1), plus `no_ticket_id` and `ticket_not_found` rejection modes; (3) `webhook_direct_post.py` — bypasses SMTP to exercise auth-layer rejections (bad bearer, missing Authorization header, malformed payloads); (4) `email_delivery_check.py` — IMAP-polls for an outbound email matching recipient + subject + body substrings; (5) `cleanup.py` — deletes `CLAUDE-TEST-*` tickets and expunges matching mailbox messages, with `--dry-run` for safety; (6) `capture_screenshots.py` — Playwright-driven Chromium captures of inbox / settings / reports across light / dark / auto themes for the visual sanity section. Shared `_wpcli.py` helper provides the SSH→docker→wp-cli bridge with JSON-prefix stripping for the PHP deprecation noise the wp-cli phar emits. All credentials and identifiers read exclusively from environment (`~/.claude/dev-secrets.env` + three new vars: `SSH_TARGET`, `WP_CONTAINER`, `WP_PATH`); zero defaults, no hardcoded hostnames or addresses in source. New Makefile targets: `make test-extended-verify`, `make test-extended-cleanup`, `make test-extended`. Per-push CI gates are unchanged — the extended suite is human-paced and explicitly NOT wired to CI. Test tickets use a UTC-timestamped prefix (`CLAUDE-TEST-WEBHOOK-YYYYMMDD-HHMMSS-<random>`) so concurrent operators don't collide. Documents the dev-secrets.env single-vs-double-quote gotcha in both the runbook and the README.
- **Testing — Email-per-trigger invariant** (PR #431). Adds `EmailTriggerInvariantTest` (4 cases) — a source-scan invariant covering gap #20. For each of the ~30 `swh_send_email()` call sites in the plugin, asserts: (i) the subject/body keys are a matched pair (same stem between `swh_em_` and the `_sub`/`_body` suffix); (ii) every used template stem is on the documented allow-list; (iii) every documented stem has at least one call site (catches both phantom templates and forgotten triggers); (iv) no call site uses a dynamic (variable) key that would defeat static analysis. The invariant immediately caught a phantom `admin_status` template that had been listed but never had a call site — removed. Cheaper than 15 fixture-heavy tests and catches the same regression class. Closes the last remaining Tier 1 matrix gap.
- **Testing — Tier 1 domain backfill (rate limiting, CSAT, public-API hooks, capabilities, anti-spam, cron, merge)** (PR #430). Adds 58 tests across seven Tier 1 gaps the v4.0.0 suite couldn't catch: (a) `RateLimitTest` + `RateLimitKeyInvariantTest` pin the per-action-per-ticket key shape (`portal_close_<id>`, `portal_reopen_<id>`, `portal_reply_<id>`) and the boundary semantics — close-then-immediate-reopen must not be blocked, the v3.x regression that motivated per-action keys; (b) `CsatAjaxTest` replaces the v4.0.0 stub with a real end-to-end test of `swh_submit_csat_ajax()` covering nonce, rating bounds, ticket existence, post-type guard, non-closed-status guard, and the happy path that writes `_ticket_csat` AND fires `swh_csat_submitted(int, int)`; (c) `PublicHookContractInvariantTest` source-scans for every documented action and filter with a typo-guard for near-miss hook names. (d) `CapabilityMatrixTest` (10 cases) — REST permissions_check global + per-ticket gate (the load-bearing restricted-to-assigned escape hatch), plus `swh_restrict_ticket_edit` admin gate that wp_die(403)s technicians trying to access non-assigned tickets; (e) `AntispamTest` (14 cases) — honeypot, reCAPTCHA v2, reCAPTCHA Enterprise (missing creds, below/above threshold, invalid token), and Cloudflare Turnstile — every path including fail-closed on non-JSON / network errors; (f) `CronTest` (12 cases) — all four cron events × early-return guards (days=0 kill switch, lock-held short-circuit), lock acquire/release, distinct lock keys across crons; (g) `MergeTicketsTest` (5 cases) — invalid source/target rejected, happy path moves comments + merges attachments + inserts cross-reference internal notes on both ends + closes source + emails client, empty source doesn't overwrite target. Reply + assignment lifecycle hooks were already covered by the existing `LifecycleActionsTest`. Flips 11+ matrix cells from R → ✓; Tier 1 coverage jumps from 16 ✓ → 18 ✓ across 6 new feature rows.
- **Testing — REST envelope contract tests** (PR #429). Adds `tests/Unit/Rest/Contracts/` with 17 contract assertions pinning the exact JSON envelope shape returned by every documented `swh/v1` REST endpoint × every error/success path. Catches the v4.0.0-class 409 regression by asserting the full envelope (`error`, `current.id`, `current.version` reflecting server state) rather than just the status code. Documents the REST envelope contract in `api-contract.md`. Shared `EnvelopeShape` helper asserts presence of required keys AND absence of undocumented ones so silent shape drift is impossible. Flips four L2 matrix cells from R → ✓ (Optimistic locking, Bulk actions, Views CRUD). `tests/Integration/` reserved with a stub README for the future `wp-phpunit` harness (PR 7 in the migration plan).
- **Testing — Coverage Matrix and L1 Invariants** (PR #428). Introduces `docs/internal/test-coverage-matrix.md` as the auditable source of truth for test coverage across five layers (L1 invariants, L2 REST/AJAX contracts, L3 domain, L4 behavior E2E, L5 visual regression). Adds the first six executable invariant tests under `tests/Unit/Invariants/` pinning load-bearing rules from `design-document.md` (status mutator, mail dispatcher, token comparison, file proxy guards, comment isolation). New tooling: `bin/matrix-lint.php` reports current matrix coverage; `make test-invariants` and `make matrix-lint` targets wired into the local gate. Codifies the E2E selector policy in `testing-guide.md`. Closes #421.

---

## [4.0.0] — 2026-05-23

Theme: **Workflow-First Admin.** A structural rewrite of the operator
experience — inbox-style triage, slide-over drawer, ⌘K palette, saved
views, sticky bulk-action bar — plus the foundation that makes future
v4.x integrations cleaner (PSR-4, grouped settings schema, optimistic
concurrency, pre-3.0 filter deprecations). Closes milestone [#18](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/18) (12/12).

### Breaking

- Raised PHP minimum to 8.0 (was 7.4). Hosts on PHP 7.4 must stay on the v3.6 LTS branch. (#357)
- Raised WordPress minimum to 6.0 (was 5.3). Unlocks `@wordpress/components` as a guaranteed external for the Admin UX rewrite. (#358)
- Settings schema migrated from flat `swh_<key>` options to grouped `swh_settings_<group>` arrays (7 groups: general, email, portal, notifications, tools, routing, integrations). Automatic one-shot upgrade on `plugins_loaded` priority 5 (idempotent via `swh_db_version`). Legacy flat options remain in storage but become dead reads (forward-only; v3.6 LTS branch remains the supported sidegrade for PHP 7.4 hosts). (#356)

### Added

- **Admin Tickets inbox shell (#349):** Dense list + saved-view left rail + filter chips. Replaces the WP_List_Table page. `_swh_last_activity_at` post meta written on every status change and reply insert (used by inbox sort). REST endpoint `GET /swh/v1/tickets` with 4 default views (unassigned, my\_open, high\_priority, resolved\_this\_week), pagination, and `found_posts` total.
- **Quick-reply drawer (#350):** Slide-over drawer with workhorse meta (status/priority/assignee/tags), full thread, composer with internal-note + mark-resolved toggles, draft persistence (localStorage, 7d), cmd-Enter send. Replaces row → legacy edit navigation.
- **Optimistic concurrency (#350):** Every ticket mutation bumps `_swh_version` post meta. REST PATCH + reply endpoints check the client-supplied expected version; mismatch returns 409 with current state. ConflictBanner surfaces in the drawer with draft preserved.
- **⌘K command palette (#351):** Cmd+K / Ctrl+K — fuzzy search over tickets, saved views, settings pages; bulk commands group when rows are selected. Uses @wordpress/components Modal.
- **Saved views CRUD (#352):** Personal views (per user) + global views (admin-managed) with management UI in the inbox left rail and at Settings → Helpdesk → Views. Defaults (Unassigned, My Open, High priority, Resolved this week) ship pre-baked; technicians add their own. New REST endpoints: `GET /swh/v1/views`, `POST /swh/v1/views`, `PATCH /swh/v1/views/{id}`, `DELETE /swh/v1/views/{id}`.
- **Sticky bulk-action bar (#353):** Appears above the ticket list when ≥1 row is selected. Status + Priority + Close. 200-ticket cap. Per-ticket optimistic concurrency via `POST /swh/v1/bulk-action`; conflicts surface in an alert with the skipped ticket IDs. New REST endpoint: `GET /swh/v1/palette/suggest`.
- **Opt-in Violet theme (#355):** Setting at Settings → General → Appearance (default off). Vivid Violet palette (violet-800 primary), Geist Sans typography self-hosted under OFL 1.1, tighter spacing, softer shadows. Emails unchanged.
- **Reports redesign (#359):** Time-range selector (7d / 30d / 90d / YTD / Custom), period-over-period comparison deltas on every report card, click-to-drill-down navigation from chart segments to the inbox filtered by status + date range.
- **`swh_set_option( $group, $key, $value )` helper (#356):** Write-side mirror of `swh_get_option()`.
- **`\SWH\Settings\Migration` class (#356):** One-shot schema upgrade routine, idempotent, runs on `plugins_loaded` p5. Exposes `Migration::group_for( $flat_key )` for write-side dispatch.

### Changed

- **Portal v2 (#354):** Client-facing portal rewritten to a messenger-style conversation-first layout. Thread leads visually with customer/staff bubbles; composer pinned to the bottom; ticket-level actions (close, reopen, CSAT) consolidated into a single `⋮` header menu. Server-rendered PHP + ~3KB vanilla JS for AJAX/auto-scroll/menu. Same URLs, same endpoints, same features.

### Deprecated

- 8 pre-3.0 filter hooks are now wrapped in `swh_apply_deprecated_filter()` and emit `_doing_it_wrong` in `WP_DEBUG` environments. Listeners still fire (back-compat preserved). Earliest removal: v4.2. (#360)
  - `swh_ticket_statuses`, `swh_ticket_priorities` (`includes/helpers.php`)
  - `swh_allowed_file_types` — 2 call sites (`frontend/class-shortcode.php`)
  - `swh_submission_data` (`frontend/class-shortcode.php`)
  - `swh_autoclose_threshold` (`includes/class-cron.php`)
  - `swh_rate_limit_ttl` (`includes/helpers.php`)
  - `swh_parse_template`, `swh_email_headers` (`includes/class-email.php`)

  `swh_sla_open_statuses` is post-3.0 and remains supported.

### Internal

- Updated `.vscode/settings.json` `intelephense.environment.phpVersion` 7.4.0 → 8.0.0 (carry-forward from PR #410; file is gitignored — local-only change).
- Add v3.7.0 ship audit doc (`docs/internal/v3.7.0-ship-audit.md`) — definitive record that all 7 v3.7.0 phases landed via PR #400, plus housekeeping closure of the 7 milestone issues and milestone #22 that PR #400 left open (PR #406).
- Bump `requests` 2.33.1 → 2.34.2 in `testing/requirements.txt` (Dependabot, PR #404).
- Bump `@wordpress/scripts` 32.1.0 → 32.2.0 (Dependabot, PR #405); rebuilt `simple-wp-helpdesk/assets/dist/`.
- Established `docs/internal/` as the single-source-of-truth doc set for developers and AI agents: README index, design-document (with 17 numbered load-bearing invariants), coding-guide, testing-guide, security-model, api-contract, data-dictionary, config-reference, design-guide. Every doc carries an Update protocol so the set stays current as part of normal PR flow.
- Trimmed `CLAUDE.md` from 398 to 133 lines — now a routing index + hot-invariants list + dev environment reference. Sprawling sections (Common Pitfalls, full test taxonomy, security conventions, release process) replaced with pointers to the corresponding internal doc.

---

## [3.7.0] — 2026-05-13

Theme: **v4 Foundations.** Non-breaking groundwork that de-risks v4.0 / v4.1. No PHP/WP minimum bumps, no schema changes, no UX changes — primitives that v4.x features will build on, plus a runway for integrators to adopt new APIs before v4.0 lands. Closes milestone [#22](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/22) (7/7).

### Added

- **Lifecycle action hooks (#361):** 7 new integrator-facing actions fired by ticket state transitions — `swh_ticket_replied`, `swh_ticket_status_changed`, `swh_ticket_closed`, `swh_ticket_reopened`, `swh_ticket_assigned`, `swh_sla_breached`, `swh_csat_submitted`. Documented in `docs/developer/hooks.md`. Moved earlier from v4.1 so they get a release to settle before REST/webhook consumers ship on top.
- **`swh_get_option( $group, $key, $default )` helper (#391):** Read-through wrapper that takes the v4.0 settings-schema-split signature now while still backed by the monolithic `swh_options` array. v4.0 only changes the implementation — callers stay stable.
- **Deprecation helpers (#393):** `swh_apply_deprecated_filter()` and `swh_do_deprecated_action()` wrap WP's `apply_filters_deprecated()` / `do_action_deprecated()` with an `SWH x.y` version-tag format. Mechanises the v4.0 hook deprecations ([#360](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/360)).
- **PSR-4 autoload for plugin classes (#394):** Additive — existing `require_once` calls still work. Composer `autoload` block plus `SWH\Email\Mailer` PoC class under `simple-wp-helpdesk/src/`. Cleaner bootstrap for the ~10 new classes v4.0/v4.1 will add.
- **`@wordpress/scripts` build system (#390):** JS source under `simple-wp-helpdesk/assets/src/`; toast notifications ported to ES modules and built to `assets/dist/toast.js`. Picks the build/state pattern before v4.0 inbox/command-palette/saved-views work starts. Existing `swh-admin.js` / `swh-frontend.js` untouched.
- **Component inventory + v4.0 gap manifest (#392):** `docs/internal/component-inventory.md` documents every existing CSS/JS primitive (badges, panels, tokens, swhToast, empty-state, etc.) and lists the gaps v4.0 UI work needs to close. Prevents ad-hoc primitives and indigo-refresh fights.
- **Performance baseline (#395):** `docs/internal/performance-baseline.md` captures wall-clock numbers at COUNT=100; `make bench` target added for repeatable measurement. Gives v4.0 inbox a regression target ("500+ tickets without lag").
- **Plan + discovery docs:** `docs/internal/plan_v3.7.0.md`, `docs/internal/v3.7.0-discovery.md`, `docs/internal/v3.7.0-issue-snapshots/`, `docs/developer/hooks.md`, `docs/developer/deprecations.md`, `docs/internal/js-architecture.md`.
- GitHub Pages documentation site at `https://seanmousseau.github.io/Simple-WP-Helpdesk/` (Jekyll, just-the-docs theme).
- New docs pages: Shortcode Reference, Hooks Reference (all 9 filters + 2 actions with examples), Troubleshooting / FAQ.
- Plugins page action links: **Settings** and **Docs** shortcuts shown under the plugin name.
- `docs/internal/lessons-learned.md` — consolidated post-mortems and gotchas from CLAUDE.md, memory, and session learnings (CI cache eviction, WP admin pointer interception, Authorization-header stripping in Docker, etc.).

### Changed

- `swh_set_ticket_status()` helper centralises ticket status transitions and fires the new lifecycle actions consistently across the editor, portal, and cron paths.
- `CLAUDE.md` repo-structure section clarified — plugin lives in `simple-wp-helpdesk/` subdir.
- `README.md` updated to v3.5.0 with expanded features list reflecting all v3.x additions.
- `docs/configuration.md` corrected from 6 tabs to 8; Canned Responses and Templates tabs documented.
- `docs/development.md` updated repo structure, removed stale bootstrap size note, added Inbound Email Webhook section.
- `docs/usage.md` expanded technician section (categories, SLA, merge, reporting, canned responses).
- `docs/security.md` additions: token empty-value rejection, inbound webhook Bearer auth, MIME filter hook.
- **Dev:** Bumped `playwright` from 1.58.0 to 1.59.0 in `testing/requirements.txt` (#389).

### Fixed

- **PHPStan null-guard on assignee email send (`admin/class-ticket-editor.php`):** `get_userdata()` returns `WP_User|false`, but the assignment-notification block read `$assignee_user->user_email` without a null-guard. Surfaced as a CI failure on PHP 8.1+ after the GitHub Actions composer cache evicted (cache miss → fresh install pulled an updated WP stubs version). Pre-existing code from v3.4; never reached in practice because callers always pass a valid user ID.

### Known follow-ups (deferred to a later release)

- `@wordpress/scripts` toolchain reports 5 transitive build-time advisories (no runtime impact). Stuck behind upstream — `@wordpress/scripts` 32.1.0 is latest and still pins older `serialize-javascript`, `minimatch`, and `webpack-dev-server`. Will resolve when the upstream maintainer publishes a release with bumped transitives. Full assessment in `docs/internal/lessons-learned.md` → *Dependabot: `@wordpress/scripts` transitive advisories (2026-05-13)*. Dependabot config updated to watch `@wordpress/scripts` and ignore the stuck transitives.
- `make bench` baseline currently captured at COUNT=100; 500/1000 runs deferred until v4.0 inbox work needs them.

---

## [3.6.0] — 2026-05-06

Theme: **Dark Mode Expansion & Accessibility Round 2.** Pure UX/a11y polish — no architectural changes, no new meta keys, no schema bumps. Same PHP 7.4 / WP 5.3 minimums.

### Added

- **Admin dark mode (#339):** SWH admin pages now follow the user's WordPress `admin_color` scheme. New `swh_admin_color_is_dark()` helper in `includes/helpers.php` returns `true` for the `midnight`, `modern`, and `ectoplasm` schemes; admin pages add `swh-admin-theme-dark` to `<body>` so token overrides under `body.swh-helpdesk-admin.swh-admin-theme-dark` activate. Admin component overrides for `.swh-panel`, `.swh-conversation`, `.swh-meta-box`, `.swh-kpi-card`, `.swh-empty-state`, `.swh-canned-item`, and `.swh-rule-row` live in `swh-admin.css`.
- **Email HTML dark mode (#340):** `swh_wrap_html_email()` now emits `<meta name="color-scheme" content="light dark">` plus a `@media (prefers-color-scheme: dark)` block. Apple Mail and iOS Mail honour it; webmail clients ignore it gracefully.
- **Skip-to-content link on Reports + Settings (#341):** Keyboard users can now bypass the WP admin chrome and land directly on the page main region.
- **CSAT focus management (#342):** Submitting a satisfaction rating moves focus to the success message. Pressing **Esc** dismisses the widget and returns focus to the trigger.
- **Heading hierarchy audit + shared empty-state helper (#343):** New `swh_render_empty_state( $title, $desc, $icon_svg_path, $heading_level = 'h2' )` helper in `includes/helpers.php` standardises every "nothing here" surface. Heading levels audited across portal and admin so each page has a single `h1` and no skipped levels.
- **`aria-live` announcements (#344):** SLA badge transitions and KPI card updates now announce to screen readers via `aria-live="polite"`.
- **`:focus-visible` focus rings (#345):** Mouse clicks no longer leave a focus ring; keyboard navigation still gets the high-contrast `--swh-color-focus` outline.
- **Portal token-expiry focus (#346):** When a portal token has expired, focus moves to the lookup form so screen-reader users know where to recover.
- **Touch targets ≥44×44 (#347):** CSAT stars, the merge-ticket toggle, and admin status filter chips now meet WCAG 2.5.5 AA. Achieved via transparent `::before` hit-zones on the merge toggle (preserves WP `.button` visual size) and `min-height: 44px` flex pills on filter chips.
- **Internal-note contrast bump (#348):** AAA contrast on amber background.
- **E2E sections 59–64:** `admin_dark_mode`, `email_color_scheme`, `skip_to_content`, `focus_visible_rings`, `csat_focus_management`, `aria_live_announcements`.

### Changed

- **`swh-admin.css`:** New `body.swh-helpdesk-admin.swh-admin-theme-dark` overrides block (15 selectors). All literal hex values on SWH-prefixed selectors were already routed through the `--swh-color-*` token system in v3.5.0; v3.6.0 adds dark-mode coverage on top of that token graph.
- **`swh-shared.css`:** New `body.swh-helpdesk-admin.swh-admin-theme-dark` token overrides block, parallel to the existing `prefers-color-scheme: dark` block.

### Fixed

- Internal-note text contrast on amber background bumped from AA (4.5:1) to AAA (7:1).

---

## [3.5.0] — 2026-04-23

### Added

- **Unified badge system (#329, #330):** Single `.swh-badge` base class and `.swh-badge-{slug}` modifier classes defined in `swh-shared.css`. All admin and editor badge rendering now uses `sanitize_title($status)` → CSS modifier, eliminating hardcoded hex colours.
- **Design token scales (#331):** Shadow (`--swh-shadow-sm/md/lg`), z-index (`--swh-z-base/dropdown/modal/toast`), and easing (`--swh-ease-out/in-out`) token scales added to `swh-shared.css`.
- **Toast notification component (#333):** Reusable `swhToast(message, type, duration)` JS function and `.swh-toast` CSS component added to `swh-admin.js` / `swh-admin.css`. Auto-dismisses after 4 s; accessible dismiss button; three variants (success/error/info).
- **Settings save toast (#334):** Settings save now shows a toast notification instead of the legacy WP admin notice. URL param cleaned via `history.replaceState()` to prevent re-trigger on refresh.
- **Reports skeleton loaders (#332):** KPI cards and chart canvases display shimmer skeleton placeholders while AJAX fetches are in flight.
- **`aria-busy` coverage (#335):** KPI grid and chart cards expose `aria-busy="true"` during loading and `aria-busy="false"` once data has populated.
- **Focus return on error (#336):** `swh-frontend.js` now focuses the first `.swh-alert-error` element on DOMContentLoaded so screen-reader and keyboard users land on the error message immediately.
- **Interactive hover feedback (#337):** Admin ticket list rows gain a background-colour hover transition; row action links and badges gain visible focus rings.
- **`prefers-reduced-motion` safeguard (#337):** Global `@media (prefers-reduced-motion: reduce)` block in `swh-shared.css` suppresses all animations and transitions for users who have opted in to reduced motion.
- **E2E tests for v3.5.0 UX (sections 57–58):** Section 57 covers toast appearance, auto-dismiss, and × button; section 58 covers Reports page skeleton loaders and `aria-busy` state.

### Changed

- **`swh-frontend.css`:** Duplicate `.swh-badge` block removed — styles now inherited from `swh-shared.css`.
- **Dev:** Pinned `doctrine/instantiator` to `^1.5` in `composer.json` to maintain PHP 8.2 compatibility (2.1.0 requires PHP ≥ 8.4).

---

## [3.4.1] — 2026-04-20

### Added

- **Frontend Portal Theme setting (#327):** New option in Settings → General lets site owners choose between "Auto (follows system dark/light mode)" and "Force light mode". When set to light, outputs `data-swh-theme="light"` on `.swh-helpdesk-wrapper`, activating the v3.4.0 CSS escape hatch without requiring template edits.

### Fixed

- **`make e2e-docker` teardown (#325):** Trap now uses `$(CURDIR)/docker-compose.test.yml` (absolute path) so Docker containers are reliably torn down when `make` is invoked from the OneDrive alias working directory.
- **KPI transient bypass (#326):** `swh_report_kpi_data()` now checks the `swh_report_avg_resolution_time` and `swh_report_first_response_time` transients before calling the sub-functions, eliminating redundant SQL queries on a KPI cache miss.

---

## [3.4.0] — 2026-04-20

### Added

- **Frontend dark mode (#321):** `@media (prefers-color-scheme: dark)` token overrides in `swh-shared.css` using slate palette (slate-900 bg, indigo-400 primary). `[data-swh-theme="light"]` escape hatch on `.swh-helpdesk-wrapper` for sites that manage their own theme.
- **Consistent empty states (#319):** Shared `.swh-empty-state` component (icon + title + desc) applied to My Tickets portal, admin ticket list, canned responses, and assignment rules.
- **Portal card layout (#320):** My Tickets dashboard replaced `<table>` with `.swh-ticket-card-list` flex card layout. Cards show status badge, title, meta line, and accessible "View →" CTA. Unread tickets get a primary-colour left border accent.
- **Email template visual branding (#317):** `swh_wrap_html_email()` now renders a branded header band (primary colour `#0073aa`, optional logo, site name) and a footer note. New `swh_email_logo_url` option (Email Templates tab) for a custom logo URL.
- **Reporting KPI cards (#318):** Four stat cards above the chart grid on the Reports page: Total Tickets, Open Tickets, Avg. Resolution (30d), Avg. First Response (30d). Populated via AJAX on page load (`type=kpi`). 4-col grid, responsive to 2-col at 782px and 1-col at 480px.
- **Settings visual hierarchy (#323):** Dashicons added to all 8 settings tab buttons. General tab reorganised into three labelled fieldsets (Ticket Workflow / Auto-Close / File Uploads). Danger Zone uses `.swh-danger-zone` CSS class instead of inline styles.
- **Ticket editor side panel (#322):** Meta box content reorganised into `.swh-ticket-panel` with three `.swh-panel-group` sections (Ticket / Client / Assignment). Status colour dot updates dynamically via JS. `.swh-select` class added to all selects.
- **New CSS tokens (#321):** `--swh-color-warning` (#d97706), `--swh-color-danger-surface` (#fef2f2).

### Changed

- **Pre-push hook (#313):** Docker is now required — no host PHP fallback. Push aborts if Docker is unavailable.
- **`swh_email_logo_url` default:** Empty string (falls back to `get_site_icon_url(48)` at send time).

### Removed

- **Claude Code Review CI workflow (#312):** Removed `.github/workflows/claude-code-review.yml`; CodeRabbit review is now invoked manually via `/review` on the PR.

---

## [3.3.0] — 2026-04-20

### Added

- **RTL stylesheet (`assets/swh-rtl.css`) (#125):** Directional overrides (text-align, border sides, flex-direction, direction) for all LTR-specific layout rules. Loaded conditionally via `is_rtl()` alongside `swh-frontend.css`.
- **WCAG 2.2 AA heading hierarchy (#170):** Added `screen-reader-text` h2 landmark to the submission form. Fixed heading skip (h2→h4) in close-ticket CTA — changed to h3.
- **Responsive breakpoints (#251):** `@media (max-width: 782px)` and `(max-width: 600px)` blocks added to `swh-admin.css` for ticket list, meta boxes, and settings. `@media (max-width: 480px)` added to `swh-frontend.css` for form, drop zone, and buttons.
- **Empty-state placeholders for report charts (#254):** Status and trend charts now show a "No ticket data yet" message instead of an invisible/broken canvas when the dataset is empty.
- **Styled file input (#255):** Removed inline `style="padding:5px"` from file inputs — styling now driven by CSS class.
- **Real upload progress bar (#256):** File attachment upload now uses `XMLHttpRequest.upload.onprogress` to fill a determinate progress bar. Replaces the previous indeterminate indicator.
- **Unsaved-changes warning (#257):** Settings form now warns with a browser `beforeunload` dialog when navigating away with unsaved changes. Dirty state cleared on form submit.
- **Semantic CSS conversation bubbles (#253):** Ticket editor conversation bubbles now use `.swh-bubble`, `.swh-bubble-note`, `.swh-bubble-user`, `.swh-bubble-tech` classes for visual distinction between internal notes and public replies, replacing all inline styles.
- **CSS design token extensions (#250):** Added `--swh-color-surface`, `--swh-color-focus`, `--swh-color-success-accent`, `--swh-color-text-secondary`, `--swh-color-bg-highlight`, `--swh-color-track` to `swh-shared.css`.

### Changed

- Hard-coded hex color values in `swh-admin.css` and `swh-frontend.css` replaced with semantic design tokens from `swh-shared.css`.
- My Tickets h3 heading inline style (`margin-top:0`) replaced with `.swh-my-tickets-heading` CSS class.
- `SWH_VERSION` bumped to `3.3.0`.

### Fixed

- AJAX network errors in ticket editor merge form now display a visible error message instead of failing silently (#252).

---

## [3.2.0] — 2026-04-20

### Added

- **`make test-docker` — Docker-based full gate (#292):** Runs lint, PHPCS, PHPStan, PHPUnit, and Semgrep inside the `phptest` container. No host PHP or semgrep installation required.
- **`make e2e-docker` — fully self-contained E2E (#294):** Spins up the Docker test stack, waits for WordPress, runs setup, executes the full Playwright suite with MailHog, then tears down — all in one command.
- **`make coverage` — PHPUnit coverage report (#301):** Generates `coverage.xml` (Clover format) using pcov. Paired with new `coverage.yml` CI workflow that uploads to Codecov on push/PR.
- **MailHog automated email assertions (#288, #295, #296, #297):** `mailhog/mailhog:v1.0.1` service added to `docker-compose.test.yml`. `docker/mailhog-smtp.php` MU-plugin routes `wp_mail()` through MailHog when `MAILHOG_SMTP_HOST` is set. `expect_email()` in the E2E suite now asserts delivery via MailHog API when `WP_MODE=docker`; SSH mode retains the manual `EMAIL_CHECKS` fallback.
- **`release.yml` GH Actions workflow (#302):** Triggered on `v*.*.*` tag push. Builds `simple-wp-helpdesk.zip`, extracts the matching CHANGELOG entry, and creates the GitHub Release with ZIP attached. Replaces the manual ZIP step in the release process.
- **`coverage.yml` GH Actions workflow (#301):** PHP 8.2 + pcov coverage on every push to `main`/`dev` and PR to `main`/`dev`. Uploads Clover report to Codecov.
- **phptest Dockerfile upgraded (#291):** Added `make`, Python 3, pip, semgrep 1.157.0 (pinned to match CI), and pcov PHP extension. CMD now runs `composer install && make test` so `make test-docker` delegates entirely to the container.
- **Pre-push hook upgraded (#293):** Auto-detects Docker (`docker info`); prefers `make test-docker` when available, falls back to `make test` on machines without Docker.
- **Dependabot allow-list expanded (#298):** Added `php-stubs/wordpress-stubs`, `wp-coding-standards/wpcs`, and `dealerdirect/phpcodesniffer-composer-installer` to the Composer allow-list.

### UX / A11y / DX

- **#258 — Expired portal token recovery link:** Expired-token page now displays an error alert with a direct link to the ticket lookup form, instead of a blank/generic error.
- **#259 — Pill-style status badges:** Status badges in admin ticket list and frontend portal now use modern pill styling (`border-radius: 9999px`, `letter-spacing: 0.02em`) replacing the legacy Bootstrap-era alert style.
- **#260 — Shared design tokens extracted to `swh-shared.css`:** All CSS custom properties (`--swh-color-*`, `--swh-radius-*`, `--swh-space-*`, `--swh-font-*`, `--swh-transition-*`) now live in a single shared stylesheet loaded as a dependency of both `swh-frontend.css` and `swh-admin.css`, eliminating the duplicated `:root` block.
- **#262 — CSAT star widget keyboard & ARIA support:** Star rating widget now uses `role="radiogroup"` / `role="radio"` ARIA semantics with roving tabindex and `ArrowLeft`/`ArrowRight` keyboard navigation. Previously mouse-only.
- **#263 — `aria-sort` on admin ticket list sortable columns:** Sortable column headers that are not the active sort now receive `aria-sort="none"` via an inline script injected by `wp_add_inline_script()`. Active column `aria-sort` was already set by WordPress core.
- **#264 — `aria-live` on unread reply badge:** Admin menu unread badge now carries `aria-live="polite"` and a descriptive `aria-label` so assistive technologies announce updates without requiring focus.
- **#265 / #266 — CSS typography and spacing scale:** All font sizes and spacing values now reference design tokens (`--swh-font-*`, `--swh-space-*`) rather than ad-hoc pixel values.
- **#267 — Settings tab position persisted across form submissions:** Active tab is now saved to `sessionStorage` on click. On page reload (e.g. after a form submission that doesn't redirect), the tab position is restored from `sessionStorage` if the URL `swh_tab` param is absent.
- **#268 — `prefers-reduced-motion` respected:** Progress bar indeterminate animation is now wrapped in `@media (prefers-reduced-motion: no-preference)` so animations do not run for users who have requested reduced motion.
- **#269 — Responsive CSAT star size:** Star rating font size now uses `clamp(22px, 5vw, 28px)` to scale correctly on mobile instead of a fixed `28px`.
- **#270 — Modern honeypot technique:** Honeypot fields in all three forms (submit, portal reply, portal lookup) now use the clip-path off-screen technique (`clip-path:inset(50%); height:1px; overflow:hidden; position:absolute; white-space:nowrap; width:1px;`) instead of `position:absolute; left:-9999px`.
- **#271 — Merge form expand/collapse transition:** The "Merge Ticket" section in the ticket editor is now collapsed by default behind a toggle button. Expanding/collapsing uses a CSS `max-height` + `opacity` transition (0.2 s) instead of appearing instantly.
- **#272 — Ticket lookup form slide transition:** The lookup form toggle now animates with a CSS `max-height` + `opacity` slide (0.3 s) instead of an instant `display:none` toggle.
- **#273 — Drag-and-drop file attachments:** All file attachment inputs on the frontend are now wrapped in a styled drop zone (`swh-drop-zone`) that accepts `dragover`/`drop` events. Files dropped onto the zone are assigned to the underlying `<input type="file">` via `DataTransfer`.
- **#274 — File attachment size and type icon:** After selecting or dropping files, the UI displays a per-file summary (inline SVG type icon + filename + human-readable size). All DOM mutations use `createElement`/`createElementNS`/`textContent` exclusively — no `innerHTML`.
- **#275 — CSAT widget auto-dismiss:** The CSAT rating prompt auto-dismisses after 60 seconds if the client ignores it, preventing it from persisting indefinitely.

### Changed

- `CLAUDE.md` release process: step 7 is now "push tag → `release.yml` fires automatically" — no manual `zip` command.
- PR template pre-PR gate updated to reference `make test-docker` as the preferred path.
- `SWH_VERSION` bumped to `3.2.0`.

---

## [3.1.0] — 2026-04-19

### Added

- **Dedicated "Send Reply" and "Save Note" buttons (#97):** The ticket editor conversation meta box now has explicit buttons for sending a public reply and saving an internal note, replacing the ambiguous "click Update" instruction.
- **Unread reply count badge in admin menu (#101):** The helpdesk menu item shows a WordPress-native `.awaiting-mod` count badge when tickets have unread client replies. Badge clears automatically when the admin opens the ticket.
- **Row highlighting for unread client replies (#102):** Tickets with new client replies receive the `swh-has-unread` CSS class in the admin list, adding a blue left border and light background tint for visual prominence.
- **"Send Test Email" button in Settings → Email Templates (#103):** Administrators can send a sample "New Ticket" notification to the WordPress admin email address with one click, with inline success/error feedback via AJAX.
- **`.pot` translation file (#123):** `languages/simple-wp-helpdesk.pot` generated via WP-CLI and shipped with the plugin, enabling translators to create `.po`/`.mo` locale files.
- **PHPUnit tests for `swh_format_comment_date()`:** Three new unit tests verify WP-timezone-aware formatting, `wp_date()` fallback behavior, and empty-GMT handling.
- **Playwright tests 48–52:** End-to-end coverage for timestamp locale (48), dedicated reply buttons (49), unread badge (50), unread row highlight (51), and Send Test Email button (52).
- **`Makefile` local gate (#276):** `make test` chains lint → phpcs → phpstan → phpunit → semgrep in sequence. `make e2e` runs the full Playwright suite. `make test-all` runs both. Individual targets available for each tool.
- **Pre-push git hook (#277):** `.githooks/pre-push` runs `make test` before every push. Activated automatically via `composer install` (`post-install-cmd`).
- **Docker Compose test stack (#278, #279):** `docker-compose.test.yml` defines `db`, `wordpress`, `wpcli`, and `phptest` services. `docker/setup-test-wp.sh` installs WordPress, creates users, activates the plugin, and creates submission/portal pages. `WP_MODE=docker` switches the Playwright suite to `docker compose exec` mode.
- **GitHub Actions — PHP matrix (#280, #282, #286):** `.github/workflows/php-tests.yml` runs lint, PHPCS, PHPStan, and PHPUnit across PHP 7.4/8.1/8.2/8.3. Includes a `composer audit` security job and a `CHANGELOG.md` update check on PRs.
- **GitHub Actions — E2E matrix (#281, #287):** `.github/workflows/e2e.yml` runs the full Playwright suite against WP 5.3 and latest in Docker, with screenshot artifacts on failure.
- **PR template (#283):** `.github/pull_request_template.md` with pre-PR gate checklist, E2E coverage checklist, and release checklist.
- **`testing/.env.example` (#284):** Documents all environment variables used by the test suite, including `WP_MODE` and Docker/SSH configuration.
- **Test update policy (#285):** Added to `CLAUDE.md` — defines which change types require new or updated Playwright sections.

### Fixed

- **Timestamps now respect WP site locale and timezone (#121):** Conversation timestamps in both the admin ticket editor and client portal now use `wp_date()` with `comment_date_gmt` (UTC source), ensuring the displayed time respects the site's timezone and `date_format` / `time_format` options.
- **`swh_send_email()` now returns `bool`:** The function previously returned `void`; it now returns the `wp_mail()` return value so callers (e.g. the test email AJAX handler) can detect delivery failures.
- **Portal ticket title promoted to `<h1>` (#247):** The ticket title in the client portal now renders as an `<h1>` (class `swh-ticket-title`) and the Conversation History heading as an `<h2>` (class `swh-section-heading`), correcting the heading hierarchy for accessibility and SEO.
- **Conversation log height changed to viewport-relative (#248):** Replaced `max-height: 400px` inline style with CSS class `.swh-conversation-wrap` (`max-height: 60vh; min-height: 200px`), so the conversation area scales with the viewport rather than being fixed.
- **Ticket UID block inline styles extracted to CSS class (#249):** Removed inline `style` attribute from the ticket UID display block; styles now live in `.swh-ticket-uid` in `swh-admin.css`.

### Changed

- `SWH_VERSION` bumped to `3.1.0`.

---

## [3.0.0] — 2026-04-12

### Added
- **Categories / departments taxonomy (#127):** New `helpdesk_category` hierarchical taxonomy — auto-registered on `init`, admin column, ticket-list dropdown filter, optional category selector on frontend submission form (`show_category` shortcode attribute, default `no`).
- **Ticket templates (#132):** Configurable request types (label + pre-filled description) managed in Settings → General → Templates. A "Request Type" dropdown appears on the submission form when templates exist; selected label stored as `_ticket_template` post meta and displayed read-only in the ticket editor.
- **CC / Watcher support (#129):** "CC / Watchers" field in the ticket editor saves comma-separated addresses as `_ticket_cc_emails` meta. All outgoing `swh_send_email()` calls inject `Cc:` headers for the ticket's CC list via the new helper `swh_get_cc_emails()`.
- **First response time tracking (#136):** `_ticket_first_response_at` Unix timestamp meta set automatically on the first staff reply. Displayed as elapsed time via `human_time_diff()` in the Ticket Details meta box.
- **Ticket merge (#133):** "Merge with another ticket" section in the ticket editor — ticket-ID lookup, AJAX merge action (`swh_merge_ticket`), and helper `swh_merge_tickets()` that moves comments, copies attachments, adds system notes on both tickets, and notifies the source-ticket client.
- **SLA breach alerts (#128):** Configurable warn/breach hour thresholds and alert recipient in Settings → Assignment & Routing → SLA. Hourly cron (`swh_sla_check_event`) sets `_ticket_sla_status` to `warn` or `breach` and sends a digest email on first breach. Ticket list rows receive `swh-sla-warn` / `swh-sla-breach` CSS classes; SLA badge shown in the ticket editor.
- **Auto-assignment rules (#126):** JS rule builder in Settings maps `helpdesk_category` terms to assignee users. Rules evaluated at ticket creation via `swh_apply_assignment_rules()`; first matching rule wins, falls back to `swh_default_assignee`.
- **Reply-by-email inbound webhook (#131):** REST endpoint `POST /wp-json/swh/v1/inbound-email` (registered in bootstrap). Supports Mailgun, SendGrid, and Postmark payload shapes. Validates optional Bearer token (`swh_inbound_secret`), parses `[TKT-XXXX]` from subject, validates sender via `hash_equals`, strips `>`-quoted lines, creates a `helpdesk_reply` comment, and reopens resolved/closed tickets. Webhook URL displayed read-only in Settings → Email Templates.
- **Reporting dashboard (#135, #137):** New submenu page "Reports" under the helpdesk CPT. Four AJAX-powered charts/metrics: status breakdown (doughnut), weekly opened/closed trend (bar), average resolution time, and average first response time. Results cached in 1-hour transients. Powered by Chart.js (CDN).
- **PHPUnit test additions (#241, #242):** `test_handle_multiple_uploads_preserves_origname()` verifying origname spaces preserved; CSAT building-block tests; new `ReportingTest` (status breakdown shape, resolution time row exclusion); new `InboundEmailTest` (ticket-ID regex, sender validation, quoted-reply stripping).

### Changed
- `swh_send_email()` now accepts a `$ticket_id` parameter (default `0`) to auto-inject `Cc:` headers from `_ticket_cc_emails` meta.
- Frontend submission calls `swh_apply_assignment_rules()` instead of reading `swh_default_assignee` directly, so assignment rules take effect at submission time.
- `SWH_VERSION` bumped to `3.0.0`.

---

## [2.5.0] — 2026-04-12

### Added
- **PHPUnit + WP-Mock unit test infrastructure:** `phpunit.xml`, `tests/bootstrap.php`, and three test classes (`HelpersTest`, `EmailTest`, `TicketTest`) covering typed helpers, template parsing, CSAT gating, and attachment origname handling.
- **reCAPTCHA Enterprise support (#236):** New Anti-Spam settings (Type selector, Project ID, API Key, Score Threshold) to use the reCAPTCHA Enterprise Assessment API alongside the existing v2 flow. Enterprise JS API enqueued automatically when selected.
- **CSAT status gate (#218):** `swh_submit_csat_ajax()` now rejects ratings (HTTP 400) when the ticket status does not match the configured closed status.
- **`wp_insert_post()` error feedback (#215):** Frontend submission shortcode now shows a user-visible error message and logs to `error_log` when ticket creation fails.
- **File upload size feedback (#219):** When one or more uploads are skipped due to the configured size limit, the response includes the count of skipped files for user feedback.
- **PCRE failure logging (#230):** All `preg_replace*` calls in `swh_parse_template()` now log to `error_log` on `null` return (PCRE error) and preserve the unmodified template.
- **JS string localisation for canned responses (#185):** Canned response UI strings (placeholder, aria-labels, Remove button label) are now passed from PHP via `wp_localize_script()` as `swhAdmin.i18n.*`; `swh-admin.js` consumes them with hard-coded fallbacks.
- **`aria-label` on canned response inputs (#187):** PHP-rendered canned response rows and JS-built rows both now carry explicit `aria-label` attributes for screen reader compatibility.
- **Typed wrapper helpers for PHPStan L9 (#145):** `swh_get_string_meta()`, `swh_get_int_meta()`, `swh_get_string_option()`, `swh_get_int_option()`, `swh_get_string_comment_meta()` in `includes/helpers.php`.
- **`swh_plugin_description_html()` i18n (#233):** All hard-coded English strings in the plugin description function now wrapped with `esc_html__()` / `__()`.

### Changed

- **PHPStan raised to Level 9 (#146):** All `mixed`-typed accesses from `get_option()`, `get_post_meta()`, `$_POST`, `$_FILES`, and WP_User magic getters narrowed to concrete types. `phpstan.neon` bumped to `level: 9`.
- **`actions/checkout` bumped v4→v6 (#238):** Both `.github/workflows/semgrep.yml` and `.github/workflows/claude-code-review.yml` updated.

### Fixed

- **Double `wp_unslash()` on canned responses (#213):** Redundant inner `wp_unslash()` calls removed from the settings save handler; the outer unslash on the raw POST array already handles unslashing.
- **Attachment origname stores sanitised name (#231):** `sanitize_file_name()` (converts spaces → hyphens) replaced with `sanitize_text_field()` when storing `_swh_attachment_orignames`, preserving the original filename.
- **`get_posts()` not guarded with `is_array()` (#220):** All `get_posts()` return values in `class-portal.php` now guarded with `is_array()` before iteration.
- **Empty "View" cell in My Tickets (#216):** Added a "Link unavailable" fallback when `swh_get_secure_ticket_link()` returns `false` so the table cell is never blank.
- **Lookup email sent with empty `{ticket_links}` (#217):** `swh_send_email()` is now skipped when no usable ticket links could be generated; an `error_log` entry is written and a user-facing message shown instead.
- **`swh_helpdesk_portal_shortcode` docblock (#221):** Docblock updated to accurately describe the no-token behaviour (My Tickets for logged-in users, lookup form for guests).

### Security

- **reCAPTCHA / Turnstile token extraction (#146):** Combined `isset` + `is_string` + `sanitize_text_field` + `wp_unslash` into a single expression; added `phpcs:ignore NonceVerification` comments with explanatory notes.

---

## [2.4.2] — 2026-04-10

### Fixed
- **Admin menu icon (#234):** Correct icon now shown when the sidebar is expanded — `SWH_MENU_ICON` restored to `favicon-32.png` (had regressed to `icon-128x128.png`).

### Changed
- **Bundled icons:** Plugin icons (`favicon-32.png`, `icon-128x128.png`, `icon-256x256.png`) are now served from the plugin package (`assets/`) instead of an external CDN, removing the outbound HTTP dependency and improving offline/staging compatibility.

---

## [2.4.1] — 2026-04-10

### Fixed
- **Original Attachment Filenames (#231):** Filenames with spaces are now preserved correctly — `sanitize_file_name()` (which converts spaces to hyphens) replaced with `sanitize_text_field()` when storing the `_swh_attachment_orignames` map in `swh_handle_multiple_uploads()`.
- **Canned Response Backslashes (#213):** Backslashes in canned response titles and bodies are no longer stripped on save — removed redundant inner `wp_unslash()` calls in the settings handler (outer `wp_unslash()` on the POST array already handles unslashing).
- **`default_status` Shortcode Attribute (#212):** `[submit_ticket default_status="In Progress"]` now correctly applies the specified status — removed erroneous `array_keys()` wrapper that returned numeric indices instead of status name strings.

---

## [2.4.0] — 2026-04-10

### Changed
- **Plugin Author Attribution:** Author changed from "SM WP Plugins" to "Sean Mousseau" with `Author URI` linking to the GitHub repository — the author name now appears as a hyperlink on the Plugins list screen.
- **Plugin Details Modal — Description:** The "View Details" modal now shows the full feature list with formatted HTML instead of the bare plugin header one-liner. Injected via `puc_request_info_result` filter since PUC does not reliably parse the readme.txt `== Description ==` section from a GitHub release ZIP.

### Fixed
- **readme.txt Changelog Whitespace:** Removed extra blank line between the 2.3.0 and 2.2.0 changelog sections that was causing extra whitespace in the modal Changelog tab.

---

## [2.3.0] — 2026-04-10

### Added
- **My Tickets Dashboard (#111):** Portal page without a ticket token now shows a table of open tickets for logged-in WordPress users (with secure links) or the ticket lookup form for guests — replacing the previous "No ticket specified" error box.
- **Original Attachment Filenames (#112):** Uploaded files now display their original filename as the link label (stored as `_swh_attachment_orignames` / `_swh_reply_orignames` meta), instead of the server-mangled name.
- **XHR Upload Progress Indicator (#113):** A progress bar (`swh-progress-bar`) appears during file attachment uploads on the ticket submission form, with the submit button disabled until the upload completes.
- **CSAT Prompt on Ticket Close (#116):** After a client closes a ticket via the portal, a 1–5 star satisfaction widget is shown. Ratings are stored in `_ticket_csat` post meta via an AJAX handler. Clients can skip to dismiss.
- **Humanized Timestamps (#117):** Reply timestamps in the client portal now display as relative strings ("3 hours ago", "Yesterday", etc.) using a `<time datetime>` element; the absolute date is preserved in the `title` tooltip.
- **`[submit_ticket]` Shortcode Attributes (#119):** Both `[submit_ticket]` and `[helpdesk_portal]` shortcodes now accept `show_priority` (yes/no), `default_priority`, `default_status`, and `show_lookup` (yes/no) attributes for per-page customisation.
- **Playwright/pytest Test Suite:** Full browser-based end-to-end suite covering 34 scenarios via pytest-playwright. Scenarios cover: admin auth, plugin verification, ticket submission, admin management, client portal, status transitions, internal notes, access control, bulk actions, settings persistence, canned responses, multi-technician workflow, admin search/filters, file attachments, portal token security, XSS escaping, subscriber access control, and rate limiting. Run with `pytest testing/scripts/test_helpdesk_pw.py`.

### Changed
- **Resolved → Close CTA Layout (#118, #120):** On the portal, the "Close Ticket" prompt for resolved tickets is now a prominent two-part block: a primary CTA card with the Close button and a de-emphasised "Still need help? Reply below ↓" link, replacing the previous single alert box.
- **PHPStan Level 6 → 8 (#143, #144):** Static analysis level raised to 8. Added `is_array()` / `instanceof WP_Comment` guards on all `get_comments()` / `get_pages()` calls; null guards on `preg_replace*` / `ob_get_clean` / `wp_parse_url` return values.

### Fixed
- **Shortcode Detection — `has_shortcode()` (#186):** Page dropdown in Settings now uses WordPress's `has_shortcode()` for reliable detection (handles shortcode attributes), replacing the previous `strpos` loop.
- **Canned Response Insert in Ticket Editor (#182):** `swh-admin.js` was not enqueued on `post.php` / `post-new.php`, causing the canned response Insert button to be non-functional in the ticket editor.
- **Canned Response Cleanup on Reset/Uninstall (#182):** `swh_canned_responses` was not registered in `swh_get_defaults()`, so factory reset and plugin uninstall did not clean up saved canned responses.
- **Bulk Status Change — `_resolved_timestamp` Sync (#182):** Bulk status updates now sync `_resolved_timestamp` meta (set when a ticket enters resolved, cleared on re-open) to match the behaviour of `swh_save_ticket_data()`.
- **Canned Response Input Sanitization (#182):** `wp_unslash()` now applied to canned response title and body POST arrays before `sanitize_text_field()`.
- **Duplicate Icon Constants:** Duplicate `SWH_ICON_1X` / `SWH_ICON_2X` / `SWH_MENU_ICON` define block introduced by a rebase conflict removed from the plugin bootstrap.

---


## [2.2.0] — 2026-04-09

### Added
- **Bulk Status Change (#107):** New bulk action on the ticket list — "Set Status: {Status}" entries for every configured status. Updates `_ticket_status` meta on all selected tickets and shows a confirmation notice.
- **Shortcode Indicator in Page Dropdown (#108):** The Helpdesk Page selector in Settings now annotates each page with the shortcode it contains (e.g. `— [helpdesk_portal]`), making it easy to pick the correct page at a glance.
- **Canned Responses (#110):** New Canned Responses tab in Settings for managing pre-written reply templates (title + body). A picker above the ticket editor reply textarea inserts the selected template body at click.
- **Plugin Branding Assets (#177, #178, #179):** CDN-hosted plugin icons (`icon-128x128.png`, `icon-256x256.png`) wired into the admin menu CPT icon, Settings page header, and the Plugin Update Checker info response for the WordPress update UI.

### Changed
- **PHPStan Level 5 → 6 (#141, #142):** Static analysis level raised to 6. Return type and parameter type declarations verified against szepeviktor/phpstan-wordpress stubs; zero errors at level 6.

### Fixed
- **Missing Icon Constants (#177-bug):** `SWH_ICON_1X`, `SWH_ICON_2X`, and `SWH_MENU_ICON` were used in `class-installer.php` and `class-settings.php` but never defined at runtime. All four CDN constants now defined in the plugin bootstrap and in `phpstan-bootstrap.php`.
- **CDP Test: Icon-in-Transient (#180):** `wp_update_plugins()` is now called before the transient check so the icon data is primed before the assertion.
- **CDP Test: `curl` Availability Guard (#181):** CDN reachability checks now call `shutil.which("curl")` and skip gracefully when `curl` is not in `PATH`.

---

## [2.1.0] — 2026-04-09

### Added
- **Extensibility Hooks (#171):** Ten new `apply_filters()` / `do_action()` hooks for customizing plugin behaviour without modifying core files: `swh_ticket_statuses`, `swh_ticket_priorities`, `swh_rate_limit_ttl`, `swh_allowed_file_types`, `swh_submission_data`, `swh_pre_ticket_create`, `swh_ticket_created`, `swh_email_headers`, `swh_parse_template`, `swh_autoclose_threshold`.
- **ARIA Tab Interface on Settings Page (#161):** Settings navigation upgraded from `<a>` links to fully keyboard-navigable ARIA tab widgets (`role="tab"`, `role="tabpanel"`, `aria-selected`, `aria-controls`, `aria-labelledby`). Supports Arrow, Home, and End key navigation.
- **Accessible Form Labels (#163, #165):** All admin ticket editor fields and frontend submission form inputs now have explicit `<label for>` / `id` associations.
- **ARIA Live Regions (#162, #166):** Conversation log has `role="log"`; success messages use `role="status"` (polite); error messages use `role="alert"` (assertive).
- **Screen-Reader Honeypot (#159):** Honeypot wrapper divs now carry `aria-hidden="true"` so assistive technologies skip invisible fields.
- **`aria-expanded` on Lookup Toggle (#159):** The "Resend my ticket links" toggle link exposes its open/closed state to assistive technologies via `aria-expanded`.
- **`swh-admin.css` Asset (#149):** Admin badge and column styles extracted from inline PHP into a dedicated enqueued stylesheet.
- **Inline Docs on All Hook Registrations (#164, #169):** All `add_action()`, `add_filter()`, `add_shortcode()`, and `register_*_hook()` calls have inline docblock comments.
- **PHPDoc on All Functions (#154, #155, #157):** Complete `@param`, `@return`, and `@since` docblocks added to all functions and file-level docblocks across all 11 PHP files.

### Changed
- **JavaScript Modernisation (#150, #153):** All `var` declarations replaced with `const`/`let` in `swh-admin.js` and `swh-frontend.js`.
- **CSS Formatting (#149):** Both `swh-frontend.css` and `swh-admin.css` use consistent multi-line rule format.
- **PHP Code Style (#151, #152):** ABSPATH guard brace style normalised; mixed indentation corrected across all module files via `phpcbf`.
- **HTML Audit (#156):** Self-closing void elements, attribute quoting, and `echo` chain indentation normalised.

### Fixed
- **Focus Styles (#167, #168):** Removed `outline: none` from `.swh-form-control:focus`; added compliant 2px focus rings on form controls and buttons in both frontend and admin stylesheets.
- **PHPStan Level-5 Type Errors (#phase-17):** Resolved 27 type errors introduced by stricter analysis — `comment_ID` casts, `str_pad` string coercion, redundant truthiness guards, and `is_wp_error` check on `wp_insert_post` return.
- **PHPCS Zero Warnings (#158):** All PHP_CodeSniffer warnings resolved; `phpcs:ignore` directives moved to the correct lines; `@var` type annotations converted to non-docblock style.

---

## [2.0.0] — 2026-04-07

### Changed
- **Modular File Structure (#61):** Refactored single-file architecture into `includes/`, `admin/`, and `frontend/` directories. Bootstrap file is now a thin loader (~55 lines). Admin files only loaded in admin context. No behavioral changes.
- **Replaced GitHub Updater (#64):** Replaced custom `SWH_GitHub_Updater` class (~130 lines) with the [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) library. Gains retry with backoff, release asset detection, and GitHub API token support.

### Added
- **Conditional Email Template Blocks (#65):** Email templates now support `{if key}...{endif key}` syntax for conditionally including sections. Unreplaced placeholders are automatically cleaned up. Default templates updated to use conditionals for optional fields.
- **`[helpdesk_portal]` Shortcode (#59):** New optional shortcode for placing the client portal on a dedicated page, separate from the submission form. The existing `[submit_ticket]` shortcode continues to work identically.
- **WordPress-Compliant `readme.txt` (#75):** Added `readme.txt` following the WordPress plugin readme standard for proper plugin directory display.

### Fixed
- **Attachment Display in Emails (#76):** Attachment links in emails now show clean filenames (e.g., `photo.jpg`) instead of raw query parameters, in both HTML and plain-text formats.
- **Settings Save Redirect (#77):** Settings save now correctly redirects back to the settings page and active tab instead of the ticket list.
- **Client Reopen — Silent Failure:** Submitting the reopen form with an empty textarea and no attachments silently did nothing — no status change, no error. Reopen now always succeeds (consistent with close, which requires no explanation). Audit comment adapts: includes the reason if provided, otherwise logs `TICKET RE-OPENED BY CLIENT`.
- **Client Reopen — Rate Limit Conflict:** All three portal actions (close, reply, reopen) shared one rate limit key per ticket. Closing a ticket consumed the 30-second window, blocking an immediate reopen attempt. Each action now has its own key (`portal_close_`, `portal_reopen_`, `portal_reply_`).
- **Helpdesk Page Setting Not Applied to Links:** `swh_get_secure_ticket_link()` read the stale `_ticket_url` post meta (written at ticket creation) instead of the live `swh_ticket_page_id` setting. Changing the page in Settings had no effect on generated portal links. The function now resolves the URL from the setting directly via `get_permalink()`, falling back to stored meta only when no page is configured.
- **Wrong Portal URL in New-Ticket Emails:** `$data['ticket_url']` was built before `_ticket_token` was written to post meta, so `swh_get_secure_ticket_link()` always returned `false` and the fallback hardcoded `get_permalink()` (the `[submit_ticket]` page). The token is now stored first, so the correct portal page is used in all outbound emails.

### Removed
- `SWH_GitHub_Updater` class (replaced by plugin-update-checker library).

---

## [1.9.0] — 2026-04-06

### Added
- **Portal Token Expiration (#58):** Configurable portal link TTL (default 90 days, 0 = never). Expired links show a clear message directing clients to the lookup form. Tokens auto-rotate on each lookup resend, providing self-service link revocation.
- **Persistent Rate Limiting (#62):** Rate limits now stored in `wp_options` instead of transients, surviving cache flushes and working across multi-server deployments. Global per-IP keying prevents distributed ticket attacks. Expired entries cleaned up via existing cron.
- **Cron Job Locking (#52):** Transient-based locking prevents duplicate processing when cron fires multiple times simultaneously on high-traffic sites.
- **Comment Isolation (#57):** Helpdesk replies now use `comment_type = 'helpdesk_reply'`, preventing leakage into WordPress comment widgets, RSS feeds, and sitewide comment counts. Includes one-time upgrade migration for existing comments.
- **Meta Cache Priming (#53):** Admin ticket list and cron loops now batch-prime post meta via `update_meta_cache()`, eliminating N+1 query patterns.
- **Restrict Technicians to Assigned Tickets (#60):** New "Restrict Technicians" checkbox in Assignment & Routing settings. When enabled, technicians only see tickets assigned to them. Admins always see all tickets. Direct URL access to unassigned tickets is also blocked.
- **Inline File Validation Errors (#54):** Frontend file upload validation now shows inline error messages below the input instead of blocking browser `alert()` dialogs.

### Fixed
- **Missing `is_wp_error()` Checks (#49):** Added return value guards on `wp_insert_post()` and all 7 `wp_insert_comment()` call sites to prevent silent data corruption on insertion failure.
- **Header Injection in File Proxy (#50):** `Content-Disposition` filename now strips `\r`, `\n`, `"`, and `;` characters to prevent HTTP header injection.
- **Inconsistent `intval()` (#51):** Frontend portal ticket ID sanitization changed from `intval()` to `absint()` to match the pattern used everywhere else.
- **Extra Closing `</div>` (#55):** Removed duplicate closing tag in the invalid-token error output that corrupted page DOM structure.

### Security
- **Token Expiration (#58):** Portal links no longer grant permanent access. Configurable TTL with auto-rotation on lookup.
- **Header Injection (#50):** Defense-in-depth filename sanitization in file proxy endpoint.
- **Persistent Rate Limiting (#62):** Rate limits survive cache flushes and work across load-balanced environments.

### Changed
- **Thorough Uninstall (#63):** "Delete data on uninstall" now also clears cron hooks, removes upload protection files/directory, deletes rate-limit options and transients, removes migration flags, and reassigns technician users to the default role before removing the role.

---

## [1.8] — 2026-04-06

### Added
- **Internationalization (i18n) (#43):** All ~85 hardcoded UI strings wrapped with WordPress i18n functions. Text domain `simple-wp-helpdesk` registered with `load_plugin_textdomain()`. JS alert strings localized via `wp_localize_script()`. `languages/` directory created for `.pot`/`.po`/`.mo` files.
- **Client Ticket Lookup (#44):** "Resend my ticket links" form on the frontend allows clients to enter their email and receive links to all open tickets. 60-second rate limit per IP. Deliberately vague success message prevents email enumeration. New email template (`swh_em_user_lookup_sub`/`_body`) with `{ticket_links}` placeholder.
- **Anti-Spam on Portal Forms (#41):** Reply form now supports honeypot, reCAPTCHA, and Turnstile. Reopen form supports honeypot. New `swh_check_antispam()` helper consolidates all spam verification logic. Existing ticket form refactored to use the helper.

### Security
- **CDN/Proxy-Aware Rate Limiting (#40):** New `swh_get_client_ip()` helper checks `HTTP_CF_CONNECTING_IP` (Cloudflare), `HTTP_X_FORWARDED_FOR` (first IP), then `REMOTE_ADDR`. Both rate-limit locations updated.
- **Protected File Uploads (#42):** Attachments now upload to `uploads/swh-helpdesk/` with `.htaccess` deny and `index.php` guard. New `swh_serve_file()` proxy endpoint validates portal token or admin capability before streaming files. All attachment display and email links use proxy URLs. Existing attachments in `uploads/YYYY/MM/` continue to work.

---

## [1.7] — 2026-04-05

### Added
- **HTML Email Support (#26):** All outgoing emails now use a clean, table-based HTML layout with clickable links. New `Email Format` setting in the Email Templates tab allows toggling between HTML and Plain Text. Defaults to HTML.
- **Admin Ticket List Columns (#29):** The All Tickets admin screen now displays Ticket #, Status (color-coded badge), Priority, Assigned To, Client, and Date columns. Status and Priority filter dropdowns and sortable columns included.
- **Admin Notice for Unconfigured Helpdesk Page (#38):** A dismissible warning appears on ticket screens when the Helpdesk Page setting is not configured, preventing silent misconfiguration of portal URLs.

### Fixed
- **Sidebar Attachment Labels (#31):** The "All Attachments" list in the admin sidebar meta box now displays actual filenames instead of generic "File 1, File 2" labels.
- **`requires_php` Mismatch (#32):** The GitHub Updater plugin info popup now correctly reports `Requires PHP: 7.4` (was hardcoded to `7.2`).
- **Frontend Priority Validation (#33):** Ticket priority submitted via the frontend form is now validated against the configured priority list, preventing arbitrary values from being stored.
- **Missing `$_FILES` Check (#34):** Frontend ticket submission no longer triggers a PHP notice when no file attachment is included.
- **Legacy Attachment Retention (#39):** The attachment retention cron now handles legacy single-URL string format and checks `_ticket_attachment_url` / `_ticket_attachment_id` meta keys, preventing orphaned files.

### Changed
- **Email Helper Refactor (#36):** All 14 `wp_mail()` call sites consolidated into a single `swh_send_email()` helper function, reducing ~100 lines of duplicate code and providing a single point of change for email behavior.
- **Frontend CSS Scoped to Shortcode (#30):** `swh-frontend.css` is now enqueued only when the `[submit_ticket]` shortcode is rendered, eliminating an unnecessary HTTP request on all other pages.
- **Frontend JS Extracted (#35):** Client-side file validation JavaScript extracted from inline `<script>` to `assets/swh-frontend.js` and loaded via `wp_enqueue_script()` with `wp_localize_script()` for CSP compliance.

### Closed
- **#25:** Closed as duplicate of #29.
- **#37:** `SWH_GitHub_Updater` confirmed instantiated at line 1873. Closed as not applicable.

---

## [1.6] — 2026-04-05

### Added
- **Reassignment Email:** When a ticket is assigned or reassigned to a technician, that technician now receives an email notification. Template configurable under Settings → Email Templates.
- **Max Files Per Upload:** New `Max Files Per Upload` setting (default: 5) in the General tab. Enforced both server-side and via client-side JS validation. Set to 0 for unlimited.
- **Rate Limiting on New Ticket Submission:** The 30-second transient-based rate limit (previously only applied to portal actions) now also applies to initial ticket submissions, keyed by IP.

### Fixed
- **Plugin Header Version Mismatch:** `Version:` header comment now correctly reads `1.6`, matching `SWH_VERSION` (was showing `1.4` since v1.5).

### Changed
- **`wp_mail()` Failure Logging:** All email sends now check the return value and log failures via `error_log()` — consistent with the upload error logging added in v1.5.
- **Real Attachment Filenames:** Attachments in both the admin conversation meta box and the frontend client portal now display the actual filename (via `basename()`) instead of generic "File 1", "File 2" labels.
- **Enqueued Frontend CSS:** Frontend stylesheet extracted from inline `<style>` block to `assets/swh-frontend.css` and loaded via `wp_enqueue_style()` for browser caching and CSP compatibility.
- **Enqueued Admin JS:** Settings page JavaScript extracted from inline `<script>` block to `assets/swh-admin.js` and loaded via `wp_enqueue_script()`.
- **Enqueued Anti-Spam Scripts:** reCAPTCHA and Turnstile CDN scripts now registered via `wp_enqueue_script()` and `wp_add_inline_script()` instead of raw `echo '<script>'` output.
- **CPT Labels:** Added missing `register_post_type()` labels: `all_items`, `view_item`, `search_items`, `not_found`, `not_found_in_trash`, `menu_name`.
- **`add_role()` Idempotency:** `swh_activate()` now wraps `add_role()` with a `get_role()` existence check.
- **`posts_per_page` Parameter:** Replaced legacy `numberposts` parameter with `posts_per_page` in all `get_posts()` calls (7 occurrences).
- **PHP Minimum Bumped to 7.4:** Plugin header updated from `Requires PHP: 7.2` to `Requires PHP: 7.4`, aligning with WordPress core's minimum.

---

## [1.5] — 2026-03-27

### Added
- **Admin Ticket Creation:** Admins can now enter a client's Name and Email directly from the Add New Ticket screen in the WordPress dashboard.
- **Portal Page Setting:** New `Helpdesk Page` dropdown in the Assignment & Routing tab. Selects the page containing `[submit_ticket]`; used to build the secure portal URL for admin-created tickets.
- **Rate Limiting:** 30-second transient-based cooldown on all frontend actions (close, reopen, reply) to prevent duplicate submissions.
- **Upload Error Logging:** Failed uploads (oversized files, `wp_handle_upload()` errors) now logged via `error_log()` instead of being silently discarded.

### Fixed
- **`swh_delete_on_uninstall` Silent Reset:** The "Delete data on uninstall" checkbox was resetting to `no` whenever any other settings tab was saved. Fixed by giving the Tools form its own nonce (`swh_save_tools_action`) and processing `swh_delete_on_uninstall` exclusively in that handler.
- **Double-Escaped Author Names:** Special characters (apostrophes, accented letters) in technician names were being double-escaped and rendered as HTML entities in the conversation timeline.
- **Retention Cron Active-Ticket Bug:** The attachment retention cron was using `post_date` for its age query, meaning it could delete attachments from recently-updated (but old) tickets. Fixed to use `post_modified`.
- **Multi-Handler Firing:** Sequential `if` blocks for the frontend portal (close / reopen / reply) could theoretically fire multiple handlers in one request. Converted to `if/elseif/elseif`.
- **GitHub Auto-Updater:** Fully resolved the "No valid plugins were found" fatal error that persisted through v1.4.

### Changed
- **Single Source of Truth:** All option defaults now live exclusively in `swh_get_defaults()`. Hardcoded `add_option` calls removed from the upgrade routine.
- **PRG Pattern:** All settings saves now perform a Post/Redirect/Get redirect, preventing double-submission on page refresh. Active tab is preserved via `?swh_tab=` query parameter.
- **Input Validation:** Status, priority, and assignee values are validated against their allowed lists in `swh_save_ticket_data()` before being persisted.
- **Assignee Role Check:** Assigned user ID is verified to belong to the `administrator` or `technician` role before saving.
- **Integer Sanitization:** `swh_autoclose_days`, `swh_max_upload_size`, retention days, and `swh_ticket_page_id` are now saved with `absint()`.
- **JS Reset Button:** Reset-to-default buttons now use a `data-field-name` attribute lookup instead of fragile `previousElementSibling` DOM traversal.
- **`swh_field()` Function:** Moved from a nested closure inside `swh_render_settings_page()` to a top-level named function, eliminating a potential fatal error risk.

### Removed
- **`@set_time_limit(0)`:** Removed from all three cron functions — the micro-batch design makes it unnecessary.
- **Duplicate `wp_head` Script Injection:** Removed redundant `wp_head` action for reCAPTCHA/Turnstile scripts; the shortcode now handles its own script loading with explicit render mode.

---

## [1.4] — 2026-03-13

### Changed
- **Smart Folder-Flattening:** The GitHub Updater now automatically detects plugin files nested inside a sub-directory within the release archive and extracts them correctly.
- **Release Asset Priority:** The updater now prioritizes pre-built `.zip` release assets over raw GitHub source archives for cleaner, safer updates.
- **Cache Busting:** Implemented cache-busting on the update checker transient so new releases are detected immediately without waiting for the 12-hour timeout.

---

## [1.3] — 2026-03-13

### Security
- Fixed several security vulnerabilities identified by automated scanning tools.

---

## [1.2] — 2026-03-13

### Added
- **GitHub Auto-Updater:** The plugin now checks its linked GitHub repository for new releases and delivers them directly to the WordPress dashboard, functioning identically to a wordpress.org-hosted plugin.

### Changed
- **Background Cron Overhaul:** Auto-Close, Ticket Purge, and Attachment Purge tasks are now split into separate staggered hourly events with strict SQL-level micro-batching (1–2 items per run). Eliminates cURL error 28 timeouts on resource-restricted hosts.
- **Centralized Defaults:** Default configuration values consolidated into a statically cached object, reducing memory usage on every page load and guaranteeing fallback text when settings have not been explicitly saved.

### Security
- Enhanced frontend token validation with `hash_equals()` to protect the client portal against timing attacks.
- Added strict path-traversal prevention to data retention file unlinking functions.
- Added explicit `current_user_can` checks to backend save routines to prevent privilege escalation.

---

## [1.1] — 2026-03-12

### Changed
- **Page Builder Compatibility:** Frontend shortcode now uses a fully scoped CSS architecture under `.swh-helpdesk-wrapper`, keeping form elements correctly aligned inside Elementor columns.
- Modernized frontend UI with improved padding, focus states, badge designs, and alert box styling.
- Replaced raw inline HTML tags around form inputs with structured `<div>` groups to prevent theme line-height layout conflicts.

---

## [1.0] — 2026-03-12

### Added
- Custom Post Type (`helpdesk_ticket`) for native WordPress backend integration.
- Front-end submission form and secure token-based client portal via `[submit_ticket]` shortcode.
- Tabbed Admin Settings panel: General, Assignment & Routing, Email Templates, Messages, Anti-Spam, Tools.
- Two-way multi-file upload support with JavaScript size and extension validation.
- Technician UI with public reply and private Internal Note capabilities.
- Background automation: auto-close inactive resolved tickets; auto-purge old tickets and attachments.
- GDPR client data purge tool and full uninstallation cleanup routines.
- Anti-spam integrations: Honeypot, Google reCAPTCHA v2, Cloudflare Turnstile.
- Micro-batched cron jobs to prevent server timeouts.

---

[Unreleased]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v4.0.5...HEAD
[4.0.5]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v4.0.4...v4.0.5
[4.0.4]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v4.0.3...v4.0.4
[4.0.3]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v4.0.2...v4.0.3
[4.0.2]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v4.0.1...v4.0.2
[4.0.1]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v4.0.0...v4.0.1
[4.0.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.7.0...v4.0.0
[3.7.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.6.0...v3.7.0
[3.6.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.5.0...v3.6.0
[3.5.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.4.1...v3.5.0
[3.4.1]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.4.0...v3.4.1
[3.4.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.3.0...v3.4.0
[3.3.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.2.0...v3.3.0
[3.2.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.1.0...v3.2.0
[3.1.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.5.0...v3.0.0
[2.5.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.4.2...v2.5.0
[2.4.2]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.4.1...v2.4.2
[2.4.1]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.4.0...v2.4.1
[2.4.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v1.9.0...v2.0.0
[1.9.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.8...1.9.0
[1.8]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.7...1.8
[1.7]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.6...1.7
[1.6]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.5...1.6
[1.5]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.4...1.5
[1.4]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.3...1.4
[1.3]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.2...1.3
[1.2]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.1...1.2
[1.1]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.0...1.1
[1.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/tag/1.0
