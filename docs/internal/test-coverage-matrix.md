# Test Coverage Matrix

**Audience:** plugin contributors. **Status:** living document — update in every PR that adds, changes, or removes covered surface.

This matrix is the **definition of done** for testing. Every functional surface in the plugin appears on one axis; every test layer appears on the other. A cell is `✓` (test exists), `R` (red — gap), `N/A` (not applicable with reason), or `P` (partial — exists but inadequate).

It replaces the implicit "we have some tests" answer with an auditable one. A PR that adds or modifies a feature must leave its row all-green (or explicitly N/A with rationale).

## Why this exists

v4.0.0 shipped with 23+ bugs that the existing suite missed across portal, inbox, drawer, REST, email, settings, and reports. The post-mortem (`docs/internal/v3.7.0-discovery.md` and session notes) found three root causes:

1. **Selector-coupled E2E** — tests asserted DOM shape, so UI changes "fixed" the test instead of failing it (test_59, test_69, test_70, test_71 all updated reactively during v4.0.0).
2. **No invariant guards** — the hot invariants in `design-document.md` (status mutator, mail dispatcher, `hash_equals` token compare, portal URL ordering, rate-limit key shape, comment isolation) had zero tests.
3. **Happy-path-only assertions** — error shapes (the 409 envelope), case sensitivity (`Closed` vs `closed`), and stubs (CSAT) escaped because the suite never asserted them.

This matrix prevents that pattern from recurring by making every gap explicit and every "done" claim verifiable.

## The five layers

| Layer | Purpose | Tooling | Location |
|---|---|---|---|
| **L1 — Invariants** | Pin every load-bearing rule from `design-document.md` as an executable assertion. Catches "someone bypassed the contract" bugs. | PHPUnit + grep/AST helpers | `tests/Unit/Invariants/` (new) |
| **L2 — REST/AJAX contracts** | Assert status code AND JSON envelope for every endpoint × every error/success path. **Two sub-layers:** (a) PR 2 ships envelope-shape assertions at the mocked-WP layer (fast feedback, catches v4.0.0-class response-shape regressions); (b) PR 7 adds the `wp-phpunit` integration harness for route dispatch, `permission_callback` enforcement, real auth, and concurrent-write paths that mocks can't model. | PHPUnit + WP-Mock (mocked); PHPUnit + `wp-phpunit` (integration) | `tests/Unit/Rest/Contracts/` (PR 2); `tests/Integration/Rest/`, `tests/Integration/Ajax/` (PR 7) |
| **L3 — Domain** | State transitions, side effects, guard conditions, normalization. Table-driven where possible. No mocking the function under test. | PHPUnit + WP-Mock (or wp-phpunit for DB-touching tests) | `tests/Unit/` (expand) |
| **L4 — Behavior E2E** | User-visible outcomes asserted via semantic selectors (`getByRole`, `getByLabel`, `getByText`). Banned: CSS-class selectors except where genuinely non-semantic. | Playwright | `testing/scripts/` (rewrite by feature) |
| **L5 — Visual regression** | Pixel-diff baselines for critical surfaces × theme variants. Auto-runs on PR, human-approves baseline updates. | Playwright `toHaveScreenshot()` | `testing/visual/` (new) |

## How to read a cell

- **`✓`** — Test exists, covers happy + at least one failure mode, asserts both observable side effect and (where applicable) the documented contract shape.
- **`P`** — Partial: a test touches this surface but does not meet the bar above (e.g. asserts only happy path, only DOM existence, or mocks the unit under test).
- **`R`** — Red: no test. Backlog candidate.
- **`N/A — <reason>`** — Genuinely not applicable. Reason is required (e.g. "cron job, no UI" → N/A for L5).

---

## The matrix

Numbers in parentheses link to detailed gap notes in the next section. Existing test references use class names from `tests/Unit/` or test-IDs from `testing/scripts/test_helpdesk_pw.py`.

### Tier 1 — data loss, security, money

| Feature | L1 Invariant | L2 REST/AJAX | L3 Domain | L4 E2E | L5 Visual |
|---|---|---|---|---|---|
| Status lifecycle (`swh_set_ticket_status`, status mutator invariant) | ✓ `StatusMutatorInvariantTest` | ✓ `TicketControllerTest`, `BulkControllerTest` (happy only — P for error envelope) | P `HelpersTest` (basic) | P test_06, test_10, test_08 (selector-coupled) | N/A — covered by drawer L5 |
| Direct `_ticket_status` writes (allow-list) | ✓ `StatusMutatorInvariantTest` | N/A | N/A | N/A | N/A |
| Portal token comparison (`hash_equals` invariant) | ✓ `TokenComparisonInvariantTest` | ✓ inbound-email path covered indirectly | P test_24 (token expiration) | P test_24 | N/A |
| Inbound-email bearer auth (`hash_equals`) | ✓ `TokenComparisonInvariantTest` | ✓ `InboundEmailTest` | ✓ `InboundEmailTest` | R (4) | N/A |
| File proxy auth (token OR cap, expiration check, path traversal guard) | ✓ `FileProxyInvariantTest` | R (6) | P `TicketTest` (upload path only) | P test_23 (download happy path) | N/A |
| File upload validation (mime, size, count, double-extension, SVG) | R | N/A | P `TicketTest` (size+count only — R for mime exhaustion, SVG, double-extension) | P test_23 | N/A |
| Anti-spam — honeypot | R | N/A | ✓ `AntispamTest` (3 cases: legit + bot-fills + lookup-forms) | P test_16 | N/A |
| Anti-spam — reCAPTCHA v2 + Enterprise | R | N/A | ✓ `AntispamTest` (8 cases: v2 happy/fail/non-JSON/skipped, Enterprise missing-creds/below-threshold/above-threshold/invalid-token) | R | N/A |
| Anti-spam — Turnstile | R | N/A | ✓ `AntispamTest` (3 cases: success/fail/non-JSON fail-closed) | R | N/A |
| Capabilities — administrator | N/A — WP built-in | ✓ implicit via REST tests | N/A | P test_11 | N/A |
| Capabilities — technician (default) | R (8) | P REST tests assume tech | ✓ `CapabilityMatrixTest` (REST permissions_check: missing edit_posts → false, admin pass, list endpoints skip per-ticket check) | P test_07 | N/A |
| Capabilities — technician (restricted to assigned) | R (8) | ✓ `CapabilityMatrixTest` (per-ticket edit_post rejection — load-bearing) | ✓ `CapabilityMatrixTest` (5 cases: setting off, non-tech, tech-assigned, tech-not-assigned 403, wrong post type) | P test_21 | N/A |
| Capabilities — subscriber blocked | N/A | ✓ permission_callback | N/A | ✓ test_26 | N/A |
| Rate limiting — per-action keys (`portal_close_`, `portal_reopen_`, `portal_reply_` + id) | ✓ `RateLimitKeyInvariantTest` | R (boundary test 0/N/N+1) | ✓ `RateLimitTest` (1st-call/2nd-call/different-action/different-ticket/expired-lock) | P test_27 | N/A |
| Comment isolation — `comment_type='helpdesk_reply'` & `_is_internal_note` filtered from frontend | ✓ `CommentIsolationInvariantTest` | N/A | R | R (11) | R (drawer L5 should snapshot with note hidden) |
| Migration v3→v4 settings schema | N/A | N/A | ✓ `SettingsMigrationTest`, `SwhGetOptionTest` | R (12) | N/A |
| Migration: comment_type_v2, tech_caps_v2 | N/A | N/A | P `LifecycleActionsTest` (activation only) | R | N/A |
| Ticket merge | R (13) | R (no REST yet — AJAX `swh_merge_ticket`) | ✓ `MergeTicketsTest` (5 cases: invalid source, wrong type, happy path with comment move + attachment merge + cross-ref notes + source close, empty source preserves target, missing target) | P test_43 | R |
| Optimistic locking (`TicketVersion::check`, 409 envelope) | R (14) | ✓ `TicketControllerContractTest` (409 envelope + `current.version` shape) | ✓ `TicketVersionTest` | R | N/A |

### Tier 2 — user-facing breakage

| Feature | L1 | L2 | L3 | L4 | L5 |
|---|---|---|---|---|---|
| Ticket create — admin | N/A | R (no admin-create REST) | R | P test_06 | R (drawer) |
| Ticket create — frontend shortcode | N/A | N/A | R (`swh_ticket_frontend` direct test) | ✓ test_03 | R |
| Ticket create — email-to-ticket | N/A | ✓ `InboundEmailTest` (REST) | ✓ `InboundEmailTest` | R (14) | N/A |
| Ticket create — REST | N/A | R (no POST endpoint — confirm intentional) | N/A | N/A | N/A |
| Reply — admin via drawer | R (15) | P `TicketControllerTest` | P | P test_06, test_09 (selector) | R |
| Reply — portal | R (15) | N/A (portal posts to admin-post.php) | P | P test_08 | R |
| Reply — inbound email | R (15) | ✓ `InboundEmailTest` | ✓ `InboundEmailTest` | R | N/A |
| Internal note | R (10) | P `TicketControllerTest` | R | P test_06 | R (drawer must hide for portal screenshot) |
| Assign / reassign / unassign | R (16) | P `TicketControllerTest` | R | P test_06, test_21 | R |
| Bulk actions — status change | N/A | ✓ `BulkControllerContractTest` (400/200 envelopes, conflict shape) | ✓ `BulkControllerTest` | ✓ test_20 | R |
| Bulk actions — assign, delete | N/A | P `BulkControllerTest` | P | R | R |
| Delete / trash / restore | N/A | N/A (WP default REST) | R | R | N/A |
| Auto-close (cron `swh_autoclose_event`) | R | N/A | ✓ `CronTest` (3 cases: days=0 kill switch, lock-held short-circuit, lock acquire+release) | R (17) | N/A — cron |
| SLA tracking + breach digest (cron `swh_sla_check_event`, `swh_sla_breached` hook) | R | N/A | ✓ `CronTest` (3 cases: both-thresholds-disabled kill, lock-held, warn-only proceeds) | R | N/A |
| Retention — tickets (cron) | R | N/A | ✓ `CronTest` (3 cases: days=0 kill, lock-held, lock-key distinctness) | R | N/A |
| Retention — attachments (cron) | R | N/A | ✓ `CronTest` (2 cases: days=0 kill, lock-held) | R | N/A |
| Portal — token link entry (valid/expired/revoked/mismatched) | ✓ (via L1 token invariant) | N/A | P | P test_05, test_24 | R |
| Portal — logged-in entry (`my-tickets`) | N/A | N/A | R | P test_34 | R |
| Portal — close | R (15) | N/A | R | P test_10 | R |
| Portal — reopen | R (15) | N/A | R | P test_10 | R |
| Portal — attachment upload | R | N/A | P `TicketTest` | P test_23 | R |
| Portal — CSAT submission | R | ✓ AJAX `swh_submit_csat` covered? confirm | ✓ `CsatAjaxTest` (7 cases: nonce, rating bounds, ticket existence, post type, non-closed guard, valid path + hook fires) | P test_33 | R |
| Portal — guest lookup | N/A | N/A | R | ✓ test_35 | R |
| Email — `swh_send_email` template parser, `{if}` blocks, HTML wrapper | ✓ `MailDispatcherInvariantTest` (dispatcher pinning) | N/A | ✓ `EmailTest`, `EmailWrapperTest`, `MailerTest` | R | N/A |
| Email — each of 15 transactional templates renders & dispatches | ✓ `EmailTriggerInvariantTest` (4 cases: subject/body pair shape per call site, every used stem documented, every documented stem has a call site, no dynamic keys) | N/A | P `EmailTest` (template parser only — per-trigger dispatch covered by invariant above) | R (20) | N/A |
| Email — inbound parsing (reply detection, attachment extraction, signature stripping) | R | ✓ `InboundEmailTest` (REST) | ✓ `InboundEmailTest` | R | N/A |
| Inbox — default view per role | R (21) | ✓ `InboxControllerTest` | P | P test_12 | ✓ (light + dark snapshot needed) |
| Inbox — filters (status/assignee/tag/priority/date) | N/A | P `InboxControllerTest` | R | P test_12, test_22, test_37 | R |
| Inbox — search (free-text, ID, requester) | N/A | P `InboxControllerTest` | R | P test_22 | R |
| Inbox — saved/personal views (CRUD + scope) | N/A | ✓ `ViewsControllerContractTest` (403/404/201/200/deleted envelopes) | ✓ `ViewsRepositoryTest` | P test_70 (selector) | R |
| Inbox — pagination | N/A | P `InboxControllerTest` | R | R | R |
| Inbox — Cmd+K palette (nav, ID lookup, action invocation) | N/A | P `PaletteControllerTest` (suggest only) | R | R (22) | R |
| Inbox — deep-link hash routing | N/A | N/A | R | R | N/A |
| Drawer — open from row click / keyboard / deep link | N/A | N/A | N/A | P test_69 (selector-coupled, **rewrite first**) | R |
| Drawer — field edits, status, assign, reply | R (15, 16) | P `TicketControllerTest` | R | P test_06, test_69 | R (each status state) |
| Drawer — attachments view/download | N/A | R | R | P test_23 | R |
| Drawer — history/audit trail | N/A | R | R | R | R |
| Drawer — merge UI | R (13) | R | R | P test_43 | R |

### Tier 3 — quality of life

| Feature | L1 | L2 | L3 | L4 | L5 |
|---|---|---|---|---|---|
| Settings — read/write each setting (62 keys × 7 groups) | N/A | N/A | P `SwhGetOptionTest` (option reading) | P test_18 (persistence smoke) | P (each tab snapshot) |
| Settings — validation on bad input | R (23) | N/A | R | R | R |
| Settings — defaults on fresh install | N/A | N/A | ✓ `LifecycleActionsTest` (option seeding) | R | N/A |
| Reports — Status Breakdown | N/A | N/A | ✓ `ReportingTest` | R | R |
| Reports — Avg Resolution Time | N/A | N/A | ✓ `ReportingTest` | R | R |
| Reports — Weekly Trend | N/A | N/A | ✓ `ReportingTest` | R | R |
| Reports — First Response Time | N/A | N/A | P | ✓ test_40 | R |
| Reports — KPI cards | N/A | N/A | P | R | R |
| Reports — prior comparison (shift / YTD) | N/A | N/A | ✓ `PeriodComparisonTest` | R | R |
| Reports — date-range picker + drill-down | N/A | N/A | R | R | R |
| Dark mode — light/dark/auto across every screen | N/A | N/A | ✓ `VioletThemeTest` (toggle) | P test_59 (selector-coupled) | R (per-screen baselines × 3 themes) |
| Shortcodes — `[submit_ticket]` attrs (`category`, `priority`, `status`, `lookup`) | N/A | N/A | R | ✓ test_36, test_39 | R |
| Shortcodes — `[helpdesk_portal]` | N/A | N/A | R | P test_05 | R |
| Tags / categories / priorities — CRUD + filter | N/A | N/A | R | P test_42, test_37 | R |
| Canned responses | N/A | N/A | R | P test_19 | R |
| Ticket templates (pre-fill) | N/A | N/A | R | ✓ test_39 | R |
| Watchers / CCs | N/A | N/A | R | P test_41 | N/A |
| Humanized timestamps | N/A | N/A | R | ✓ test_29 | N/A |
| Test-email button (AJAX `swh_send_test_email`) | N/A | ✓ AJAX covered? confirm — likely R | R | R | N/A |
| XSS escaping (tickets, replies, settings) | R (24) | R | R | P test_25 | N/A |
| Accessibility — keyboard nav, landmarks, focus, contrast | N/A | N/A | N/A | P test_14 (single sweep — should be per-screen) | N/A |

### Tier 4 — rarely exercised

| Feature | L1 | L2 | L3 | L4 | L5 |
|---|---|---|---|---|---|
| Multisite activation | N/A | N/A | R | R | N/A |
| Auto-updater (plugin-update-checker) | N/A | N/A | R | R | N/A |
| Uninstall — option cleanup honors `swh_delete_on_uninstall` | N/A | N/A | R | R | N/A |
| Public hooks (`swh_ticket_*`, `swh_csat_submitted`, `swh_sla_breached`) fire with documented payloads | ✓ `PublicHookContractInvariantTest` (presence + typo guards) | N/A | P `LifecycleActionsTest` (lifecycle subset only) | R | N/A |
| Public filters (`swh_ticket_priorities`, `swh_ticket_statuses`, `swh_sla_open_statuses`) honor return values | R (25) | N/A | R | R | N/A |
| i18n — string extraction, RTL render | N/A | N/A | R | R | R (RTL snapshot of inbox + drawer + portal) |
| Deprecated v3 filters still emit notice | N/A | N/A | ✓ `DeprecationHelpersTest`, `Pre30FiltersDeprecatedTest` | R | N/A |

---

## Gap notes

Numbered to match parenthesised refs above. Each is a concrete test to write.

1. **Status mutator invariant.** Static/grep test: assert no file outside `includes/helpers.php` and `frontend/class-shortcode.php:239` (initial-create allow-list) contains `update_post_meta(...,\s*['"]_ticket_status['"]`. Implementation: PHPUnit case that scans `simple-wp-helpdesk/` source files with a regex and asserts the match set equals the allow-list.

2. **Status-write allow-list.** Single test that pins the allow-list explicitly: `class-shortcode.php:239` (and any future entry must be added here, forcing PR-time discussion).

3. **Token comparison invariant.** Assert every comparison of `_ticket_token`, `_inbound_webhook_secret`, or `swh_inbound_secret` in source uses `hash_equals(`, never `==` or `===`. Add second test that pinpoints the known call sites (`class-portal.php:65,96,215,309`, `class-ticket.php:52`, `class-email.php:247`) and fails if any are removed without replacement.

4. **Inbound-email E2E.** Drive POST to `/swh/v1/inbound-email` with crafted payloads (new ticket, reply to existing, attachment-bearing, signature-stripped) and assert ticket/comment state. Currently only unit-tested.

5. **File-proxy invariants.** Three separate assertions: (a) `swh_serve_file` calls `hash_equals` on the token; (b) path traversal is blocked via `realpath()` + `strpos` against the upload dir; (c) non-admin requests fail when the token-creation timestamp is older than `swh_token_expiration_days`.

6. **File-proxy REST/integration tests.** Hit the proxy URL with: valid token, expired token, missing token + admin cookie, missing token + technician cookie, traversal path (`../../wp-config.php`). Assert HTTP status, headers, and (for traversal) absence of the file content in the response.

7. **Anti-spam domain tests.** Table-driven. Inputs: each method (honeypot/reCAPTCHA v2/reCAPTCHA Enterprise/Turnstile) × each branch (configured/unconfigured, missing token, invalid token, valid token, network failure). Mock the HTTP layer (reCAPTCHA/Turnstile verify endpoints), never the function under test. Assert `swh_check_antispam` return value plus log line.

8. **Technician capability matrix.** Table-driven on `(role, restrict_to_assigned, assigned_user, action)` → expected permission. Cover: list-own-only when restricted, list-all when not, edit-own-only when restricted, REST `edit_post` failure for unassigned tickets in restricted mode.

9. **Rate-limit invariants + boundary tests.** Invariant: every portal action calls `swh_rate_limit_check( 'portal_<action>_' . $ticket_id, ... )` — keys are per-action and per-ticket. Boundary test: N requests succeed, N+1 returns the documented 429 envelope, requests under a different action key succeed (so close→reopen isn't blocked).

10. **Comment isolation invariant.** Two parts: (a) every `WP_Comment_Query`/`get_comments` call on `helpdesk_ticket` filters `comment_type='helpdesk_reply'`; (b) every frontend render path excludes `_is_internal_note=1`. Pair with E2E that creates an internal note as admin and asserts it does not appear in the portal HTML.

11. **Internal note frontend filter — E2E.** Currently no test asserts the negative case (internal note absent from portal). Required.

12. **Migration end-to-end.** Boot a fixture with flat `swh_*` options, fire `plugins_loaded`, assert grouped options exist and contain migrated values, assert old options preserved (rollback path), assert `swh_db_version` bumped, assert idempotency on second boot.

13. **Merge invariants + tests.** Invariants: source ticket gets `_swh_merged_into`, attachments and `_swh_attachment_orignames` copy via the merge helper at `helpers.php:773-776`, target ticket gets a merge-marker comment, `swh_user_merged` email fires to source requester. Pair with L3 unit test and L4 behavior E2E (test_43 needs upgrading from happy-path to assert all four side effects).

14. **409 envelope contract.** REST integration test: PATCH `/tickets/{id}` with stale `expected_version`, assert HTTP 409, assert JSON body matches the documented shape `{ code, message, data: { current_version, ... } }`. This is the exact bug that escaped v4.0.0.

15. **Reply lifecycle hooks.** Domain test: every reply path (`admin`, `portal`, `inbound`) creates a comment with the correct `comment_type`, `_is_user_reply`, `_attachments`, `_swh_reply_orignames`, fires `swh_ticket_replied($ticket_id, $comment_id, $is_staff_reply)` with correct args, updates `_swh_last_activity_at`, and toggles `_swh_unread` per audience rules.

16. **Assignment side effects.** Domain test: assignment change updates `_ticket_assigned_to`, fires `swh_ticket_assigned($ticket_id, $old, $new)`, sends `em_assigned` email to new assignee, and respects `restrict_to_assigned` for subsequent reads. Cover unassign (old → empty) explicitly.

17. **Cron tests.** For each of the 4 cron jobs: fixture data → trigger hook directly → assert side effects (tickets closed, attachments deleted, SLA emails queued) and locks honored (`swh_*_lock` transients prevent overlap). Mock `wp_mail` to capture emails. No real cron scheduling needed.

18. **CSAT real path.** Replace the stub. Test the AJAX handler with a valid token+rating, assert `_ticket_csat` meta set, `swh_csat_submitted($ticket_id, $rating)` hook fires, and reporting picks it up. Invalid token, out-of-range rating, double-submission all covered.

19. **Email template engine.** Pin behavior: every placeholder (`{ticket_id}`, `{ticket_status}`, `{user_name}`, `{secure_link}`, …) resolves correctly; `{if}` block honored; missing data falls back gracefully; HTML wrapper applied when `swh_email_format=html`; plain when `plain`. Plus an invariant: no source file calls `wp_mail(` directly outside `class-email.php`.

20. **Each transactional email fires once with right template.** Closed by `EmailTriggerInvariantTest` via a source-scan approach (cheaper than 15 fixture-heavy tests, catches the same regression class): every `swh_send_email()` call site uses a matched subject/body pair where the stems are identical, every used stem is on the documented allow-list, and every documented stem has at least one call site (catches both phantom templates and forgotten triggers). The invariant immediately caught a phantom `admin_status` template that was historically in the docs but never had a call site — removed in PR 3.5.

21. **Default-inbox-view invariant.** v4.0.0 shipped with the inbox defaulting to wrong view (later flipped from "Unassigned" to "All"). Pin the default per role with a test that boots a fresh install and asserts the initial REST response shape.

22. **Cmd+K palette E2E.** Behavior test that asserts navigation, ID-search (palette ID-search fix from PR #427), and action invocation, all via keyboard. No DOM selectors.

23. **Settings validation.** Per-field validation tests for: `swh_max_upload_size` rejects non-numeric, `swh_max_upload_count` clamps to 1..50, `swh_sla_warn_hours` < `swh_sla_breach_hours` enforced, email fields validate as email, color/theme enums reject unknown values.

24. **XSS escaping invariants.** Grep test that every echo of user-provided ticket data uses `esc_html`, `esc_attr`, `wp_kses_post`, or equivalent; flag bare `echo $variable` against an allow-list of safe sources. Pair with E2E (test_25) extended to cover ticket title, body, reply body, requester name, custom field values, and SVG filename.

25. **Public-API hook contract tests.** For each documented action/filter in `api-contract.md`: assert it fires with the documented argument count and types (use `add_action` to capture). Filter tests assert the filtered value is honored downstream. Locks the public extension API.

---

## Tooling additions

| Tool | Why | Where |
|---|---|---|
| `wp-phpunit` (WP test framework) | Real DB + WP bootstrap for L2 and DB-touching L3 tests. WP-Mock can't model the things that broke (REST request lifecycle, comment query filters, capability checks). | `tests/Integration/bootstrap.php`, `phpunit-integration.xml` |
| Playwright `toHaveScreenshot()` | Built-in, no SaaS dependency. Baselines committed under `testing/visual/__screenshots__/`. | `testing/visual/` |
| `make test-feature FEATURE=portal-v2` | Filters L1–L5 to one feature row. Forces "done = matrix row green". | `Makefile` |
| `make matrix-lint` | **Today:** parses this document, validates the markdown table structure (6 columns per row, recognised `✓` / `P` / `R` / `N/A` markers), and reports coverage totals by layer and tier. Fails non-zero only on structural problems or unrecognised cell markers. **Not yet diff-aware** — does not (yet) inspect git diff or block PRs based on changed features. Diff-aware enforcement is scoped for PR 6. | `bin/matrix-lint.php`, CI |
| `make visual-update` | Regenerates baselines and prompts for review. Required after intentional UI change. | `Makefile` |
| `wp-browser` (optional) | Alternative L2 stack if `wp-phpunit` integration proves slow. Evaluate during Phase 1. | n/a |

## Migration plan

Six PRs, each independently mergeable. Each PR turns red cells into green for one slice; none rewrites the world.

### PR 1 — Foundation (1 week)

- Add `tests/Integration/` with `wp-phpunit` bootstrap. `make test-integration` target. Wired into `make test-docker`.
- Add `tests/Unit/Invariants/` with gap #1, #2, #3, #5(a), #10(a), #19(invariant), #24(invariant) — seven invariant tests.
- Add `bin/matrix-lint.php` (parses this document) and CI step.
- Update `docs/internal/testing-guide.md` to reference this matrix as the source of truth.

**Exit criteria:** all seven invariants green; matrix linter runs on PR; one merge has flipped at least one cell in this file.

### PR 2 — REST contracts (1 week)

- Move existing REST controller tests into `tests/Integration/Rest/` using `wp-phpunit`.
- Add error-envelope assertions to every endpoint: 401/403/404/409/422/429 each with documented JSON shape.
- Gap #14 (409 envelope) lands here.
- Gap #6 (file-proxy integration tests) lands here.

**Exit criteria:** every row in the matrix L2 column for endpoints is `✓`, not `P`.

### PR 3 — Domain backfill, Tier 1 (1–2 weeks)

- Gaps #7 (anti-spam — three methods), #8 (capability matrix), #9 (rate-limit boundaries), #13 (merge), #15 (reply lifecycle), #16 (assignment), #17 (cron), #18 (CSAT real path), #20 (each email template fires), #25 (public-API hook contracts).
- Replace any test that mocks the function under test.

**Exit criteria:** Tier 1 L3 column is all `✓` or explicit `N/A`.

### PR 4 — Behavior E2E rewrite (2 weeks, can parallelize)

- Establish semantic-selector standard in `docs/internal/testing-guide.md`. Add a lint rule that flags `By.css('.foo-class')` patterns in test files.
- Rewrite the four selector-coupled tests first (test_59 dark mode, test_69 drawer, test_70 views, test_71 inbox default) — proof-of-pattern PRs.
- Sweep section by section: each PR converts 5–10 tests, asserts user-visible outcomes via `getByRole`/`getByLabel`/`getByText`/data attributes (only for genuinely non-semantic items).
- Add gap #4 (inbound-email E2E), #11 (internal-note isolation E2E), #12 (migration E2E), #22 (Cmd+K palette E2E).

**Exit criteria:** zero CSS-class selectors in `testing/scripts/`; every E2E test name is a user-visible behavior sentence.

### PR 5 — Visual regression (1 week)

- Add `testing/visual/` with Playwright screenshot tests.
- Baselines for: inbox (light/dark/auto), drawer (each ticket status × with/without internal note × with/without attachments), portal v2 (logged-out token + logged-in dashboard), each settings tab, reports KPIs, admin theme switcher.
- `make visual-update` workflow and PR-template checkbox.

**Exit criteria:** visual baselines committed; CI runs and diffs on every PR; documented review process for baseline updates.

### PR 6 — Gates & enforcement (3 days)

- `make matrix-lint` becomes blocking on CI.
- PR template adds the five-checkbox block (L1–L5 per touched feature).
- Pre-push hook runs `make test-integration` in addition to existing chain (opt-out via `SWH_SKIP_INTEGRATION=1` for emergencies, logged).
- Add `make test-feature FEATURE=<slug>` and document.

**Exit criteria:** a PR that adds a feature without filling its matrix row fails CI.

### PR 7 — `wp-phpunit` integration harness (3–5 days)

PR 2 shipped REST contract assertions at the mocked-WP layer (controller methods called directly, WordPress functions stubbed via WP-Mock). That catches the v4.0.0-class envelope regressions cheaply but leaves six gaps that only a real WordPress test installation can close. This PR adds the integration harness and lifts the existing contract tests into it.

**What the mocked layer cannot test (the trigger for this PR):**

1. **Route registration & dispatch.** We count `register_rest_route()` calls but never dispatch a real request through `WP_REST_Server`. A wrong path regex, wrong HTTP method binding, or missing route passes today.
2. **`permission_callback` enforcement.** Contract tests bypass the callback by calling the controller method directly. If a permission_callback is changed to `__return_true` or wired to the wrong capability, every test still passes — the API would be wide open in production.
3. **Cookie / nonce / capability stack.** We mock `current_user_can` to return a fixed value. The real auth flow (cookie auth, application passwords, REST nonce header, role inheritance, multisite super-admin) isn't exercised.
4. **`rest_*` filters wrapping responses.** `rest_pre_dispatch`, `rest_request_after_callbacks`, `rest_post_dispatch`, and any plugin or future feature that mutates responses globally don't run.
5. **DB-level invariants.** Real concurrent writes against `TicketVersion::check`, `WP_Query` meta_query joins for the inbox filter, transactional behavior on `wp_insert_comment` failure, capability inheritance through actual roles.
6. **Full request lifecycle.** JSON serialization, response headers (CORS, cache-control), HTTP status propagation. We assert what the controller returns; we don't assert what the HTTP client receives.

**Scope:**

- Add `wp-phpunit` (and `yoast/phpunit-polyfills` for PHPUnit 9 ↔ WP compatibility) to `composer.json` dev deps.
- Add `tests/Integration/bootstrap.php` that loads the WordPress test framework, activates the plugin, and sets up a clean DB per test.
- Add `phpunit-integration.xml` config pointing at `tests/Integration/`.
- Add a Docker service to `docker-compose.test.yml` for the integration DB, or reuse the existing test DB with a separate prefix.
- Add `bin/install-wp-tests.sh` (the standard WP CLI tool, adapted for our paths).
- Lift the existing `tests/Unit/Rest/Contracts/` tests into `tests/Integration/Rest/` as the first consumers, rewriting them to use `WP_UnitTestCase` and `rest_do_request()` instead of WP-Mock. Keep the WP-Mock tests for fast-feedback unit coverage; integration tests run on PR and pre-push.
- Add the six categories of test the mocked layer can't do:
  - `RouteRegistrationTest` — assert every documented endpoint resolves, returns expected status for unauth requests.
  - `PermissionCallbackTest` — assert each endpoint's permission_callback rejects an unauthorized user with the documented envelope.
  - `AuthIntegrationTest` — cookie + nonce flow, application password flow, technician role with and without `restrict_to_assigned`.
  - `ConcurrentVersionTest` — two simultaneous PATCH requests against the same ticket; assert one wins, one gets 409.
  - `MetaQueryTest` — inbox filter with mixed missing/present `_swh_last_activity_at` (the v4.0.0 inbox-default-view regression).
  - `ResponseHeadersTest` — Content-Type, status code, CORS where applicable.
- Add `make test-integration` Makefile target; wire into `make test` and the pre-push hook.
- Add a `Test Integration` CI job alongside the existing PHP gate.

**Exit criteria:** all six L2 categories above are green; the existing PR 2 contract tests run in both `tests/Unit/Rest/Contracts/` (mocked, fast) AND `tests/Integration/Rest/` (real WP, slower); CI completes integration suite in under 3 minutes on the standard runner.

**Trigger to bump priority:** any of the following moves PR 7 ahead of PRs 3–6 in the queue —
- a permission_callback or capability bug ships to production
- a REST routing bug (404 / wrong method) ships to production
- a contract test starts needing more than ~5 lines of `WP_Mock::userFunction` setup just to exercise one method (the mock surface exceeding the real surface)
- a new endpoint genuinely depends on real DB semantics that WP-Mock can't model

## Day-zero policy after migration completes

1. Every PR that adds or modifies a feature must turn its row green, or annotate the cell with `N/A — <reason>`. CI enforces.
2. Every bug fix adds at least one new test that would have caught it. Cell must move from `P`/`R` → `✓`.
3. Every refactor leaves cell states unchanged or improved. Never `✓` → `P`.
4. New feature areas add new rows to this matrix in the same PR that adds the code.

## Open questions to resolve during PR 1

- Confirm `wp-phpunit` over `wp-browser` (speed vs. ergonomics; benchmark on CI runner).
- Confirm in-tree visual baselines vs. external storage (size budget — initial estimate ~30 PNGs × 200KB = 6MB acceptable).
- Decide where `bin/matrix-lint.php` lives in the repo (currently in `bin/` per other internal scripts, but `tools/` is also used).
- Confirm whether `AJAX swh_submit_csat` and `swh_send_test_email` already have tests (inventory says "confirm" — verify in PR 1 and update this doc).

---

**This document is load-bearing.** It is the answer to "did we test the thing?" Keep it honest.
