# Contract: File Uploads

Attachments on tickets and replies. End-to-end: client upload → MIME validation → storage under a protected directory → token-gated serving → eventual retention cleanup.

> Touch this contract before changing allowed file types, where files are stored, how they're served, or retention.

---

## Storage

| | Value |
|---|---|
| Directory | `wp-content/uploads/swh-helpdesk/` |
| Protection | `.htaccess` with `Deny from all` (Apache) — direct URL access returns 403 |
| Filenames | Original filename preserved, deduplicated by WP's `wp_unique_filename` |
| Attachment posts | Standard WP attachment CPT (`attachment` post type, `post_parent` = ticket ID) |
| Per-ticket meta | `_ticket_attachments` on the ticket — array of file URLs (root-level attachments). See `data-dictionary.md` for the canonical shape. |

Files are never served by direct URL. All downloads go through the proxy described below.

---

## Allowed MIME types

| Default | Filter |
|---|---|
| `jpg`, `jpeg`, `jpe`, `png`, `gif`, `pdf`, `doc`, `docx`, `txt` | `swh_allowed_file_types` |

Both extension and MIME are validated by WP's `wp_handle_upload` flow. Custom code adding to the list **must** add both the extension and the matching MIME — adding only an extension would let WP reject the upload at the MIME check.

---

## Size limits

| Limit | Source | Default |
|---|---|---|
| Per-file | `swh_max_upload_size` setting | 5 MB |
| Per-submission count | `swh_max_upload_count` setting | 5 (0 = unlimited) |
| PHP `upload_max_filesize` | server | must be ≥ `swh_max_upload_size` |
| PHP `post_max_size` | server | must be ≥ `swh_max_upload_size` × `swh_max_upload_count` |

Enforced client-side in JS (XHR progress; pre-upload size check) **and** server-side in the upload handler. Server-side is authoritative.

---

## Upload flow

### Submission form

1. Client drops or selects files.
2. JS validates extension + size per file. Over-limit files highlighted; submit blocked.
3. On submit, `multipart/form-data` POST with files in `swh_attachments[]`.
4. Server handler: nonce check → rate-limit check → anti-spam check → `wp_handle_upload` per file → extension check via allowed list → attach as WP attachment.
5. Attachment IDs appended to `_ticket_attachments` meta on the new ticket.

### Portal reply

Same flow, scoped to the authenticated ticket. Reply attachment IDs are stored on the comment as `comment_meta._reply_attachments` (serialized array).

### Admin ticket editor

Staff can upload attachments via the standard WP media uploader; they're attached to the ticket post (`post_parent`).

---

## Serving

`swh_serve_file()` — defined in `includes/class-ticket.php`, registered on `init` priority 1. Takes no arguments; reads `$_GET['swh_file']`, `$_GET['swh_ticket']`, and `$_GET['token']` directly.

Validation sequence:

1. All three query params (`swh_file`, `swh_ticket`, `token`) must be present and non-empty.
2. Sanitize filename via `sanitize_file_name()`.
3. Confirm ticket exists and is `helpdesk_ticket`.
4. Confirm caller authority:
   - Logged-in staff with the `edit_posts` capability → allowed.
   - Anonymous → verify `token` against the ticket's `_ticket_token` meta with `hash_equals()`.
5. `realpath()` the resolved file path; confirm it's under the protected upload directory. Anything outside → 403.
6. Stream via `readfile()` with correct `Content-Type` and `Content-Disposition: attachment`.

Download URLs use plain query args, not a REST route:

```text
https://yoursite.com/?swh_file=<filename>&swh_ticket=<id>&token=<token>
```

A REST-style `/wp-json/swh/v1/file/…` endpoint is on the roadmap (see `roadmap.md`) but **not implemented today**.

---

## Retention

| Job | Setting | Default |
|---|---|---|
| Attachment retention | `swh_retention_attachments_days` | 0 (disabled) |
| Ticket retention | `swh_retention_tickets_days` | 0 (disabled) |

Both run hourly via `swh_cron_event` in micro-batches to avoid timeouts.

### Attachment retention

Deletes physical files older than N days, but **keeps** the attachment post and the ticket — only the binary is removed. The attachment post's title/desc remain so the conversation history stays readable.

### Ticket retention

Deletes entire tickets (and their attachments, comments, and files) that have been inactive for N days. Inactivity = no reply, no status change.

---

## Path safety

The single most-attacked surface in any file-serving plugin. Hard rules:

1. **Every delete path is validated with `realpath()` against the protected directory.**
2. **Every read path is validated with `realpath()` against the protected directory.**
3. **No user-supplied path is ever passed to PHP filesystem functions.** Inputs are attachment IDs; paths are derived from `get_attached_file()`.
4. **Filenames in `Content-Disposition` are sanitized** to strip path separators.
5. **The protected directory `.htaccess` is regenerated on activation** in case an operator deletes it.

---

## Invariants

1. **MIME + extension are both checked** before storage.
2. **All serving goes through `swh_serve_file()`**; no direct `wp-content/uploads/swh-helpdesk/` URLs are valid.
3. **Anonymous access requires a valid portal token** (constant-time compared).
4. **Staff access requires `edit_posts` on the parent ticket** — not just `read_post`.
5. **Delete paths are `realpath`-validated.**
6. **Retention runs in micro-batches** (no whole-table scans inside a single cron tick).

---

## Failure modes

| Scenario | Behavior |
|---|---|
| Disallowed extension | Upload rejected; rest of submission proceeds |
| File over size limit | Upload rejected; rest of submission proceeds |
| MIME mismatch (extension says PNG, content is something else) | `wp_handle_upload` rejects; upload fails |
| Anonymous request without token | 403 |
| Token mismatch | 403 |
| Attachment ID points outside the protected dir | 403 (realpath check) |
| `wp_handle_upload` filesystem failure | Submission proceeds without the file; surfaces in JS as upload error |

---

## What integrators may rely on

- The `swh_allowed_file_types` filter signature.
- The protected directory path.
- The token-gated serving URL structure for v4.x.

## What integrators may NOT rely on

- The exact `_ticket_attachments` storage shape (URL list today; might change).
- Internal helper names not exposed in `docs/hooks.md`.

---

## Routing

| Touching … | Read also |
|---|---|
| MIME / extension list | This doc + `security-model.md` |
| Serving / proxy | This doc + `security-model.md` + `contract-portal.md` |
| Retention | This doc + `release_v4.x.x_roadmap.md` |
| Storage location | `data-dictionary.md` + `security-model.md` |
| Frontend upload UI | `design-guide.md` + `accessibility.md` (progress region) |
