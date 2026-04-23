---
title: Security
nav_order: 6
---

# Security

Simple WP Helpdesk is built with WordPress security best practices. This document summarizes the key security mechanisms in place.

---

## Client Portal Authentication

Access to ticket details is controlled by a **cryptographic token** appended to each ticket's URL:

```text
https://yoursite.com/support/?swh_ticket=123&token=AbCdEfGhIjKlMnOpQrSt
```

- Tokens are generated with `wp_generate_password(20, false)` — 20 random alphanumeric characters.
- Token comparisons always use `hash_equals()` to prevent timing-based brute-force attacks.
- Tokens are stored as post meta and never exposed in the admin-facing ticket list.
- Empty tokens are rejected before comparison (prevents `hash_equals('', '')` bypass).
- Tokens have a configurable expiration TTL (default 90 days) and auto-rotate on lookup.

---

## Anti-Spam

Three methods are available (configured in **Settings → Anti-Spam**):

| Method | How It Works |
|--------|-------------|
| **Honeypot** | A hidden form field invisible to real users; bots that fill it are silently rejected |
| **Google reCAPTCHA v2** | Server-side verification with Google's API before ticket submission is accepted |
| **Cloudflare Turnstile** | Privacy-friendly challenge verified server-side before processing |

Protection applies to the submission form, ticket lookup form, and portal reply/close/reopen forms.

---

## Frontend Rate Limiting

All frontend form actions (ticket submission, reply, close, re-open) enforce a **30-second cooldown** per action per client IP. Limits are stored in `wp_options` (not transients) so they survive object-cache flushes and work correctly across load-balanced environments.

Each portal action type has its own rate limit key (`portal_close_`, `portal_reopen_`, `portal_reply_`), so closing a ticket does not block an immediate reopen attempt.

IP detection uses `swh_get_client_ip()`, which inspects `HTTP_X_FORWARDED_FOR` and `HTTP_X_REAL_IP` headers (in addition to `REMOTE_ADDR`) and is CDN/proxy-aware.

---

## Form & Nonce Validation

Every form submission — both frontend and admin — is protected by a **WordPress nonce**:

- Frontend submission form: `swh_submit_ticket_nonce`
- Frontend reply / close / re-open: verified before any action is taken
- Admin settings (main form): `swh_save_settings_action` / `swh_settings_nonce`
- Admin settings (Tools form): `swh_save_tools_action` / `swh_tools_nonce`

---

## Capability Checks

All admin-side operations check WordPress capabilities before executing:

| Operation | Required Capability |
|-----------|-------------------|
| Save plugin settings | `manage_options` |
| Save ticket meta (status, assignee, etc.) | `edit_post` on that ticket |
| GDPR purge / factory reset | `manage_options` |

---

## Input Sanitization & Output Escaping

All user input is sanitized on the way in and escaped on the way out:

- **Text fields:** `sanitize_text_field()`
- **Email fields:** `sanitize_email()`
- **Textarea / message bodies:** `sanitize_textarea_field()`
- **HTML fields (email templates):** `wp_kses_post()`
- **Integer options:** `absint()`
- **HTML output:** `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`

---

## File Upload Security

- File uploads are processed exclusively through WordPress's `wp_handle_upload()`.
- **Allowed MIME types:** JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Extendable via the `swh_allowed_file_types` filter.
- Upload size is enforced both client-side (JavaScript) and server-side (PHP).
- Files are stored in a dedicated `/swh-helpdesk/` subdirectory inside the WordPress uploads folder, protected by an `.htaccess` file that denies all direct access.
- Files are never served by direct URL — all downloads go through `swh_serve_file()`, which verifies the portal token before streaming the file.
- Before deleting any file, the path is validated with `realpath()` to confirm it falls within the protected upload directory, preventing path traversal attacks.

---

## Assignee Validation

When saving a ticket, the assigned user ID is verified to belong to either the `administrator` or `technician` role. Invalid user IDs are silently discarded, preventing privilege escalation via forged form submissions.

---

## Inbound Webhook Authentication

The reply-by-email endpoint (`POST /wp-json/swh/v1/inbound-email`) requires:

1. A valid `Authorization: Bearer <token>` header matching the `swh_inbound_secret` option.
2. The sender email address must match the `_ticket_email` meta on the target ticket (verified via `hash_equals()`).

This prevents unauthorized parties from injecting replies into tickets.

---

## Reporting Vulnerabilities

Please use [GitHub Security Advisories](https://github.com/seanmousseau/Simple-WP-Helpdesk/security/advisories/new) to report vulnerabilities privately. Do not open a public issue for security concerns.
