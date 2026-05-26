# Contract: Inbound Email Webhook

The REST endpoint that converts email replies into ticket comments. This is **the** authoritative description of how `POST /wp-json/swh/v1/inbound-email` behaves.

End-user setup, provider configuration (Mailgun / SendGrid / Postmark), and troubleshooting live in **end-user `docs/inbound-webhook.md`**. This contract covers the implementation, security guarantees, and change policy.

---

## Surface

| Property | Value |
|---|---|
| HTTP method | `POST` |
| Path | `/wp-json/swh/v1/inbound-email` |
| Registration | `register_rest_route( 'swh/v1', '/inbound-email', … )` |
| Handler | `swh_handle_inbound_email( WP_REST_Request $request )` in `simple-wp-helpdesk/includes/class-email.php` |
| Permission callback | Returns `true` — **auth is enforced inside the handler** via `hash_equals()` against the bearer header |

---

## Authentication

```text
Authorization: Bearer <swh_inbound_secret>
```

- Secret is stored as the `swh_inbound_secret` option (Integrations tab).
- Compared via `hash_equals()`.
- Blank secret → endpoint returns `503 swh_not_configured`. **Blank secret means disabled.**
- Bearer mismatch / missing header → `401 swh_unauthorized`.

---

## Payload

Field-name compatibility across providers — first match wins:

| Logical field | Accepted form-field names | Required |
|---|---|---|
| Subject | `subject` | ✅ (must contain `[TKT-XXXX]`) |
| Body (plain text) | `body-plain`, `text` | ✅ |
| Sender | `sender`, `from` | ✅ |

`sender` / `from` accepts either `email@example.com` or `Name <email@example.com>`. The display name (if present) is used as the comment author; the parsed email is used for sender pinning.

HTML payloads are ignored — only the plain-text body is consumed.

---

## Processing pipeline

1. **Auth check.** `hash_equals( swh_inbound_secret, Bearer )` against the configured secret. Fail → `401`.
2. **Read fields** with the field-name fallbacks above.
3. **Parse sender.** If `Name <email>` format, split. Sanitize email with `sanitize_email`.
4. **Extract UID from subject.** Regex: `/\[(TKT-\d+)\]/i`. No match → `200 {success:false, error:"no_ticket_id"}`.
5. **Look up ticket** by `_ticket_uid` meta. Not found → `200 {success:false, error:"ticket_not_found"}`.
6. **Sender pinning.** `hash_equals( strtolower($_ticket_email), strtolower($from) )`. Mismatch → `200 {success:false, error:"sender_mismatch"}`.
7. **Strip quoted reply lines** (lines starting with `>`).
8. **Reject empty body** → `200 {success:false, error:"empty_body"}`.
9. **Insert comment.** `wp_insert_comment` with `comment_type='helpdesk_reply'`, author name = display name or address, content `wp_kses_post`-filtered.
10. **Update meta.** `_is_user_reply = 1` on the comment; `_swh_unread = 1` on the ticket; bump `TicketVersion`; clear `swh_unread_count` transient.
11. **Fire** `swh_ticket_replied( $ticket_id, $comment_id, false )`.
12. **Auto-reopen** if the ticket was in the configured Resolved or Closed status. Reopen via `swh_set_ticket_status()` — same hooks fire as a UI-driven reopen.
13. **Send admin notification.** `swh_send_email( admin, 'swh_em_admin_reply_*', … )` — **no watcher Cc** (admin-only by design).
14. Return `200 {success:true}`.

---

## Response codes

| Status | Shape | Meaning |
|---|---|---|
| `200` | `{"success":true}` | Reply attached to ticket |
| `200` | `{"success":false,"error":"no_ticket_id"}` | Subject did not contain `[TKT-XXXX]` |
| `200` | `{"success":false,"error":"ticket_not_found"}` | UID parsed but no ticket has that `_ticket_uid` |
| `200` | `{"success":false,"error":"sender_mismatch"}` | `from` doesn't match `_ticket_email` |
| `200` | `{"success":false,"error":"empty_body"}` | Empty after quoted-line stripping |
| `200` | `{"success":false,"error":"comment_insert_failed"}` | `wp_insert_comment` returned 0 |
| `401` | `{"code":"swh_unauthorized"}` | Missing / wrong bearer |
| `503` | `{"code":"swh_not_configured"}` | Secret is blank |

### Why soft failures return 200

Mail providers (Mailgun, SendGrid, Postmark) treat non-2xx as a delivery failure and retry — sometimes for days. Soft failures are inherent to email (typo in subject, CC'd reply from a different address) and would never succeed on retry. Returning `200 {success:false}` stops the retry storm and surfaces the cause in our own response body.

Hard failures (`401`, `503`) are configuration problems on the provider's side or ours — there a real HTTP error is correct.

---

## Invariants

1. **Auth is in the handler, not the permission callback.** Centralizes the bearer check and ensures we always emit `401 swh_unauthorized` with the right error code.
2. **Bearer comparison uses `hash_equals()`.** Constant-time.
3. **Sender pinning uses `hash_equals()` after lowercasing.** Constant-time on the address compare.
4. **Empty `_ticket_email` is treated as a mismatch.** No "no stored email → accept anything" bypass.
5. **Only `helpdesk_reply` comments are created.** The webhook cannot create `_is_internal_note = 1` comments, ever.
6. **Body is `wp_kses_post`-filtered** before insert. No raw HTML survives.
7. **Auto-reopen uses `swh_set_ticket_status()`**, not direct meta writes. Hooks fire normally.
8. **Admin notification path does not inject watcher Cc.**

---

## Apache header passthrough

Some Apache configurations strip `Authorization` before PHP sees it. Common fix (operator-side, documented in end-user troubleshooting):

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

In our test harness (Docker), we bypass HTTP entirely and call `swh_handle_inbound_email()` directly via `wp eval` — avoids any Apache-strip surprises in CI.

---

## Hooks fired

- `swh_ticket_replied( $ticket_id, $comment_id, false )` — always `$is_staff_reply=false`.
- `swh_ticket_status_changed`, `swh_ticket_reopened` — fired only when auto-reopen runs.

No webhook-specific hooks today. Future "outbound webhooks" (v4.2 per `roadmap.md`) will add `swh_inbound_email_received` so integrators can audit inbound traffic.

---

## What integrators may rely on

- Endpoint path `/wp-json/swh/v1/inbound-email` is stable for v4.x.
- Auth method is bearer-token with constant-time compare.
- Sender pinning is by `_ticket_email`.
- Response shapes above are stable.

## What integrators may NOT rely on

- The exact regex for UID extraction (it's a private impl detail; we may relax it to handle wrapped subjects in the future).
- The exact wording of the `error` strings beyond the documented set.
- The HTTP status code for soft failures (we may switch to `202` in a future major version if providers handle it well; not changing inside v4.x).

---

## Routing

| Touching … | Read also |
|---|---|
| Webhook handler logic | This doc + `security-model.md` + end-user `docs/inbound-webhook.md` |
| Auth model | This doc + `security-model.md` |
| Sender pinning | This doc + `security-model.md` |
| Adding fields the webhook should accept | This doc — keep the field-name compatibility table accurate |
| Outbound notification path on inbound reply | `contract-email-templates.md` |
