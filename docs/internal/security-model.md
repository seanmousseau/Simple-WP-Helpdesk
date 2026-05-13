# Security Model

**Audience:** plugin contributors and security reviewers.

## Trust boundaries

```
                       ┌──────────────────────┐
  Anonymous internet ──┤ Submit form (POST)   │── nonce + antispam + rate limit ──> swh_pre_ticket_create
                       │ [submit_ticket]      │
                       └──────────────────────┘

                       ┌──────────────────────┐
  Anonymous + token ───┤ Portal (GET/POST)    │── token hash_equals + TTL + rate limit ──> reply/close/reopen
                       │ [helpdesk_portal]    │
                       └──────────────────────┘

                       ┌──────────────────────┐
  Anonymous internet ──┤ REST inbound-email   │── Authorization: Bearer + [TKT-XXXX] + sender hash_equals
                       │ POST /swh/v1/...     │
                       └──────────────────────┘

                       ┌──────────────────────┐
  WP user (any)     ───┤ CSAT AJAX (nopriv)   │── nonce + ticket_id match
                       │                      │
                       └──────────────────────┘

                       ┌──────────────────────┐
  WP user (logged in) ─┤ My Tickets dashboard │── user_email == _ticket_email
                       │                      │
                       └──────────────────────┘

                       ┌──────────────────────┐
  Technician           ┤ Admin ticket editor  │── edit_post + (optional) restrict-to-assigned
                       │                      │
                       └──────────────────────┘

                       ┌──────────────────────┐
  Administrator        ┤ Settings + Tools     │── manage_options + nonce (two separate nonces)
                       │                      │
                       └──────────────────────┘
```

Everything to the left of the box is untrusted. The bullet to the right of the box is the mitigation that must hold or the boundary fails.

## Threats and mitigations

| Threat | Mitigation | Code location |
|---|---|---|
| Spam ticket submission | Honeypot field (`swh_website_url_hp`) zero-config + optional reCAPTCHA v2/Enterprise / Cloudflare Turnstile + per-IP rate limit. | `swh_check_antispam()` at `includes/helpers.php:375-458`; `swh_is_rate_limited()` at `helpers.php:777-794`. |
| Portal token replay | Constant-time compare with `hash_equals`; optional TTL via `swh_token_expiration_days` (default 90; 0 disables). Pre-v1.9.0 tickets grandfathered (no `_ticket_token_created` → never expired). | `swh_is_token_expired()` at `helpers.php:336-346`; portal handlers in `frontend/class-portal.php`. |
| Direct file URL access | `swh_serve_file()` proxy validates the resolved path starts inside `wp_get_upload_dir()['basedir']`. Attached files are served only through this proxy. `.htaccess` + `index.php` are written under `wp-content/uploads/swh-helpdesk/` to block direct listing. | `includes/class-ticket.php:21` (action priority 1 on `init`); `swh_ensure_upload_protection()` called from `swh_activate()` at `class-installer.php:55`. |
| Inbound webhook spoofing | Three-factor: `Authorization: Bearer <swh_inbound_secret>` header; `[TKT-XXXX]` UID parsed from the subject; sender email compared to `_ticket_email` with `hash_equals`. All three must pass. | `swh_handle_inbound_email()` at `includes/class-email.php:222-`. |
| CSRF on form submission | `wp_verify_nonce()` on every POST handler. `check_ajax_referer()` on AJAX. REST endpoints either rely on cookie+nonce (capability callback) or the Bearer secret. | Throughout. Canonical: AJAX merge at `simple-wp-helpdesk.php:184`. |
| Privilege escalation via ticket comments | Reply comments are typed `helpdesk_reply` so they never leak into the site's default comment templates. Internal notes (`_is_internal_note=1`) are filtered out of frontend renderers in `frontend/class-portal.php`. | Comment-type migration at `class-installer.php:92-105`. |
| XSS on rendered ticket content | `esc_html()` for text, `esc_attr()` for attributes, `wp_kses_post()` for admin-edited HTML fields. Translations with HTML use `wp_kses()` with an explicit allowlist (e.g. `<code>` only) — see `simple-wp-helpdesk.php:136`. | Throughout. |
| Mass-assignment on ticket save | `swh_save_ticket_data()` in `admin/class-ticket-editor.php` reads only the documented input fields by name; no `$_POST` loop. | `admin/class-ticket-editor.php`. |
| File upload abuse | Extension allowlist via `swh_allowed_file_types` filter (`jpg, jpeg, jpe, png, gif, pdf, doc, docx, txt` by default — `class-shortcode.php:57, 517`). Size/count limits (`swh_max_upload_size`, `swh_max_upload_count`). Uploads confined to `wp-content/uploads/swh-helpdesk/`. Retention cron prunes orphans. | `frontend/class-shortcode.php` upload handlers; retention cron at `includes/class-cron.php`. |
| SQL injection | All raw SQL is parameterised through `$wpdb->prepare()`. The plugin runs no string-concatenated queries; direct queries (e.g. the comment-type migration at `class-installer.php:94-103` and the uninstall sweeps at `class-installer.php:228-229`) use `prepare()` or static string literals with no user input. | `class-installer.php:94-103, 228-229, 119`. |
| Misconfigured anti-spam | Fails **closed**. Missing reCAPTCHA Enterprise credentials, invalid JSON response, or missing success flag all return `true` (i.e. "block submission"). | `helpers.php:392-393, 404, 421-422, 435-437, 453-455`. |
| Side-channel timing on token compare | `hash_equals()` is used everywhere portal tokens are compared. | Portal handlers. |

## Capability matrix

| Action | Required capability | Additional gate |
|---|---|---|
| Submit a ticket | none (anonymous) | nonce + antispam + rate limit |
| View own ticket via portal | none (token-authenticated) | `hash_equals` on token + TTL |
| Reply via portal | none (token-authenticated) | `hash_equals` + rate limit (per-action key) |
| Close own ticket via portal | none (token-authenticated) | `hash_equals` + rate limit (`portal_close_*`) |
| Reopen own ticket via portal | none (token-authenticated) | `hash_equals` + rate limit (`portal_reopen_*` — distinct from close so immediate reopen is not blocked) |
| Submit CSAT rating | none (nopriv AJAX) | nonce + ticket_id match |
| View "My Tickets" dashboard | logged-in user | `wp_get_current_user()->user_email` equals `_ticket_email` of each shown ticket |
| Edit a ticket (admin) | `edit_post` on the ticket | (optional) `swh_restrict_to_assigned=yes` filters to the technician's own tickets in the list query and direct `load-post.php` |
| Bulk status change | `edit_post` on each ticket | nonce |
| Inbound email webhook | none (Bearer-authenticated) | `Authorization: Bearer` + subject `[TKT-XXXX]` + sender `hash_equals` |
| Merge tickets | `manage_options` | nonce |
| Send test email | `manage_options` | nonce |
| Save settings (main form) | `manage_options` | nonce (main) |
| Save Tools / destructive options | `manage_options` | nonce (Tools — distinct from main) |
| Read reporting | `manage_options` | nonce on AJAX |

## Explicit non-threats

The plugin does **not** defend against:

- **End-user impersonation via a shared inbox account.** If two clients use the same email address they will see each other's tickets through the lookup form. Email ownership is the site operator's concern.
- **WP admin compromise.** A user with `manage_options` can already do everything destructive the plugin offers (delete tickets, drop options, run uninstall). Site-level hardening is out of scope.
- **DDoS at network layer.** Rate limiting is per-IP at application layer for spam mitigation; CDN/edge filtering is the operator's responsibility.
- **Side-channel timing of `hash_equals` itself.** Assumed sufficient.
- **Mailbox spoofing where SPF / DKIM / DMARC are not enforced upstream of the inbound webhook.** The webhook compares the parsed sender to `_ticket_email`. If the inbound mail pipeline accepts spoofed `From:` headers, the webhook will accept the spoof. Operators must enforce SPF/DKIM/DMARC at their MTA.

## Security audit history

No formal external audit. Continuous SAST coverage via:

- Semgrep MCP server (developer-side, before commit).
- `.github/workflows/semgrep.yml` on every PR.
- PHPCS WordPress.Security rules.
- PHPStan level 9.

Findings are addressed in the PR that introduces or surfaces them.

## Reporting vulnerabilities

See `SECURITY.md` at the repository root.

## Update protocol

Update this doc when:
- A new untrusted input surface is added (new public REST/AJAX endpoint, new shortcode handler, new file upload path).
- An auth/authz flow changes (new capability check, new token type, new bypass path closed).
- A mitigation is added, removed, or substantively changed.
- A new explicit non-threat is recognised and documented.
- A security audit (internal or external) produces findings worth recording.
