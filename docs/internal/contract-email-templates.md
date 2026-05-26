# Contract: Email & Templates

Every piece of mail Simple WP Helpdesk sends flows through one function (`swh_send_email`) and one template parser (`swh_parse_template`). Everything else is fields, headers, and presentation. This contract describes that pipeline.

> Touch this contract before changing how mail is rendered, sent, or templated.

---

## The one-and-only dispatcher

```php
swh_send_email(
    string $to,
    string $subject_key,
    string $body_key,
    array  $data,
    array  $attachments = array(),
    int    $ticket_id   = 0
): bool
```

- `$to` — comma-separated list of addresses. Validated via `sanitize_email` upstream of this call.
- `$subject_key` / `$body_key` — option keys for the template (e.g. `swh_em_new_ticket_sub` / `swh_em_new_ticket_body`).
- `$data` — placeholder map passed to `swh_parse_template()`.
- `$attachments` — list of absolute file paths.
- `$ticket_id` — used to look up assignee, watchers, internal note Cc rules.

**Direct `wp_mail()` calls are forbidden** for any ticket-related mail. They skip the template parser, the `{if}` conditional blocks, the HTML wrapper, the dark-mode email styles, and the watcher Cc injection.

---

## Template surface

### Where templates live

`wp_options` keyed by:

- Subject: `swh_em_<event>_sub`
- Body: `swh_em_<event>_body`

Default templates live in `swh_get_defaults()` (`includes/helpers.php`).

### Events (14 total)

**Client-facing (8):**

| Event | Subject key |
|---|---|
| New ticket received | `swh_em_new_ticket_sub` |
| Technician replied | `swh_em_ticket_replied_sub` |
| Status changed (no reply) | `swh_em_status_change_sub` |
| Reply + status changed (combined) | `swh_em_combined_sub` |
| Ticket resolved | `swh_em_ticket_resolved_sub` |
| Ticket re-opened | `swh_em_ticket_reopened_sub` |
| Ticket auto-closed | `swh_em_autoclose_sub` |
| Ticket closed by client | `swh_em_closed_by_client_sub` |

**Admin-facing (6):**

| Event | Subject key |
|---|---|
| New ticket submitted | `swh_em_admin_new_sub` |
| Client replied | `swh_em_admin_reply_sub` |
| Client re-opened | `swh_em_admin_reopen_sub` |
| Client closed | `swh_em_admin_close_sub` |
| Assigned to technician | `swh_em_admin_assigned_sub` |
| Ticket auto-closed (admin) | `swh_em_admin_autoclose_sub` |

Each has a matching `…_body` key.

### Placeholders

The full set lives in end-user `docs/configuration.md` and is also enumerated in `swh_parse_template()`. Hot list:

| Token | Source |
|---|---|
| `{name}` | `_ticket_name` |
| `{email}` | `_ticket_email` |
| `{ticket_id}` | `_ticket_uid` (the `TKT-XXXX` UID — not the post ID) |
| `{title}` | `post_title` |
| `{status}` | `_ticket_status` |
| `{priority}` | `_ticket_priority` |
| `{message}` | The reply body / status-change context |
| `{ticket_url}` | Result of `swh_get_secure_ticket_link( $ticket_id )` |
| `{admin_url}` | Result of `swh_admin_ticket_url( $ticket_id )` |
| `{autoclose_days}` | Current `swh_autoclose_days` setting |
| `{site_name}` | `get_bloginfo( 'name' )` |

### Conditional blocks

`{if key}...{endif key}` blocks include the inner content only when `$data[$key]` is a non-empty value. Nesting is permitted. Unbalanced tags are stripped silently — log a `_doing_it_wrong` if you see one in development.

Unreplaced placeholders are deleted from the output.

---

## Invariants

1. **`swh_send_email` is the sole entry point** for ticket-related mail. New code paths must call it.
2. **Subject lines must contain `[TKT-{ticket_id}]`** on every outbound message that should be threadable back via the inbound webhook. The defaults all include it; if an operator removes it, replies stop threading. See `contract-inbound-webhook.md`.
3. **Templates are stored under `wp_options`** keyed exactly as above. Renaming a key requires a migration in `class-installer.php`.
4. **HTML body templates are passed through `wp_kses_post`** before being wrapped. Operators may use a curated set of HTML tags; raw `<script>` does not survive.
5. **The HTML wrapper (`swh_wrap_html_email`)** owns the dark-mode + color-scheme meta tags. Don't inline a second `<html>`/`<head>` in any template.
6. **Plain-text fallback is auto-derived** by `wp_strip_all_tags` from the HTML body. We do not separately maintain plain-text templates — operators edit HTML and the plain version follows.

---

## Routing logic

The "best" address for a ticket-scoped admin notification is determined by `swh_get_admin_email( $ticket_id )`:

1. Assigned technician's email (if a user is in `_ticket_assigned_to`).
2. Default assignee's email (`swh_default_assignee_id`).
3. Fallback alert email (`swh_fallback_email`).
4. `get_bloginfo( 'admin_email' )`.

Watcher / Cc behavior is layered on top: when a non-internal-note staff reply is sent, additional watchers from `_ticket_watchers` meta are added to `Cc:`. Inbound-webhook admin notifications **never** include watcher Cc (they are admin-only, by design).

---

## The HTML wrapper

`swh_wrap_html_email( string $html_body, string $site_name, string $logo_url, string $footer_note, string $attachment_html ): string`

- Emits `<meta name="color-scheme" content="light dark">` and `<meta name="supported-color-schemes" content="light dark">`.
- Embeds the dark-mode stylesheet inside `<style>` plus a `@media (prefers-color-scheme: dark)` block.
- Renders the header band with logo + site name. Falls back to the site icon at 32×32 px if no logo URL is configured.
- All component styling is inlined for clients that strip `<style>` (Gmail web especially).

Apple Mail (macOS / iOS) honours the dark-mode directive. Webmail clients that strip `<style>` fall back to the inlined light theme — readable in both modes.

---

## Failure modes

| Scenario | Behavior |
|---|---|
| Unknown subject/body key | Template falls back to the default in `swh_get_defaults()` |
| `wp_mail` returns false | `swh_send_email` returns false; logged via `error_log` in WP_DEBUG |
| Template contains unbalanced `{if}` | Inner content is removed; log emitted in WP_DEBUG |
| Empty `$to` after sanitisation | Send aborted; returns false |
| Attachment path outside `wp-content/uploads/swh-helpdesk/` | Rejected before send |

---

## Hooks for integrators

| Hook | Purpose |
|---|---|
| `swh_parse_template` (filter) | Modify the rendered string after placeholder substitution |
| `swh_email_headers` (filter) | Modify outbound headers (Reply-To, Cc, etc.) |
| Standard WP `wp_mail_failed` action | Capture send failures |

All filter signatures are public API; see `api-contract.md` + end-user `docs/hooks.md`.

---

## Routing

| Touching … | Read also |
|---|---|
| Add a new email event | This doc + `data-dictionary.md` (new option keys) + `config-reference.md` |
| Change template rendering | This doc + `security-model.md` (`wp_kses_post`) |
| Change recipient routing | This doc + `contract-ticket-lifecycle.md` |
| Touch the HTML wrapper / dark-mode email styles | This doc + `accessibility.md` |
| Add inbound-mail behavior | `contract-inbound-webhook.md` + this doc (subject `[TKT-…]` invariant) |
