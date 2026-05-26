# API Contract

**Audience:** integrators writing PHP against the plugin, and contributors changing the plugin's public surface.

## What is public, what is internal

**Public** (covered by the SemVer policy below):

- Action hooks documented in `docs/hooks.md`.
- Filter hooks listed under "Filter hooks" in this doc.
- The REST endpoint `POST /wp-json/swh/v1/inbound-email`.
- The `nopriv` AJAX endpoint `wp_ajax_nopriv_swh_submit_csat`.
- Shortcodes `[submit_ticket]` and `[helpdesk_portal]` with their documented attributes.
- Constants `SWH_VERSION`, `SWH_PLUGIN_DIR`, `SWH_PLUGIN_URL`, `SWH_PLUGIN_FILE`.
- Helper functions documented with `@since` in PHPDoc and called from outside their defining file (e.g. `swh_get_secure_ticket_link`, `swh_send_email`, `swh_set_ticket_status`, `swh_render_empty_state`).

**Internal** (may change in any release):

- Functions and classes not listed above. Even if they have `swh_` prefix.
- Database schema beyond what `data-dictionary.md` documents.
- Admin AJAX endpoints (`wp_ajax_swh_merge_ticket`, `wp_ajax_swh_send_test_email`, `wp_ajax_swh_report_data`) — these are admin-only and gated by `manage_options`.
- CSS class names beyond those in `component-inventory.md`.
- The "advisory" group argument to `swh_get_option()` — it is intentionally ignored in v3.7 and consumed by v4.0 schema migration.

If you depend on an internal API, pin to a version and read the changelog before upgrading.

## Versioning policy

[SemVer](https://semver.org/). Breaking-change taxonomy:

| Change | Bump |
|---|---|
| Removing a public hook | MAJOR |
| Renaming a public hook | MAJOR |
| Changing a public hook signature (arg count, arg order, arg type) | MAJOR |
| Adding a public hook | MINOR |
| Removing a setting | MAJOR (operator-visible) |
| Changing a setting's default in a way that alters runtime behaviour | MAJOR |
| Adding a setting | MINOR |
| Changing a REST endpoint URL | MAJOR |
| Adding a REST endpoint or new query/body parameter | MINOR |
| Changing a shortcode attribute default | MAJOR |
| Adding a shortcode attribute | MINOR |
| Removing a meta key without an upgrade routine | MAJOR |
| Adding a meta key | MINOR |
| Bug fixes / internal refactor with no external surface change | PATCH |

When MAJOR is unavoidable, the previous behaviour is wrapped in `simple-wp-helpdesk/includes/deprecations.php` so old hooks fire alongside new ones for at least one MAJOR cycle. See `docs/developer/deprecations.md`.

## Action hooks

Listed in `docs/hooks.md`. Enumerated for reference:

| Hook | Signature | Since |
|---|---|---|
| `swh_pre_ticket_create` | `(array $data)` | 2.1.0 |
| `swh_ticket_created` | `(int $ticket_id, array $data)` | 2.1.0 |
| `swh_ticket_status_changed` | `(int $ticket_id, string $old_status, string $new_status)` | 3.7.0 |
| `swh_ticket_closed` | `(int $ticket_id, string $previous_status)` | 3.7.0 |
| `swh_ticket_reopened` | `(int $ticket_id, string $previous_status)` | 3.7.0 |
| `swh_ticket_replied` | `(int $ticket_id, int $comment_id, bool $is_staff_reply)` | 3.7.0 |
| `swh_ticket_assigned` | `(int $ticket_id, int $old_user_id, int $new_user_id)` | 3.7.0 |
| `swh_csat_submitted` | `(int $ticket_id, int $rating)` | 3.0.0 |
| `swh_sla_breached` | `(int $ticket_id, int $minutes_over)` | 3.0.0 |

See `docs/hooks.md` for usage examples and full documentation.

## Filter hooks

Enumerated from `grep -rn "apply_filters(\s*['\"]swh_" simple-wp-helpdesk/`:

| Hook | Signature | Purpose | Since | Deprecated | Location |
|---|---|---|---|---|---|
| `swh_ticket_statuses` | `(string[] $statuses) -> string[]` | Override the available ticket status labels. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/helpers.php` |
| `swh_ticket_priorities` | `(string[] $priorities) -> string[]` | Override the available priority labels. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/helpers.php` |
| `swh_allowed_file_types` | `(string[] $extensions) -> string[]` | Override the upload extension allowlist (default `jpg, jpeg, jpe, png, gif, pdf, doc, docx, txt`). Applied at both submit and reply upload paths. | pre-3.0 | 4.0 (removal target: 4.2) | `frontend/class-shortcode.php` (2 sites) |
| `swh_submission_data` | `(array $data) -> array` | Mutate sanitized submission fields before insert. Fires after sanitization, before `swh_pre_ticket_create`. | pre-3.0 | 4.0 (removal target: 4.2) | `frontend/class-shortcode.php` |
| `swh_autoclose_threshold` | `(int $days) -> int` | Override the auto-close threshold (default `swh_autoclose_days` option). | pre-3.0 | 4.0 (removal target: 4.2) | `includes/class-cron.php` |
| `swh_rate_limit_ttl` | `(int $ttl, string $action) -> int` | Override the rate-limit TTL per action. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/helpers.php` |
| `swh_parse_template` | `(string $rendered, array $data) -> string` | Post-process a parsed email template body or subject. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/class-email.php` |
| `swh_email_headers` | `(string[] $headers, string $to, string $subject) -> string[]` | Add or replace email headers. Canonical place to add Reply-To, BCC, or X-* headers. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/class-email.php` |
| `swh_sla_open_statuses` | `(string[] $statuses) -> string[]` | Statuses considered "open" by the SLA cron. | post-3.0 | — | `includes/class-cron.php` |

**Note:** 8 of the 9 filters listed above were wrapped in `swh_apply_deprecated_filter()` in v4.0.0 (#360). They continue to fire for existing listeners (back-compat preserved) but emit `_doing_it_wrong` notices in `WP_DEBUG` environments. Earliest removal: v4.2. `swh_sla_open_statuses` is post-3.0 and remains supported.

## REST envelope contract

All `swh/v1` endpoints follow a uniform envelope shape. JS clients (admin-inbox, portal-v2, reports-v2) rely on these shapes — changing one is a breaking change that must update consumers in the same PR. Contract tests in `tests/Unit/Rest/Contracts/` lock these shapes.

| Status | Envelope | Used by | Notes |
|---|---|---|---|
| `200` / `201` | resource-keyed: `{ view }`, `{ views }`, `{ tickets }`, full ticket payload | success | The HTTP status code carries the success bit; the body carries the resource. No `success: true` redundancy. |
| `200` (delete) | `{ deleted: bool }` | DELETE `/views/{id}` | Distinguishes "operation rejected" (would be 403) from "already gone" (`deleted: false` + 404). |
| `200` (bulk) | `{ succeeded: int[], conflicts: [{ id, version_now, error? }] }` | POST `/bulk-action` | Per-ticket conflicts carry `version_now`; `error` field is present only for non-version conflicts (`not_found`, `forbidden`). Absence of `error` ⇒ stale-version conflict, safe to retry. |
| `400` | `{ error: <code>, ...extras }` | validation failures | `extras` may include caps like `max`. Example: `{ error: 'too_many_tickets', max: 200 }`. |
| `403` | `{ error: 'forbidden' }` | capability failures | Returned by `ViewsController` when a non-admin attempts a global-scope mutation. |
| `404` | `{ error: 'not_found' }` | missing ticket / view | Same shape used by `TicketController` and `ViewsController` update paths. |
| `409` | `{ error: 'version_conflict', current: <full ticket payload> }` | stale optimistic-lock | The `current` payload lets the React inbox reconcile without an extra round-trip. `current.version` reflects server state; client retries with that value. |
| `500` | `{ error: <code> }` | internal failure | Codes in use: `comment_insert_failed`, `save_failed`. |

Error envelopes have exactly the keys named above — no extras unless documented per-endpoint (`extras` column in the bulk row). The contract test base asserts both presence of required keys AND absence of undocumented ones.

## REST endpoints

> **The `helpdesk_ticket` CPT is intentionally NOT registered with `show_in_rest = true`.** The standard `/wp-json/wp/v2/helpdesk_ticket` routes do **not** exist for this post type — any request to them returns `404 rest_no_route`. Read/write access is via the plugin's custom `/swh/v1/...` endpoints (below) or via WP-CLI / direct DB access. This is by design — exposing the CPT through the default REST surface would bypass the plugin's `permission_callback` per-ticket gates. (#441)

> **All `swh/v1` endpoints authenticate via cookie OR application password — not raw HTTP Basic Auth with a regular WP password.** A curl invocation using a regular admin password as the Basic Auth secret will return `401 rest_forbidden` even when the user has full admin capabilities. For programmatic / integration access, operators MUST create an [application password](https://wordpress.org/documentation/article/application-passwords/) (Users → Profile → Application Passwords) and use that as the Basic Auth password. Example:
> ```bash
> curl -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
>      https://example.com/wp-json/swh/v1/palette/suggest?q=test
> ```
> (#442)

| Method | Path | Auth | Body | Response |
|---|---|---|---|---|
| `POST` | `/wp-json/swh/v1/inbound-email` | `Authorization: Bearer <swh_inbound_secret>` header. `permission_callback` is `__return_true` (auth enforced inside the handler). | Parsed-email JSON; the handler reads `from`, `subject`, `body`. Subject must contain `[TKT-XXXX]`. Sender email is compared to `_ticket_email` of the matched ticket via `hash_equals`. | `200` on accepted reply; `4xx` on auth / parse / match failure. |
| `POST` | `/wp-json/swh/v1/bulk-action` | `edit_posts` | `{ action, params, ticket_ids[], version_map{} }` | `{ succeeded[], conflicts: [{ id, version_now }] }`. Cap: 200 tickets. Introduced 4.0.0 #353. |
| `GET` | `/wp-json/swh/v1/palette/suggest` | `edit_posts` | — | `{ tickets: [{ id, title, status }] }`. Param `q` (string). Up to 8 results. Introduced 4.0.0 #351. |
| `GET` | `/wp-json/swh/v1/views` | `edit_posts` | — | `{ views: [{ id, name, scope, filters, sort, columns }] }`. Introduced 4.0.0 #352. |
| `POST` | `/wp-json/swh/v1/views` | `edit_posts` (personal) / `manage_options` (global) | `{ name, scope, filters, sort, columns }` | `{ view }` at 201. Introduced 4.0.0 #352. |
| `PATCH` | `/wp-json/swh/v1/views/{id}` | `edit_posts` (personal) / `manage_options` (global) | `{ scope, patch }` | `{ view }` at 200. Introduced 4.0.0 #352. |
| `DELETE` | `/wp-json/swh/v1/views/{id}` | `edit_posts` (personal) / `manage_options` (global) | — | `{ deleted: bool }`. Param: `scope`. Introduced 4.0.0 #352. |

Registered at `simple-wp-helpdesk/simple-wp-helpdesk.php:213`. Handler at `simple-wp-helpdesk/includes/class-email.php:222`.

**Idempotency:** the handler does not de-duplicate inbound messages; the upstream MTA / inbound-parse provider is responsible for at-most-once delivery. The handler is safe to call repeatedly — it will insert a reply comment each time.

**Authorization header note:** Apache running in front of PHP-FPM strips the `Authorization` header by default. Operators must configure `.htaccess` or vhost to pass it through, or use a server-internal call path. Test section 47 documents the bypass used in CI (calling `swh_handle_inbound_email()` directly via `wp eval`).

## AJAX endpoints

| Action | Auth | Purpose |
|---|---|---|
| `wp_ajax_nopriv_swh_submit_csat` (also `wp_ajax_swh_submit_csat`) | nonce | Records a 1–5 CSAT rating into `_ticket_csat` post meta. **Public surface.** | 
| `wp_ajax_swh_merge_ticket` | `manage_options` + nonce | Admin-only ticket merge. **Internal.** |
| `wp_ajax_swh_send_test_email` | `manage_options` + nonce | Admin-only test email sender. **Internal.** |
| `wp_ajax_swh_report_data` | `manage_options` + nonce | Reporting data fetch with transient caching. **Internal.** |

> **Reports v2 (#359):** `swh_report_data` action accepts optional params:
> - `range_start`, `range_end` (ISO `Y-m-d` dates) — constrain the reporting window.
> - `compare_to_prior` (`1`/`0`; default `1`) — compute prior-period data.
>
> Response shape extends with `range`, `prior`, and `prior_range` keys when range params are supplied.
> Existing callers that ignore those keys keep working. Transient cache is bypassed when a custom range is provided.

Registered:

- `class-ticket.php:391-392` — CSAT.
- `simple-wp-helpdesk.php:173` — merge.
- `admin/class-settings.php:1035` — test email.
- `admin/class-reporting.php:12` — report data.

## Shortcodes

Both shortcodes are dispatched by the same handler `swh_submit_ticket_shortcode()` in `frontend/class-shortcode.php`.

| Shortcode | Attribute | Default | Effect |
|---|---|---|---|
| `[submit_ticket]` | `show_priority` | `yes` | Show/hide the priority select field. |
| `[submit_ticket]` | `default_priority` | `Medium` (from `swh_default_priority`) | Pre-select a priority value. |
| `[submit_ticket]` | `default_status` | `Open` (from `swh_default_status`) | Pre-set the new ticket status on save. |
| `[submit_ticket]` | `show_lookup` | `yes` | Show/hide the "lost your link?" lookup form below the submit form. |
| `[helpdesk_portal]` | (same attributes) | | When the URL lacks a `token`, renders either the My Tickets dashboard (logged-in users) or the lookup form (guests). |

> **Portal v2 (#354):** Portal v2 moves reply/close/reopen from inline PHP POST handling (same page) to dedicated `admin-post.php` handlers registered as both `admin_post_nopriv_*` and `admin_post_*`: `swh_portal_reply`, `swh_portal_close`, `swh_portal_reopen`, `swh_portal_csat`. All require `ticket_id` + `token` (verified via `hash_equals`) and `swh_portal_reply_nonce` (verified via `wp_verify_nonce`). On success, each handler does `wp_safe_redirect` back to the portal URL. The reply form field is `reply_body`; the nonce field is `swh_portal_reply_nonce`. The v2 JS sends the reply via `fetch()` optimistically and falls back to a standard full-page form submit if JS is unavailable.

## Constants

| Constant | Type | Source of truth |
|---|---|---|
| `SWH_VERSION` | string | `simple-wp-helpdesk/simple-wp-helpdesk.php:19` |
| `SWH_PLUGIN_DIR` | string | bootstrap |
| `SWH_PLUGIN_URL` | string | bootstrap |
| `SWH_PLUGIN_FILE` | string | bootstrap |
| `SWH_ICON_1X`, `SWH_ICON_2X`, `SWH_MENU_ICON` | string | bootstrap (icon URLs) |

These are guaranteed across releases. Renaming or removing any of them is MAJOR.

## Deprecation policy

When a public hook or function must be removed:

1. The new replacement ships first, in a MINOR release.
2. The old name continues to fire (or function) for at least one MAJOR cycle, implemented in `simple-wp-helpdesk/includes/deprecations.php`.
3. Each shim calls `_deprecated_hook()` or `_deprecated_function()` once per page load (WP's own throttle) so debug logs surface usage.
4. The shim is removed only in the MAJOR release **after** the introduction MAJOR.

See `docs/developer/deprecations.md` for the current shim list.

## Update protocol

Update this doc when:
- Any item in the "public surface" lists above is added, removed, renamed, or changes signature.
- A new filter hook is introduced — add a row to the filter table.
- The SemVer taxonomy is amended.
- A deprecation enters or leaves `includes/deprecations.php`.
