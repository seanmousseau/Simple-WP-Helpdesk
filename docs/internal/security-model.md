# Security Model

**Audience:** plugin contributors and security reviewers.

## Trust boundaries

```text
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

## REST authentication

All `swh/v1` endpoints authenticate via standard WP REST `permission_callback` (except `inbound-email`, which uses a `Bearer` token in the header — see #441 / #442 callouts in `api-contract.md`). This means:

- **Cookie auth** — works for browser-based requests (admin UI calls).
- **Application passwords** — work for programmatic / integration access. Operators create one at *Users → Profile → Application Passwords* and pass it as the Basic Auth password.
- **Raw HTTP Basic Auth with a regular WP password** — does **not** work. The `wp_authenticate()` flow only accepts the user password for the login form, not for REST. A curl invocation using a regular admin password returns `401 rest_forbidden` even when the user has full admin capabilities.

Operators integrating with the REST API (Zapier, Make, custom scripts) MUST use application passwords. The `testing/extended/` runbook scripts use WP-CLI over SSH for this reason — they skip the REST surface entirely to avoid the Basic Auth confusion. (#442)

### API key authentication (v4.1.0+)

The v4.1.0 REST surface introduces operator-issued API keys as a second-class auth mechanism alongside WP Application Passwords. Application Passwords remain the preferred path for users who have WP accounts; API keys exist for integrations that should not impersonate a real WP user (CI bots, dashboards, third-party connectors).

**Bearer-token verification idiom.** Mirrors the inbound-email webhook precedent (`includes/class-email.php`): the client presents the plaintext key in the `Authorization: Bearer <plaintext>` header; the plugin extracts the first 12 chars as a prefix, narrows candidate entries with `hash_equals()` on the stored prefix (invariant #3), and then runs `password_verify()` against the stored bcrypt hash. All comparisons are constant-time.

**Storage.** Keys live inside the `swh_settings_integrations` option bag under the `api_keys` sub-key. `SWH\Rest\ApiKeys` (at `simple-wp-helpdesk/src/Rest/ApiKeys.php`) is the only writer — operators do not edit the list via the freeform settings save handler. Each entry stores a bcrypt hash (cost 12), a 12-char prefix, a scope (`read` or `write`), the issuing WP user id, timestamps, an optional per-key rate-limit override, and a `revoked_at` field. See `data-dictionary.md` for the exact shape.

**Plaintext lifetime.** The full plaintext (`swhk_<base62 32-char>`) is returned exactly once from `ApiKeys::generate()` and is **never** persisted, logged, or echoed elsewhere. The prefix is the only key-derived value safe to display in the operator UI after generation — it identifies the key without enabling its use.

**Scopes.** Two scopes are defined: `read` and `write`. `write` implies `read`. Phase 2's permission helper rejects scope mismatches with `swh_rest_scope_insufficient` (HTTP 403). There is intentionally no "client" or "owner" scope — see plan §14 / open question #6.

**Revocation.** `ApiKeys::revoke( $id )` is permanent — `revoked_at` is set and never cleared. `find_by_plaintext()` returns `null` for revoked entries so the request fails closed at `permission_callback`. There is no unrevoke path.

**Write-rate-limiting.** `ApiKeys::touch_last_used()` is internally rate-limited to once per 5 minutes per key via a `swh_apikey_touch_<id>` transient guard, preventing write amplification when a single key drives high-RPS traffic.

**Allowlist contract for `GET /settings/public`.** The Phase 5 settings allowlist excludes the entire `api_keys` sub-key. Bcrypt hashes never appear on any unauthenticated surface. Operators or auditors verifying this should grep `PublicSettingsAllowlist` for wildcards — there are none over the integrations bag.

#### Permission helper logic (v4.1.0 Phase 2)

`SWH\Rest\Auth::check_request()` is the single check every `swh/v1` route runs via `swh_rest_require( $scope )`. Controllers MUST NOT call `current_user_can()` inline — the helper is the only seam. Logic order:

1. **Logged-in WP user with `edit_posts`** → success (cookie auth or Application Password).
2. **Logged-in WP user without `edit_posts`** → `swh_rest_capability_required` (403). Does NOT fall through to the API key path — a session that already authenticated as a low-cap user cannot smuggle a higher-cap bearer token to elevate.
3. **No / malformed `Authorization` header** → `swh_rest_auth_required` (401).
4. **Bearer token that does not start with `swhk_`** → `swh_rest_auth_invalid` (401). The prefix is public — a wrong prefix is a malformed token, not a credential leak.
5. **Bearer token that does not resolve to an active entry** → `swh_rest_auth_invalid` (401). Revoked keys collapse into this code path because `ApiKeys::find_by_plaintext()` returns `null` for them.
6. **Scope mismatch** (`read` key on a `write` route) → `swh_rest_scope_insufficient` (403). `write` keys satisfy both `read` and `write` routes.
7. On success: `ApiKeys::touch_last_used()` runs (write-rate-limited inside ApiKeys), the full key entry is stashed on the request via `WP_REST_Request::set_param( '_swh_api_key', $entry )` for Phase 9 audit-log attribution, and the helper returns `true`.

The helper never logs the bearer token. The plaintext exists only in the in-memory request scope.

#### Per-route capability matrix (v4.1.0)

The matrix below is **documented here** and **enforced per-route** by each phase's controller via `swh_rest_require( 'read' | 'write' )`.

| Route group | Logged-in user | API key scope |
|---|---|---|
| `GET /tickets*`, `GET /replies*` | `edit_posts` | `read` |
| `POST /tickets`, `PATCH /tickets/*`, `POST /replies` | `edit_posts` | `write` |
| `GET /users/technicians`, `GET /categories` | `edit_posts` | `read` |
| `GET /settings/public` | **public** (no auth) | n/a |
| `GET /openapi.json` | **public** | n/a (Phase 8) |

### REST rate limiting (v4.1.0 Phase 7, #367)

Enforced by `SWH\Rest\RateLimiter` (`simple-wp-helpdesk/src/Rest/RateLimiter.php`). Two buckets, both fixed 60-second rolling windows:

- **Per-API-key bucket** — key `swh_rl_api_<key_id>`. Default budget **60 req/min**, overridable per-key via `Settings → Integrations → API Keys` (1–10000). Triggered from `Auth::check_request()` after the scope check.
- **Per-IP bucket** — key `swh_rl_ip_<hash>` where `hash = hash_hmac('sha256', $ip, wp_salt('nonce'))`. Budget **20 req/min**, not operator-configurable. Triggered from `Bootstrap::settings_public_permission()` — wraps the public `settings/public` route only.

**Why hash the IP?** Two reasons:

1. **Privacy / log hygiene.** Transient keys land in `wp_options` (or the persistent object cache). Without hashing, any operator with DB read access could enumerate every visitor IP that hit the public route in the last 60 seconds. Hashing the IP with `wp_salt('nonce')` produces a value that's deterministic *within a site* (same IP always hashes to the same bucket) but not reversible from the bucket key alone.
2. **Bucket-key forgery resistance.** A pre-image attacker who learned a victim IP could not, even with DB access, write a transient row that would block-or-allow that IP without also knowing the site's nonce salt.

The salt source is locked to `wp_salt('nonce')` because (a) it is already required to be set on a production WP site and (b) rotating it (operator-initiated) cleanly invalidates all open rate-limit windows — useful when responding to a DDoS attempt.

**Headers.** Every response — success or 429 — carries `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset`. The 429 response additionally carries `Retry-After: <seconds-to-window-end>`. Integrators rely on these to back off; the 429 path emits them via `Bootstrap::attach_rate_limit_headers()` (rest_post_dispatch filter), success path via `SWH\Rest\V1\ResponseDecorator::with_rate_limit_headers()`.

**What is NOT rate-limited.** Logged-in WP-user requests (the WP Application Password path) are NOT subject to the per-API-key bucket — they are already gated by core WP capability checks and core's own login throttling. Rate limiting in v4.1.0 targets the two anonymous attack surfaces: stolen API keys and unauthenticated public requests.

**Per-request idempotency.** WP REST's `rest_send_allow_header()` (wp-includes/rest-api.php:866-898) re-invokes a route's `permission_callback` once per registered handler on the matched route to build the response `Allow:` header. There is no filter to opt out — it is part of WP's REST contract. Without protection, `RateLimiter::check()` (which always increments on call) would fire 2-3× per HTTP request, halving the effective rate limit. Both `Auth::check_request()` and `Bootstrap::settings_public_permission()` therefore cache their computed result (true or WP_Error) on the request via `WP_REST_Request::set_param()` and short-circuit at the top — `_swh_auth_result_<scope>` for Auth (scope-aware because handlers on the same route may require different scopes), `_swh_settings_public_auth_result` for Bootstrap. `RateLimiter::check()` itself remains "always increments"; the dedupe lives in the callers.

**Anti-patterns explicitly forbidden:**

- Raw IPs in transient keys (privacy).
- Sharing a bucket key across read and write paths of the same API key — the limit is on total requests, not per-action.
- Suppressing `X-RateLimit-*` headers on the 429 path — integrators need them to back off cleanly.

## Public settings allowlist

`GET /swh/v1/settings/public` (v4.1.0 Phase 5, #364) is the **only** publicly readable endpoint in the v4.1.0 REST surface. Every key in its response goes through a **hard, explicit allowlist** defined in `simple-wp-helpdesk/src/Rest/V1/PublicSettingsAllowlist.php`. This subsection captures the rules; the file is the source of truth.

### Why an allowlist (not a denylist)

A denylist defaults *open* — every new setting added in some future release would be publicly readable unless someone remembered to add it to the denylist. The plugin already stores webhook secrets, API keys, SMTP credentials, and email template bodies in the same options bag as harmless labels; a single forgotten denylist entry leaks the secret to the internet.

The allowlist defaults *closed*: settings are private unless someone makes a deliberate decision to expose them. New settings can never accidentally leak.

### Locked allowlist keys (v4.1.0)

| Response key | Source option(s) | Shape |
|---|---|---|
| `priority_labels` | `swh_ticket_priorities` | `string[]` (CSV → trimmed array) |
| `status_labels` | `swh_ticket_statuses` | `string[]` |
| `sla_thresholds` | `swh_sla_warn_hours`, `swh_sla_breach_hours` | `{ low:int\|null, medium:int\|null, high:int\|null, urgent:int\|null }` (hours) |
| `features` | `swh_csat_enabled`, `swh_ticket_page_id`, `swh_max_upload_count` | `{ csat:bool, portal:bool, attachments:bool }` |

The `features` sub-shape is **booleans only** — never the underlying values. `portal` is `true` iff a portal page is configured; the page ID is not exposed. `attachments` is `true` iff uploads are allowed; the size/count caps are not exposed.

### Adding a key — review burden

Adding a key to the allowlist is a deliberate decision. The PR that adds one MUST:

1. Edit `PublicSettingsAllowlist::map()` and add a reader method that returns ONLY the public-safe shape (never the raw option value).
2. Update this subsection — add a row to the locked-keys table above.
3. Extend the `LOCKED_KEYS` constant in `tests/Unit/Rest/V1/PublicSettingsAllowlistTest.php`. The drift-detector test `test_allowlist_only_contains_locked_keys()` fails immediately if step 3 is skipped, forcing reviewers to notice the security review burden.
4. In the PR description, justify why the option's value can never carry secrets — and propose a future-proofing test if the value could in principle become secret-bearing (e.g. an admin-customisable label that some operator might use as a free-text field).

### Known-secret exclusion (negative test)

The drift-detector suite also asserts that **none** of the following option keys can be in the allowlist:

- `swh_inbound_secret` / `inbound_webhook_secret` — bearer token for the inbound email webhook.
- `api_keys` — v4.1.0 API key entries (Phase 6 surface).
- `smtp_password`, `smtp_user`, `smtp_host` — third-party SMTP-plugin patterns.
- `swh_recaptcha_secret_key`, `swh_recaptcha_api_key`, `swh_turnstile_secret_key` — anti-spam provider secrets.
- `swh_em_user_new_body`, `swh_em_admin_new_body`, etc. — operator-authored email template bodies, which may contain internal phrasing, signed URLs, or workflow details.

If a future PR adds any of these to the allowlist, `test_known_secrets_are_not_in_allowlist` fails in CI.

### No-wildcard guarantee (static check)

`test_no_wildcard_or_prefix_match` reads the `PublicSettingsAllowlist` source file and asserts it contains **none** of: `preg_match`, `preg_match_all`, `fnmatch`, `str_starts_with`, `str_ends_with`, `wp_load_alloptions`, or a `foreach` over `$wpdb`. The allowlist must remain an explicit array of named keys. Anyone trying to introduce "expose all `swh_settings_general` keys" fails CI.

### Related privacy lever

`GET /swh/v1/users/technicians` (also v4.1.0 Phase 5) is gated by `read` scope but the email field can be a privacy concern for some operators. The `swh_hide_staff_email` filter, when it returns `true`, OMITS the `email` key from every object in the response. Default is `false` (email exposed to authenticated `read`-scope callers).

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
