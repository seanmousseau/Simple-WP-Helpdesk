# API Contract

**Audience:** integrators writing PHP against the plugin, and contributors changing the plugin's public surface.

## What is public, what is internal

**Public** (covered by the SemVer policy below):

- Action hooks documented in `docs/developer/hooks.md`.
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

Listed in `docs/developer/hooks.md`. Enumerated for reference:

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

See `docs/developer/hooks.md` for usage examples and full documentation.

## Filter hooks

Enumerated from `grep -rn "apply_filters(\s*['\"]swh_" simple-wp-helpdesk/`:

| Hook | Signature | Purpose | Location |
|---|---|---|---|
| `swh_ticket_statuses` | `(string[] $statuses) -> string[]` | Override the available ticket status labels. | `includes/helpers.php:278` |
| `swh_ticket_priorities` | `(string[] $priorities) -> string[]` | Override the available priority labels. | `includes/helpers.php:296` |
| `swh_allowed_file_types` | `(string[] $extensions) -> string[]` | Override the upload extension allowlist (default `jpg, jpeg, jpe, png, gif, pdf, doc, docx, txt`). Applied at both submit and reply upload paths. | `frontend/class-shortcode.php:57, 517` |
| `swh_submission_data` | `(array $data) -> array` | Mutate sanitized submission fields before insert. Fires after sanitization, before `swh_pre_ticket_create`. | `frontend/class-shortcode.php:186` |
| `swh_autoclose_threshold` | `(int $days) -> int` | Override the auto-close threshold (default `swh_autoclose_days` option). | `includes/class-cron.php:51` |
| `swh_sla_open_statuses` | `(string[] $statuses) -> string[]` | Statuses considered "open" by the SLA cron. | `includes/class-cron.php:343` |
| `swh_rate_limit_ttl` | `(int $ttl, string $action) -> int` | Override the rate-limit TTL per action. | `includes/helpers.php:785` |
| `swh_parse_template` | `(string $rendered, array $data) -> string` | Post-process a parsed email template body or subject. | `includes/class-email.php:69` |
| `swh_email_headers` | `(string[] $headers, string $to, string $subject) -> string[]` | Add or replace email headers. Canonical place to add Reply-To, BCC, or X-* headers. | `includes/class-email.php:109` |

## REST endpoints

| Method | Path | Auth | Body | Response |
|---|---|---|---|---|
| `POST` | `/wp-json/swh/v1/inbound-email` | `Authorization: Bearer <swh_inbound_secret>` header. `permission_callback` is `__return_true` (auth enforced inside the handler). | Parsed-email JSON; the handler reads `from`, `subject`, `body`. Subject must contain `[TKT-XXXX]`. Sender email is compared to `_ticket_email` of the matched ticket via `hash_equals`. | `200` on accepted reply; `4xx` on auth / parse / match failure. |

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
