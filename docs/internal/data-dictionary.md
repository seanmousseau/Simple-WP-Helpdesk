# Data Dictionary

**Audience:** plugin contributors, data-protection / audit reviewers.

Every persistent piece of state the plugin owns. If a key is not in this doc and not owned by WordPress core, it does not exist.

## Storage map overview

The plugin uses **no custom database tables**. State lives in standard WordPress storage:

| Concept | Storage primitive | Table |
|---|---|---|
| Ticket | CPT (`helpdesk_ticket`) | `wp_posts` |
| Reply / internal note | Comment (`comment_type = 'helpdesk_reply'`) | `wp_comments` |
| Ticket field | Post meta | `wp_postmeta` |
| Reply field | Comment meta | `wp_commentmeta` |
| Plugin setting | Option | `wp_options` (autoloaded by default) |
| Cache / lock | Transient | `wp_options` (or object cache if available) |
| Rate-limit lock | Option `swh_rl_<hash>` (NOT a transient — survives cache flush) | `wp_options` |
| Category | Taxonomy term | `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships` |
| Capability | Role (added at activation, removed at uninstall) | `wp_options` (`wp_user_roles`) |

See `design-document.md` invariant #1 for the rationale.

## Post meta keys

Cited from `grep -rEon "(update|get|delete)_post_meta\(...\)"` on `simple-wp-helpdesk/`. All keys begin with `_` (hidden meta).

| Key | Type | Required? | Purpose | Since |
|---|---|---|---|---|
| `_ticket_status` | string | yes | Current status label (free string matching one of the configured statuses). Written only via `swh_set_ticket_status()` except for initial-create. | 1.0 |
| `_ticket_priority` | string | yes | Priority label from `swh_get_priorities()`. | 1.0 |
| `_ticket_name` | string | yes | Client display name from submission. | 1.0 |
| `_ticket_email` | string | yes | Client email address. Compared to inbound webhook sender. Compared to `wp_get_current_user()->user_email` for My Tickets. | 1.0 |
| `_ticket_token` | string | yes | Random token for portal access. Compared with `hash_equals`. | 1.0 |
| `_ticket_token_created` | int (unix ts) | no (grandfathered) | When the token was generated. Absence on a pre-v1.9.0 ticket means "never expire". | 1.9.0 |
| `_ticket_url` | string | optional | Stored permalink of the submission page; fallback for `swh_get_secure_ticket_link()` when `swh_ticket_page_id` is unset. | 1.0 |
| `_ticket_uid` | string | yes | Human-readable ticket UID (`TKT-XXXX`). Used in subjects, merge breadcrumbs, inbound webhook subject parsing. | 1.0 |
| `_ticket_assigned_to` | int (user id) | no | Assignee user ID. `0` = unassigned. Set by `swh_apply_assignment_rules()` or manual admin edit. | 1.0 |
| `_ticket_attachments` | string[] (URLs) | no | URLs of root-level attachments (uploaded with the new ticket). | 1.0 |
| `_ticket_attachment_url` | string | legacy | Pre-v2 single-attachment field. Still referenced for migration. | 1.0 |
| `_ticket_attachment_id` | int | legacy | Pre-v2 single-attachment field. | 1.0 |
| `_swh_attachment_orignames` | array<string,string> | optional | Map of attachment URL → original filename for new-ticket uploads. Falls back to `basename($url)` if missing (pre-v2.3.0). | 2.3.0 |
| `_resolved_timestamp` | int (unix ts) | optional | Set when ticket transitions to the resolved status; used by the auto-close cron to detect tickets eligible for auto-close. | 1.0 |
| `_ticket_first_response_at` | int (unix ts) | optional | Unix timestamp of the first staff reply. Used by first-response-time reporting. | 3.0.0 |
| `_ticket_sla_status` | string (`warn`/`breach`) | optional | Latest SLA evaluation; set by `swh_sla_check_event`. | 3.0.0 |
| `_ticket_cc_emails` | string (comma-sep) | optional | Comma-separated CC/watcher addresses. Read via `swh_get_cc_emails()` which sanitizes and validates. | 3.0.0 |
| `_ticket_template` | string | optional | Label of the ticket template selected at submission. | 3.0.0 |
| `_ticket_csat` | int (1–5) | optional | Client satisfaction rating; not set if the client dismisses the prompt. Written by the `swh_submit_csat` AJAX handler. | 3.0.0 |
| `_swh_unread` | string (`'1'`) | optional | Set to `'1'` when a client posts a reply; cleared when admin opens the ticket. Pairs with the `swh_unread_count` transient (see invariant #17). | 3.1.0 |
| `_edit_lock` | string | WP core | WordPress's own concurrent-edit lock. Not owned by this plugin. | WP core |

## Comment meta keys

Comments carry these meta when `comment_type='helpdesk_reply'`:

| Key | Type | Required? | Purpose | Since |
|---|---|---|---|---|
| `_is_internal_note` | string (`'1'`) | optional | Staff-only note. **Must be filtered out** of any frontend renderer. | 2.0.0 |
| `_is_user_reply` | string (`'1'`) | optional | Marks a portal-side or inbound-email reply (i.e. came from the client, not staff). Used by reporting to attribute first-response time correctly. | 2.0.0 |
| `_swh_reply_orignames` | array<string,string> | optional | Per-comment map of attachment URL → original filename for replies and reopens. Falls back to `basename($url)` if missing. | 2.3.0 |
| `_attachments` | string[] (URLs) | optional | Attachment URLs on the reply comment. | 1.0 |

## Options

Enumerated in `simple-wp-helpdesk/includes/helpers.php:19-121` (`swh_get_defaults()`). See `config-reference.md` for the exhaustive list with defaults, types, and admin UI location.

Categories:

- **General:** statuses, priorities, autoclose, max upload.
- **Assignment & Routing:** default assignee, fallback email, helpdesk page, token TTL, technician restriction.
- **Email Format:** format, logo URL.
- **Anti-Spam:** method, reCAPTCHA keys/type, Turnstile keys.
- **Data Retention & Tools:** retention windows, delete-on-uninstall.
- **Email Templates:** 14 subject/body pairs (see `config-reference.md`).
- **Messages:** success/error strings shown to clients.
- **Canned Responses:** structured array.
- **Ticket Templates:** structured array.
- **SLA:** warn/breach hours, notify email.
- **Assignment Rules:** JSON array of `{category_term_id, assignee_user_id}`.
- **Inbound Email:** Bearer secret.
- **Frontend Portal Appearance:** theme preference.

Additionally:

- `swh_db_version` — installed plugin version; updated by `swh_run_upgrade_routine()` at `class-installer.php:147`. **Not** in `swh_get_defaults()` and **excluded** from bulk operations (`swh_get_all_option_keys()` filters it out).
- `swh_comment_type_v2`, `swh_tech_caps_v2` — one-time migration flags. Cleaned on uninstall.

## Transients

| Key | TTL | Purpose | Set by | Cleared by |
|---|---|---|---|---|
| `swh_unread_count` | 5 min | Cached count of tickets with `_swh_unread=1`. | `swh_get_unread_reply_count()` at `helpers.php:740-764`. | Any write to `_swh_unread` (see invariant #17). |
| `swh_report_avg_resolution_time` | `HOUR_IN_SECONDS` | Reporting cache. | `admin/class-reporting.php`. | Hourly expiration. |
| `swh_report_first_response_time` | `HOUR_IN_SECONDS` | Reporting cache. | `admin/class-reporting.php`. | Hourly expiration. |
| `swh_report_<other types>` | `HOUR_IN_SECONDS` | Reporting cache for `status_breakdown`, `weekly_trend`, `kpi`. | `admin/class-reporting.php`. | Hourly expiration. |
| `swh_lock_clear_<post_id>` | 3 min | Suppresses unread-row highlight after admin opens a ticket. | `admin/class-ticket-editor.php:455`. | 3-minute expiration. |
| `swh_lock_autoclose` | cron-duration | Single-instance lock for the auto-close cron. | `class-cron.php:56`. | Cron handler on exit. |
| `swh_lock_retention_att` | cron-duration | Lock for the attachment-retention cron. | `class-cron.php:145`. | Cron handler on exit. |
| `swh_lock_retention_tkt` | cron-duration | Lock for the ticket-retention cron. | `class-cron.php:280`. | Cron handler on exit. |
| `swh_lock_sla` | cron-duration | Lock for the SLA-check cron. | `class-cron.php:330`. | Cron handler on exit. |

**`swh_rl_*` are NOT transients** — they are stored as options with a TTL field embedded in the value. This is intentional: transients are flushed by aggressive object-cache resets that some hosts run. See `swh_is_rate_limited()` at `helpers.php:777-794`.

## Taxonomy

| Slug | Type | REST | Rewrite | Show admin column | Registered at |
|---|---|---|---|---|---|
| `helpdesk_category` | hierarchical | no (`show_in_rest=false`) | no (`rewrite=false`) | yes | `class-installer.php:311-338` |

Assign with `wp_set_post_terms( $ticket_id, $term_ids, 'helpdesk_category' )`. Read with `wp_get_post_terms( $ticket_id, 'helpdesk_category', $args )`. Used by `swh_apply_assignment_rules()` to look up routing rules.

## Capabilities

| Capability | Granted to roles | Controls |
|---|---|---|
| `read` | Technician (added at activation) | basic WP login |
| `edit_posts` | Technician | base post editing |
| `edit_others_posts` | Technician | edit tickets created by others |
| `edit_published_posts` | Technician | edit tickets after publish (all of them; v1.0 status was a meta field, not post_status) |
| `publish_posts` | Technician | publish tickets |
| `delete_posts` | Technician | delete tickets (admin UI) |
| `upload_files` | Technician | attach files to replies |

The `Technician` role is created at `class-activate()` and removed at `class-uninstall()` (see `class-installer.php:18-41, 234-239`). Existing users in the role are reassigned to the site's default role (`get_option('default_role', 'subscriber')`) before role removal.

`manage_options` (WordPress core) gates all admin / settings / merge / test-email / reporting actions.

## Migration policy

- Meta keys are **load-bearing** and follow the same versioning as code (see `api-contract.md`).
- **Adding** a new meta key is MINOR. Document it here in the same PR.
- **Renaming** or **removing** a meta key is MAJOR. The change ships with an upgrade routine in `swh_run_upgrade_routine()` at `class-installer.php:87-148` that migrates old → new for existing installs.
- **Adding** a comment meta is MINOR.
- **Adding** an option is MINOR. **Adding** to `swh_get_defaults()` causes `add_option()` to seed it on next admin page load (line 113).
- **Removing** an option is MAJOR. Add a `delete_option()` in the upgrade routine.

## What is intentionally NOT here

- **No custom tables.** See `design-document.md` invariant #1.
- **No foreign keys.** WordPress does not enforce them.
- **No application-managed sequences.** Use `wp_insert_post()`.
- **No `register_post_meta()`.** Showing meta in REST is gated separately and not desired for these keys.
- **No JSON columns.** Where structured data is needed (assignment rules, canned responses, ticket templates, attachment origname maps) it is stored as a serialised PHP array via WP's standard option/meta serialization. Read/write is via `get_*` / `update_*` only — never raw `unserialize()` on the raw column value.

## Update protocol

Update this doc when:
- A new post meta, comment meta, option, transient, capability, or taxonomy term is added — bump the relevant table.
- An existing key is renamed or removed — strike the old row, note the migration version, document the upgrade routine that converts existing data.
- The migration policy itself is amended.
