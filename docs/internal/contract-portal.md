# Contract: Client Portal

The token-authenticated frontend that clients use to view their tickets, reply, close, and reopen. This is the boundary where unauthenticated visitors interact with ticket data — every assumption it makes is a security assumption.

> Touch this contract before changing the portal's auth model, URL shape, rate limiting, or reply/close/reopen behavior.

---

## Surface

Two shortcodes, both wired in `simple-wp-helpdesk/frontend/class-shortcode.php`:

| Shortcode | Render |
|---|---|
| `[submit_ticket]` | Submission form + ticket lookup + portal (when token is in URL) |
| `[helpdesk_portal]` | Ticket lookup + portal (no submission form) |

Both shortcodes share `swh_helpdesk_portal_shortcode()`-style attribute handling. Attributes documented in end-user `docs/shortcodes.md`.

The page where one of these shortcodes lives is registered as the **Helpdesk Page** in **Settings → Assignment & Routing**. All portal URLs in outbound emails point there.

---

## URL shape

```
https://yoursite.com/<helpdesk-page>/?swh_ticket=<ticket_id>&token=<20-char-token>
```

- `swh_ticket` is the **post ID**, not the UID. Internal to the URL contract.
- `token` is the `_ticket_token` meta value — 20 alphanumeric chars from `wp_generate_password(20, false)`.
- Token expiry is the `_ticket_token_exp` meta — Unix timestamp, default 90 days.

The shortcode detects these params, validates them, and renders the portal view. Without them, it renders the submission form (logged-out) or My Tickets dashboard (logged-in clients).

---

## Authentication

The portal is **token-only authentication**. There is no WordPress login required.

### Validation order

For every portal request:

1. `swh_ticket` query var present and is a numeric ticket ID.
2. `token` query var present and non-empty.
3. Post exists and is `helpdesk_ticket`.
4. `_ticket_token` meta is set and non-empty (empty-token guard — `hash_equals('', '')` would otherwise pass).
5. `hash_equals( $stored_token, $supplied_token ) === true`.
6. `_ticket_token_exp` is in the future (or absent — older tickets pre-dating expiry don't fail).

Any failure → render the lookup form with the focus moved to the email field. Never leak which step failed — the messages are deliberately generic.

### Token rotation

A new token is generated and stored on every successful lookup-form submission. Bookmarked links to the old token become invalid after the next legitimate email-resend.

---

## Permitted actions

Once authenticated by token, the portal exposes three actions:

| Action | When permitted | Nonce | Rate-limit key |
|---|---|---|---|
| Reply | Status is not the configured closed status | `swh_portal_reply` | `portal_reply_<ticket_id>` |
| Close | Status equals the configured resolved status | `swh_portal_close` | `portal_close_<ticket_id>` |
| Reopen | Status equals the configured closed status | `swh_portal_reopen` | `portal_reopen_<ticket_id>` |

The presence of distinct per-action rate-limit keys is a hard invariant — see `contract-ticket-lifecycle.md`. Sharing a key breaks the close-then-reopen flow.

### Reply

- Body sanitized with `wp_kses_post`.
- Optional file uploads via XHR; see `contract-file-uploads.md`.
- Inserts a `helpdesk_reply` comment with `_is_user_reply = 1`.
- Sets `_swh_unread = '1'` on the ticket.
- Fires `swh_ticket_replied( $ticket_id, $comment_id, false )` (always non-staff).
- Sends the admin notification via `swh_send_email( …, 'swh_em_admin_reply_*', … )`.

### Close

- Status → `swh_closed_status` via `swh_set_ticket_status()`.
- Triggers the `swh_ticket_closed` action.
- Optionally renders the CSAT prompt (if `swh_csat_enabled`).
- Sends client confirmation + admin notification.

### Reopen

- Status → `swh_reopen_status` (default `Open`) via `swh_set_ticket_status()`.
- Triggers `swh_ticket_reopened`.
- Optional `reason` text becomes the body of a new `helpdesk_reply` comment.
- Sends admin notification.

---

## My Tickets dashboard

When the visitor is logged in **and** no ticket/token is in the URL, `[submit_ticket]` and `[helpdesk_portal]` render a card list of tickets where `_ticket_email` matches the logged-in user's email.

- Tickets are queried by `meta_query` (`_ticket_email = $user->user_email`).
- Each card links to the secure portal URL (with token).
- No rate-limit applies — this is a logged-in view.

---

## Invariants

1. **Token check uses `hash_equals()` after the empty-token guard** (see [Validation order](#validation-order)).
2. **All actions enforce both nonce + rate-limit + capability check.** Any one of those failing → action rejected.
3. **`helpdesk_reply` comments must be filtered from comment listings outside the helpdesk** (e.g. `wp_count_comments`, theme comment templates). The filter lives in `class-ticket.php`.
4. **Internal notes (`_is_internal_note = 1`) must NEVER appear in the portal view.** This is enforced in the portal renderer, not via the comments query.
5. **CSAT submission requires a valid token + valid nonce + a closed-status ticket.** The AJAX endpoint refuses if `swh_csat_enabled` is off (no endpoint exposed).

---

## CSAT widget

- Rendered after a successful Close action when `swh_csat_enabled` is true.
- 1–5 star rating, optional skip.
- AJAX submit to `wp-ajax` `swh_submit_csat`.
- Validates nonce + token + status; stores rating in `_ticket_csat` meta.
- Fires `swh_csat_submitted( $ticket_id, $rating )`.

---

## Page-builder compatibility

- The shortcodes are wrapped in `.swh-helpdesk-wrapper` for scoped CSS.
- Must be placed in a **Shortcode** block / element — not a raw HTML widget.
- Elementor, Divi, Beaver Builder all tested (see E2E sections).

---

## Failure modes

| Scenario | Behavior |
|---|---|
| Token missing/wrong/expired | Lookup form rendered; focus jumps to email field |
| Reply during rate-limit window | Friendly error; no DB write |
| Reply on closed ticket | Reply form is replaced with reopen UI; reply attempt is impossible from the rendered form |
| Close on non-resolved ticket | Close button is not rendered; direct POST is rejected with a 403 |
| Reopen on non-closed ticket | Reopen button is not rendered; direct POST is rejected with a 403 |
| File upload of disallowed type | Upload rejected with a UI message; reply text still submitted |
| CSAT submit with `swh_csat_enabled = false` | 404 from AJAX endpoint |

---

## Failure modes integrators may rely on

- Failed auth always renders the lookup form. Integrators may not infer "this token is invalid" vs "this ticket doesn't exist".
- Successful actions always send the relevant admin email; integrators should hook the action signals, not poll for the email.

---

## Routing

| You are about to … | Read also |
|---|---|
| Change URL params or token format | This doc + `security-model.md` |
| Change which actions are exposed | This doc + `contract-ticket-lifecycle.md` |
| Touch rate limiting | This doc + `security-model.md` |
| Touch CSAT | This doc + `contract-ticket-lifecycle.md` + `accessibility.md` (focus management) |
| Touch My Tickets dashboard | This doc + `contract-ticket-lifecycle.md` |
| Change portal styling | `design-guide.md` + `component-inventory.md` |
