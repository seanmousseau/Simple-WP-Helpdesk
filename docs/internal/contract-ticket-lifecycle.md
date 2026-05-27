# Contract: Ticket Lifecycle

The lifecycle of a ticket from submission through resolution, closure, reopening, and deletion. This is **the** authoritative description of what a ticket *is* and how it moves between states.

> Touch this contract before changing how tickets are created, statused, assigned, merged, or deleted.

---

## Storage shape

| Element | Storage | Notes |
|---|---|---|
| The ticket itself | `wp_posts` row with `post_type = helpdesk_ticket` | Title = summary, content = description |
| Status | `wp_postmeta._ticket_status` | String, matches one of the labels in `swh_get_statuses()` |
| Priority | `wp_postmeta._ticket_priority` | String, matches `swh_get_priorities()` |
| Client name | `wp_postmeta._ticket_name` | Sanitized text |
| Client email | `wp_postmeta._ticket_email` | Sanitized email |
| Unique ID | `wp_postmeta._ticket_uid` | `TKT-<numeric>` format. Used by inbound webhook + URLs |
| Portal token | `wp_postmeta._ticket_token` | 20-char alphanumeric (`wp_generate_password`). Rotates on lookup |
| Token created-at | `wp_postmeta._ticket_token_created` | Unix timestamp; expiry computed against `swh_token_expiration_days` (default 90; 0 disables). Pre-v1.9.0 tickets without this meta are grandfathered and never expire. |
| Assignee | `wp_postmeta._ticket_assigned_to` | WP user ID (0 = unassigned) |
| CSAT rating | `wp_postmeta._ticket_csat` | Int 1–5, or absent |
| Replies (public) | `wp_comments` with `comment_type = helpdesk_reply` | Includes client, technician, and inbound-email replies |
| Internal notes | `wp_comments` with `comment_type = helpdesk_reply` **and** `comment_meta._is_internal_note = 1` | Must be filtered from frontend |
| Attachment IDs | `wp_postmeta._ticket_attachments` | Serialized array of attachment post IDs |
| Unread flag | `wp_postmeta._swh_unread` | `'1'` when client has replied since last admin view |

Full meta catalog: `data-dictionary.md`.

---

## Status machine

Statuses are operator-configurable but always include four logical states (whose labels are also configurable in **Settings → General**):

| Logical state | Default label | Behavior |
|---|---|---|
| Open | `Open` | Default for new tickets and for reopened tickets |
| In Progress | `In Progress` | Operator-driven |
| Resolved | `Resolved` | Triggers auto-close countdown (if enabled); shows the close block in the portal |
| Closed | `Closed` | Disables reply form; shows the reopen block in the portal |

The "Resolved" and "Closed" labels are tracked in `swh_resolved_status` and `swh_closed_status` options so the plugin can match against operator-renamed labels.

### Transition rules

- Any → any is allowed structurally. Status validation is by **label match**, not by graph.
- Initial creation: status = `swh_default_status` (default `Open`).
- Client `Close` action on a Resolved ticket: status → `swh_closed_status`.
- Client `Reopen` action on a Closed ticket: status → `swh_reopened_status` (default `Open`).
- Inbound email reply on Resolved/Closed: status → first label in `swh_get_statuses()` (default `Open`).
- Auto-close cron: Resolved → Closed after `swh_autoclose_days` days of inactivity (0 disables).

### Entry points that mutate ticket fields

Every entry point below ultimately routes through `swh_update_ticket_fields()` (invariant #19 in `design-document.md`). The helper validates input + dispatches `swh_set_ticket_status()` / `swh_ticket_assigned` / resolved-timestamp bookkeeping.

| Entry point | File | Auth |
|---|---|---|
| Admin save-post (`save_post_helpdesk_ticket`) | `admin/class-ticket-editor.php` (`swh_save_ticket_data`) | WP nonce + `edit_post` capability |
| Internal REST drawer (`PATCH /swh/v1/tickets/{id}`) | `src/Rest/TicketController.php` | Logged-in `edit_posts` capability |
| **Public REST v1** (`POST /swh/v1/tickets`, `PATCH /swh/v1/tickets/{id}`) | `src/Rest/V1/TicketsController.php` | `swh_rest_require( 'write' )` — logged-in `edit_posts` or `write`-scoped API key |
| Bulk action controller (`POST /swh/v1/bulk-action`) | `src/Rest/BulkController.php` | Logged-in `edit_posts` |
| Inbound email webhook | `simple-wp-helpdesk.php` + `class-email.php` | Bearer token (`swh_inbound_secret`) |
| Portal close / reopen | `frontend/class-portal.php` (admin-post handlers) | `_ticket_token` (`hash_equals`) + WP nonce |
| Auto-close cron | `includes/class-cron.php` | n/a (server-side) |

---

## Invariants

**These hold or the system breaks. Listed full in `design-document.md` — restated here because they're load-bearing for this contract.**

1. **`swh_set_ticket_status( int $ticket_id, string $new_status ): void`** is the ONLY correct way to mutate `_ticket_status` after creation. Direct `update_post_meta()` bypasses `swh_ticket_status_changed` / `swh_ticket_closed` / `swh_ticket_reopened` hooks, the unread-flag logic, the CSAT prompt trigger, and the auto-close timer. Initial creation in `swh_create_ticket()` is the documented exception.

2. **`swh_send_email( $to, $subject_key, $body_key, $data, $attachments=array(), $ticket_id=0 ): bool`** is the ONLY correct mail dispatcher for ticket-related mail. Direct `wp_mail()` calls skip the template parser, conditional `{if}` blocks, the HTML wrapper, and the watcher-CC injection.

3. **Portal URL ordering:** when creating or re-creating a portal URL, store `_ticket_token` (and `_ticket_token_created`) **before** calling `swh_get_secure_ticket_link( $ticket_id )`. Otherwise the helper returns `false` and the email falls back to a wrong URL.

4. **Token comparison uses `hash_equals()`**, never `==`.

5. **Rate-limit keys are per-action with ticket_id appended**: `portal_close_<id>`, `portal_reopen_<id>`, `portal_reply_<id>`, `submit` (no id). Shared keys break the close-then-immediate-reopen flow.

6. **Comment-type isolation:** the frontend MUST filter `helpdesk_reply` comments out of unrelated comment listings, and MUST hide `_is_internal_note=1` comments from non-staff viewers.

---

## Hooks fired

Documented in `docs/hooks.md`. Summary per lifecycle event:

| Event | Action(s) fired |
|---|---|
| Pre-create | `swh_pre_ticket_create( $data )` |
| Created | `swh_ticket_created( $ticket_id, $data )` |
| Any reply inserted | `swh_ticket_replied( $ticket_id, $comment_id, $is_staff_reply )` |
| Status changed | `swh_ticket_status_changed( $ticket_id, $old, $new )` |
| Transitioned to closed | `swh_ticket_closed( $ticket_id, $previous_status )` |
| Transitioned from closed | `swh_ticket_reopened( $ticket_id, $previous_status )` |
| Assignee changed | `swh_ticket_assigned( $ticket_id, $old_user_id, $new_user_id )` |
| SLA breach (first time) | `swh_sla_breached( $ticket_id, $minutes_over )` |
| CSAT submitted | `swh_csat_submitted( $ticket_id, $rating )` |

Signatures are public API; see `api-contract.md`.

---

## Failure modes

| Scenario | Behavior |
|---|---|
| Submission without nonce | Rejected before any insert |
| Submission while rate-limited | Rejected with a friendly message; no DB write |
| Submission with disallowed file type | Submission proceeds, file silently skipped |
| Token expired on portal load | Lookup form rendered; focus moved to the email field |
| `swh_set_ticket_status` called with current status | No-op; no hooks fired |
| `wp_insert_post` fails inside `swh_create_ticket` | Rolls back any pre-inserted meta; user sees error |

---

## What integrators may rely on

- The hook signatures listed above will not change within v4.x.
- The meta key names listed in the Storage shape table will not change within v4.x.
- `helpdesk_ticket` will remain a CPT with `'public' => false`.
- `swh_set_ticket_status` will remain the sole status-mutation entry point.
- Comment-type `helpdesk_reply` will remain the storage for both public replies and internal notes.

---

## What integrators may NOT rely on

- The wire format of `wp_options` storing canned responses or ticket templates (PHP serialized today; may change).
- Internal helper functions whose names start `_swh_` or that are not documented in `docs/hooks.md`.
- The HTML structure of the admin ticket editor (it's WordPress meta-box markup — WP itself reserves the right to change it).

---

## Routing

| You are about to … | Read also |
|---|---|
| Add a new ticket status or change defaults | `config-reference.md` + this doc's [status machine](#status-machine) |
| Add a new meta key | `data-dictionary.md` + the installer upgrade routine |
| Change how replies are inserted | This doc + `contract-portal.md` (client path) + `contract-inbound-webhook.md` (email path) |
| Touch the auto-close cron | This doc + `release_v4.x.x_roadmap.md` for any in-flight changes |
| Touch SLA evaluation | This doc + `design-document.md` invariants |
