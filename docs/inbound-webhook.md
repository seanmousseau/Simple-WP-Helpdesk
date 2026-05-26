---
title: Inbound Email Webhook
nav_order: 6
---

# Inbound Email Webhook

Simple WP Helpdesk exposes a REST endpoint that turns email replies into ticket comments. Point any inbound-email service (Mailgun Routes, SendGrid Inbound Parse, Postmark, CloudMailin, etc.) at the webhook and your clients can simply hit **Reply** in their email client — the reply lands on the correct ticket automatically.

This page covers what the webhook does, how to configure it, what the payload looks like, and how to debug rejections.

---

## What it does

- Accepts `POST /wp-json/swh/v1/inbound-email`.
- Authenticates the request with a shared secret you set in **Tickets → Settings → Integrations**.
- Extracts the ticket ID from the email subject line (e.g. `[TKT-1234]`).
- Verifies the sender's email address matches the ticket's client email.
- Strips quoted-reply lines (anything starting with `>`).
- Creates a `helpdesk_reply` comment on the ticket, attributed to the sender.
- Reopens the ticket if it was previously **Resolved** or **Closed**.
- Sends the admin/assignee notification email.

---

## Setup

### 1. Generate a strong secret

Use any random 32+ character string. Examples:

```bash
openssl rand -base64 32
# or
python3 -c "import secrets; print(secrets.token_urlsafe(32))"
```

### 2. Store it in SWH settings

1. Go to **Tickets → Settings → Integrations**.
2. Paste your secret into **Webhook Secret**.
3. Save.

The endpoint URL is shown on the same page:

```text
https://yoursite.com/wp-json/swh/v1/inbound-email
```

> **The webhook is disabled when the secret is blank.** A blank secret returns `503 swh_not_configured` for every request.

### 3. Point your mail provider at the endpoint

Your provider must:

- POST to the endpoint URL.
- Send `Authorization: Bearer <your-secret>` on every request.
- Send the email in any of the recognized payload formats below.

The secret is compared in constant time using `hash_equals()` — there is no timing side-channel.

---

## Outbound email requirement

For replies to thread correctly, your outbound notification emails **must** include `[TKT-XXXX]` in the subject line. The default `swh_em_ticket_replied_sub` template already does this:

```text
Re: {site_name} Support — [TKT-{ticket_id}] {subject}
```

If you customize email subjects in **Tickets → Settings → Email Templates**, keep the `[TKT-{ticket_id}]` token. The webhook extracts the UID by matching `/\[(TKT-\d+)\]/i` against the inbound `subject` field.

---

## Payload format

The webhook accepts the field names used by major providers. Fields are tried in priority order:

| What it needs | Accepted field names (first match wins) |
|---|---|
| Subject | `subject` |
| Body (plain text) | `body-plain`, `text` |
| Sender | `sender`, `from` |

The `sender` / `from` field may be a bare address or `Name <email@example.com>` — the display name (if present) is used as the comment author.

### Mailgun example

Mailgun Routes already send `subject`, `body-plain`, and `sender` as form-encoded fields. No transform needed:

```http
POST /wp-json/swh/v1/inbound-email
Authorization: Bearer your-secret-here
Content-Type: application/x-www-form-urlencoded

subject=Re%3A+Support+%5BTKT-1234%5D&body-plain=Thanks%2C+that+fixed+it.&sender=Jane+Doe+%3Cjane%40example.com%3E
```

### SendGrid Inbound Parse

SendGrid posts `subject`, `text`, and `from` — all supported. Configure the parse URL to:

```text
https://yoursite.com/wp-json/swh/v1/inbound-email
```

Add the bearer header via SendGrid's "Send Raw" off + a webhook proxy if SendGrid can't set custom headers directly; many users front it with a small Cloudflare Worker that adds the header.

### Postmark Inbound

Postmark posts JSON with `Subject`, `TextBody`, `From` — field names are case-sensitive and don't match. Use Postmark's inbound webhook field-mapping or front it with a tiny relay (Worker / serverless function) that re-shapes the body to:

```json
{
  "subject": "<Subject>",
  "body-plain": "<TextBody>",
  "sender": "<From>"
}
```

### cURL test

```bash
curl -X POST https://yoursite.com/wp-json/swh/v1/inbound-email \
  -H 'Authorization: Bearer your-secret-here' \
  -d 'subject=Re: Support [TKT-1234]' \
  -d 'body-plain=Thanks, that fixed it.' \
  -d 'sender=jane@example.com'
```

Expected success response:

```json
{ "success": true }
```

---

## Response codes

| Status | Body | Meaning |
|---|---|---|
| `200` | `{"success":true}` | Reply attached to the ticket |
| `200` | `{"success":false,"error":"no_ticket_id"}` | Subject did not contain `[TKT-XXXX]` |
| `200` | `{"success":false,"error":"ticket_not_found"}` | UID parsed but no ticket has that `_ticket_uid` |
| `200` | `{"success":false,"error":"sender_mismatch"}` | `from` address doesn't match the ticket's `_ticket_email` |
| `200` | `{"success":false,"error":"empty_body"}` | Body was empty after stripping quoted lines |
| `200` | `{"success":false,"error":"comment_insert_failed"}` | WordPress refused the comment insert |
| `401` | `{"code":"swh_unauthorized"}` | Missing/wrong `Authorization: Bearer` header |
| `503` | `{"code":"swh_not_configured"}` | No secret configured in Settings |

> Soft failures return `200` with `success: false` so your mail provider doesn't retry forever. Hard failures (`401`, `503`) return real HTTP errors.

---

## Security model

- **Bearer secret, constant-time compare.** The shared secret is checked with `hash_equals()` against the `Authorization: Bearer …` header. A blank or absent header is treated as unauthorized.
- **Sender pinning.** Even with a valid bearer, a reply is rejected unless the `from` address matches the ticket's stored client email (case-insensitive, constant-time). This protects against spoofed `From:` headers being used to inject content into someone else's ticket.
- **No HTML body parsing.** Only the plain-text body is consumed. HTML payloads are ignored to keep the attack surface small.
- **Comment is `wp_kses_post`-filtered.** The cleaned body is passed through WordPress's standard post-content sanitizer before insert.
- **Internal notes are unaffected.** The webhook only creates `helpdesk_reply` comments; it cannot create `_is_internal_note=1` comments.

See the [Security](security) page for the broader threat model.

---

## Troubleshooting

### Every request returns `401 swh_unauthorized`

The `Authorization: Bearer …` header isn't reaching WordPress. Most common causes:

1. **Apache strips it.** Some Apache configs drop `Authorization` before PHP sees it. Add to `.htaccess`:
   ```apache
   SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
   ```
2. **Provider can't set custom headers.** Front the endpoint with a Cloudflare Worker / Vercel function / Lambda that injects the header server-side.
3. **Secret mismatch.** Re-copy the secret — trailing whitespace or newline characters break the match.

### Every request returns `503 swh_not_configured`

The **Webhook Secret** in **Integrations** is blank. Set it.

### Replies return `sender_mismatch`

The "from" address on the inbound email isn't the same one the ticket was opened with. Common causes:

- The client replied from a different mailbox (work vs. personal).
- Your mail provider rewrites the envelope sender (e.g. for DMARC alignment) and the rewritten address is what gets posted.
- Someone CC'd a coworker who replied to the thread.

**Workaround:** ask the client to reply from the same address that opened the ticket, or open a new ticket from their secondary address.

> CC support and multi-address ticket linking is on the [v4.x roadmap](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues).

### Replies return `no_ticket_id`

The inbound subject didn't contain `[TKT-XXXX]`. Either:

- The outbound notification template was edited to remove the token — restore it in **Settings → Email Templates**.
- The client's mail client rewrote the subject (some "Smart Reply" features do this). The reply will be rejected; ask the client to keep the subject prefix.

### Replies return `ticket_not_found`

The subject contained a UID but no ticket has that `_ticket_uid` meta. Either the ticket was deleted, or the UID was hand-typed and doesn't match.

### Debugging tip

Run the cURL test above against your own site with a known-good ticket UID and the sender address from that ticket. If the cURL test succeeds but the provider's POSTs fail, the issue is in the provider → SWH path (auth header, field names, content-type), not in SWH itself.

---

## Related

- [Configuration → Integrations tab](configuration#integrations)
- [Email Templates](configuration#email-templates) — keep `[TKT-{ticket_id}]` in subjects
- [Security](security)
