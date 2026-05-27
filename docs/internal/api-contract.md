# API Contract

**Audience:** integrators writing PHP against the plugin, and contributors changing the plugin's public surface.

## What is public, what is internal

**Public** (covered by the SemVer policy below):

- Action hooks documented in `docs/hooks.md`.
- Filter hooks listed under "Filter hooks" in this doc.
- The REST endpoint `POST /wp-json/swh/v1/inbound-email`.
- The `nopriv` AJAX endpoint `wp_ajax_nopriv_swh_submit_csat`.
- Shortcodes `[submit_ticket]` and `[helpdesk_portal]` with their documented attributes.
- Constants `SWH_VERSION`, `SWH_PLUGIN_DIR`, `SWH_PLUGIN_URL`, `SWH_PLUGIN_FILE`.
- Helper functions documented with `@since` in PHPDoc and called from outside their defining file (e.g. `swh_get_secure_ticket_link`, `swh_send_email`, `swh_set_ticket_status`, `swh_render_empty_state`).

**Internal** (may change in any release):

- Functions and classes not listed above. Even if they have `swh_` prefix.
- Database schema beyond what `data-dictionary.md` documents.
- Admin AJAX endpoints (`wp_ajax_swh_merge_ticket`, `wp_ajax_swh_send_test_email`, `wp_ajax_swh_report_data`) — these are admin-only and gated by `manage_options`.
- CSS class names beyond those in `component-inventory.md`.
- In v4.0+, the `$group` argument to `swh_get_option()` selects the `swh_settings_<group>` storage bucket. (Historically advisory in v3.7; the v4.0 schema migration made it the storage discriminator.)

If you depend on an internal API, pin to a version and read the changelog before upgrading.

## Versioning policy

[SemVer](https://semver.org/). Breaking-change taxonomy:

| Change | Bump |
|---|---|
| Removing a public hook | MAJOR |
| Renaming a public hook | MAJOR |
| Changing a public hook signature (arg count, arg order, arg type) | MAJOR |
| Adding a public hook | MINOR |
| Removing a setting | MAJOR (operator-visible) |
| Changing a setting's default in a way that alters runtime behaviour | MAJOR |
| Adding a setting | MINOR |
| Changing a REST endpoint URL | MAJOR |
| Adding a REST endpoint or new query/body parameter | MINOR |
| Changing a shortcode attribute default | MAJOR |
| Adding a shortcode attribute | MINOR |
| Removing a meta key without an upgrade routine | MAJOR |
| Adding a meta key | MINOR |
| Bug fixes / internal refactor with no external surface change | PATCH |

When MAJOR is unavoidable, the previous behaviour is wrapped in `simple-wp-helpdesk/includes/deprecations.php` so old hooks fire alongside new ones for at least one MAJOR cycle. See `docs/developer/deprecations.md`.

## Action hooks

Listed in `docs/hooks.md`. Enumerated for reference:

| Hook | Signature | Since |
|---|---|---|
| `swh_pre_ticket_create` | `(array $data)` | 2.1.0 |
| `swh_ticket_created` | `(int $ticket_id, array $data)` | 2.1.0 |
| `swh_ticket_status_changed` | `(int $ticket_id, string $old_status, string $new_status)` | 3.7.0 |
| `swh_ticket_closed` | `(int $ticket_id, string $previous_status)` | 3.7.0 |
| `swh_ticket_reopened` | `(int $ticket_id, string $previous_status)` | 3.7.0 |
| `swh_ticket_replied` | `(int $ticket_id, int $comment_id, bool $is_staff_reply)` | 3.7.0 |
| `swh_ticket_assigned` | `(int $ticket_id, int $old_user_id, int $new_user_id)` | 3.7.0 |
| `swh_csat_submitted` | `(int $ticket_id, int $rating)` | 3.7.0 |
| `swh_sla_breached` | `(int $ticket_id, int $minutes_over)` | 3.7.0 |

See `docs/hooks.md` for usage examples and full documentation.

## Filter hooks

Enumerated from `grep -rn "apply_filters(\s*['\"]swh_" simple-wp-helpdesk/`:

| Hook | Signature | Purpose | Since | Deprecated | Location |
|---|---|---|---|---|---|
| `swh_ticket_statuses` | `(string[] $statuses) -> string[]` | Override the available ticket status labels. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/helpers.php` |
| `swh_ticket_priorities` | `(string[] $priorities) -> string[]` | Override the available priority labels. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/helpers.php` |
| `swh_allowed_file_types` | `(string[] $extensions) -> string[]` | Override the upload extension allowlist (default `jpg, jpeg, jpe, png, gif, pdf, doc, docx, txt`). Applied at both submit and reply upload paths. | pre-3.0 | 4.0 (removal target: 4.2) | `frontend/class-shortcode.php` (2 sites) |
| `swh_submission_data` | `(array $data) -> array` | Mutate sanitized submission fields before insert. Fires after sanitization, before `swh_pre_ticket_create`. | pre-3.0 | 4.0 (removal target: 4.2) | `frontend/class-shortcode.php` |
| `swh_autoclose_threshold` | `(int $days) -> int` | Override the auto-close threshold (default `swh_autoclose_days` option). | pre-3.0 | 4.0 (removal target: 4.2) | `includes/class-cron.php` |
| `swh_rate_limit_ttl` | `(int $ttl, string $action) -> int` | Override the rate-limit TTL per action. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/helpers.php` |
| `swh_parse_template` | `(string $rendered, array $data) -> string` | Post-process a parsed email template body or subject. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/class-email.php` |
| `swh_email_headers` | `(string[] $headers, string $to, string $subject) -> string[]` | Add or replace email headers. Canonical place to add Reply-To, BCC, or X-* headers. | pre-3.0 | 4.0 (removal target: 4.2) | `includes/class-email.php` |
| `swh_sla_open_statuses` | `(string[] $statuses) -> string[]` | Statuses considered "open" by the SLA cron. | post-3.0 | — | `includes/class-cron.php` |

**Note:** 8 of the 9 filters listed above were wrapped in `swh_apply_deprecated_filter()` in v4.0.0 (#360). They continue to fire for existing listeners (back-compat preserved) but emit `_doing_it_wrong` notices in `WP_DEBUG` environments. Earliest removal: v4.2. `swh_sla_open_statuses` is post-3.0 and remains supported.

## Public REST API v1

### Machine-readable contract

The full API contract is published in three places:

- **Admin viewer** — **Tools → Helpdesk API Reference** (requires `manage_options`). Vendored Redoc viewer (`simple-wp-helpdesk/assets/vendor/redoc/redoc.standalone.js`, MIT-licensed); no CDN call.
- **Public docs site** — https://seanmousseau.github.io/Simple-WP-Helpdesk/api-reference.html (same Redoc viewer, same JSON spec).
- **JSON endpoint** — `GET /wp-json/swh/v1/openapi.json` (public, rate-limited per-IP via the same bucket as `settings/public`). Point Postman / Insomnia at this URL for a one-click import.

**Source of truth:** `docs/openapi.yaml` (committed). The JSON form `docs/openapi.json` is a build artifact regenerated from the YAML by `bin/openapi-sync.sh`; CI (`.github/workflows/php-tests.yml` → `openapi` job) fails if the two are out of sync, plus runs `npx @redocly/cli lint`.

Schema definitions live in `simple-wp-helpdesk/src/Rest/V1/Schemas/*.php`. **Editing those files is a public-API change** — the OpenAPI spec must update in the same PR. The `OpenApiSpecTest` drift detector (`tests/Unit/OpenApiSpecTest.php`) enumerates every `Schemas/*.php` and every `Errors` constant, failing if either drifts away from the spec.

The v4.1.0 release introduces the first formally-public REST surface under the `swh/v1` namespace. Until v4.1.0, the only documented REST endpoint was `POST /wp-json/swh/v1/inbound-email`; the other `swh/v1/*` routes (`tickets`, `views`, `inbox`, `bulk-action`, `technicians`, `palette`) were and remain **internal** controllers consumed by the bundled admin-inbox React app — they live alongside the v1 surface but are not part of the public contract documented below.

**Namespace:** `swh/v1`
**Bootstrap:** `SWH\Rest\Bootstrap` (`simple-wp-helpdesk/src/Rest/Bootstrap.php`) — registers the namespace on `rest_api_init`. Public-surface controllers (Phases 3+) register themselves through `Bootstrap::register_controller()`.

### Authentication

Two schemes:

1. **WP Application Password (preferred for user-scoped access).** Standard WP 5.6+ Application Passwords. Auth resolves via `current_user_can( 'edit_posts' )`. Operators create one at *Users → Profile → Application Passwords* and pass it as the Basic Auth password.
2. **API Key (service-to-service).** Bearer token: `Authorization: Bearer swhk_<32 chars>`. Keys are managed under Settings → Integrations (Phase 6 UI). Scopes: `read` / `write`. `write` keys satisfy both `read` and `write` routes. See `security-model.md` → "API key authentication" for the full auth flow.

Public routes (`GET /settings/public`, `GET /openapi.json`) accept anonymous requests. Every other route requires one of the two schemes above. The single `permission_callback` for the entire namespace is `swh_rest_require( $scope )` — controllers MUST NOT inline `current_user_can()` checks.

### Error envelope

Every error response in `swh/v1` is shaped:

```json
{ "code": "swh_rest_<reason>", "message": "Human readable", "data": { "status": <int> } }
```

Locked code enum (also defined as `SWH\Rest\Errors` class constants):

| Code | Status | When |
|---|---|---|
| `swh_rest_auth_required` | 401 | No / malformed `Authorization` header on a protected route |
| `swh_rest_auth_invalid` | 401 | Bearer token doesn't resolve to a valid, non-revoked key |
| `swh_rest_scope_insufficient` | 403 | Valid key but scope insufficient for route |
| `swh_rest_capability_required` | 403 | Logged-in WP user lacks the required capability |
| `swh_rest_rate_limited` | 429 | Per-key rate limit exceeded (Phase 7) |
| `swh_rest_not_found` | 404 | Resource lookup failed |
| `swh_rest_validation_failed` | 400 | Schema / input validation failure |

### Endpoints

Documented per controller in Phases 3–5 / 8 of the v4.1.0 plan. Phase 2 ships the namespace + permission helper only — this section will grow as v4.1.0 lands.

#### `swh/v1/tickets` — Tickets CRUD (Phase 3, #362)

Controller: `simple-wp-helpdesk/src/Rest/V1/TicketsController.php`. Schema source of truth: `simple-wp-helpdesk/src/Rest/V1/Schemas/Ticket.php` (also feeds the Phase 8 OpenAPI `Ticket` component).

| Method | Path | Cap | Scope | Response |
|---|---|---|---|---|
| GET | `/swh/v1/tickets` | `edit_posts` | `read` | 200 + array of tickets, `X-WP-Total` + `X-WP-TotalPages` headers |
| GET | `/swh/v1/tickets/{id}` | `edit_posts` | `read` | 200 ticket / 404 |
| POST | `/swh/v1/tickets` | `edit_posts` | `write` | 201 ticket / 400 |
| PATCH | `/swh/v1/tickets/{id}` | `edit_posts` | `write` | 200 ticket / 400 / 404 |

**List filters (query params):**

- `status[]` — array of status labels (e.g. `status[]=Open&status[]=In Progress`).
- `priority` — single priority label.
- `assignee` — WP user ID.
- `category` — `helpdesk_category` term ID.
- `after` / `before` — ISO-8601 timestamps (inclusive).
- `search` — free-text search over subject + body.
- `page` (default 1) / `per_page` (default 20, max 100).

Pagination headers: `X-WP-Total` (total result count) and `X-WP-TotalPages` (total pages at current `per_page`).

**Ticket response shape (allowlist — no other keys appear):**

```json
{
  "id": 42,
  "subject": "PDF download broken",
  "status": "Open",
  "priority": "High",
  "assignee": { "id": 3, "display_name": "Jane Tech" },
  "requester": { "email": "client@example.com", "name": "Alice Client" },
  "category": { "id": 7, "name": "Billing", "slug": "billing" },
  "sla": { "due_at": 1779999999, "breached": false },
  "csat": { "score": null, "comment": null },
  "created_at": 1779800000,
  "updated_at": 1779880000,
  "first_response_at": null,
  "links": {
    "self":    "https://example.com/wp-json/swh/v1/tickets/42",
    "replies": "https://example.com/wp-json/swh/v1/tickets/42/replies"
  }
}
```

Internal post meta (`_swh_unread`, `_swh_api_access_log`, `_ticket_token`, …) is intentionally excluded.

**POST `/swh/v1/tickets` body (minimum):**

```json
{ "subject": "Need help", "requester_email": "client@example.com" }
```

Optional: `body`, `requester_name`, `priority`, `status`, `assignee`, `category`, `cc_emails`. All optional fields are forwarded to `swh_update_ticket_fields()` after the post is created; any validation failure surfaces as `swh_rest_validation_failed` with the failing field key in `data.errors`.

**PATCH `/swh/v1/tickets/{id}` body** — any subset of the keys above (no `subject` is required; PATCH is partial). Status / priority / assignee / category / subject / body / requester contact mutations all route through `swh_update_ticket_fields()` (invariant #19, design-document.md). Invalid values are rejected before any side effects with `swh_rest_validation_failed`.

**Error codes** (in addition to the standard `swh_rest_*` envelope from §"Error envelope"):

| Status | Code | When |
|---|---|---|
| 400 | `swh_rest_validation_failed` | Required field missing / unknown enum value / role check fails. Field keys are in `data.errors`. |
| 404 | `swh_rest_not_found` | Ticket ID unknown or post type mismatch. |

#### `swh/v1/tickets/{id}/replies` — Replies subcollection (Phase 4, #363)

| Method | Path | Cap | Scope | Returns |
|---|---|---|---|---|
| GET | `/swh/v1/tickets/{id}/replies` | `edit_posts` | `read` | 200 + array of reply payloads / 404 if ticket unknown |
| POST | `/swh/v1/tickets/{id}/replies` | `edit_posts` | `write` | 201 + reply payload / 400 / 404 |

Every insertion routes through `swh_insert_helpdesk_reply()` — the canonical reply-insert helper (invariant #20, design-document.md). The controller never calls `wp_insert_comment` directly. Body is `wp_kses_post`-sanitised at the helper boundary; attachment media IDs are validated (must exist, must be `attachment` post type, must pass the MIME allowlist that mirrors `swh_handle_multiple_uploads()`). Internal notes (`is_internal_note=true`) store `_is_internal_note=1` on the comment and are excluded from GET responses returned to `read`-scope callers.

**Internal-note visibility note.** The v4.1.0 surface has only `read` and `write` scopes — there is no separate "client" scope. Internal notes are excluded from GET responses returned to `read`-scope callers (clients) and surfaced to `write`-scope callers (staff / trusted integrations). #363 originally framed the rule against a "client" scope; restated here for clarity.

**Reply response (the OpenAPI Phase 8 `Reply` component, locked in `src/Rest/V1/Schemas/Reply.php`):**

```json
{
  "id": 1001,
  "body": "Looking into it",
  "author": {
    "type": "staff",
    "id": 7,
    "display_name": "Tech",
    "email": "tech@example.com"
  },
  "is_internal_note": false,
  "created_at": 1716800000,
  "attachments": [
    { "id": null, "url": "https://example.com/wp-content/uploads/swh-helpdesk/file.pdf", "filename": "file.pdf", "mime": null, "size": null }
  ],
  "links": {
    "self":   "https://example.com/wp-json/swh/v1/tickets/42/replies/1001",
    "ticket": "https://example.com/wp-json/swh/v1/tickets/42"
  }
}
```

**POST `/swh/v1/tickets/{id}/replies` body:**

```json
{
  "body": "Looking into it",
  "is_internal_note": false,
  "attachments": [123, 456]
}
```

`body` is required unless at least one `attachments` media ID is supplied (mirrors the admin "Attached file(s)" fallback). `is_internal_note=true` requires the `write` scope (already gated by the route). `attachments` is an array of media IDs; each is validated server-side and a single bad ID rejects the whole POST with `swh_rest_validation_failed` and `data.errors.attachments` populated.

**Error codes:**

| Status | Code | When |
|---|---|---|
| 400 | `swh_rest_validation_failed` | Body missing without attachments; attachment ID invalid (`data.errors.attachments`). |
| 404 | `swh_rest_not_found` | Ticket ID unknown or post type mismatch. |

#### `swh/v1/users/technicians` — Technician roster (Phase 5, #364)

| Method | Path | Cap | Scope | Returns |
|---|---|---|---|---|
| GET | `/swh/v1/users/technicians` | `edit_posts` | `read` | 200 + array of technician payloads |

Read-only listing of users carrying the `edit_posts` capability. Response objects use a hard allowlist — exactly `{ id, display_name, email }`. `user_login`, `roles`, `capabilities`, and raw meta are never returned. Ordered by `display_name` ascending.

**Privacy lever — `swh_hide_staff_email` filter.** Add `add_filter( 'swh_hide_staff_email', '__return_true' )` and the `email` field is OMITTED from every object (the key disappears entirely; it is not null). Useful when client-scope keys consume this endpoint but the operator does not want staff inboxes exposed.

```json
[
  { "id": 7, "display_name": "Alice", "email": "alice@example.com" },
  { "id": 11, "display_name": "Bob",  "email": "bob@example.com" }
]
```

#### `swh/v1/categories` — Helpdesk categories (Phase 5, #364)

| Method | Path | Cap | Scope | Returns |
|---|---|---|---|---|
| GET | `/swh/v1/categories` | `edit_posts` | `read` | 200 + array of category payloads |

Read-only listing of `helpdesk_category` taxonomy terms. Response objects use a hard allowlist — exactly `{ id, name, slug, parent, count }`. Term description and meta are never returned. Ordered by `name` ascending. Empty taxonomy returns `[]` (not an error).

```json
[
  { "id": 3, "name": "Billing", "slug": "billing", "parent": 0, "count": 5 },
  { "id": 4, "name": "Bug",     "slug": "bug",     "parent": 0, "count": 12 }
]
```

#### `swh/v1/settings/public` — Public operator settings (Phase 5, #364)

| Method | Path | Auth | Returns |
|---|---|---|---|
| GET | `/swh/v1/settings/public` | **public** (no auth) | 200 + allowlisted subset |

The ONLY public (no-auth) endpoint in the v4.1.0 surface. The route registers `'permission_callback' => '__return_true'` — deliberate, see `security-model.md` → "Public settings allowlist". Every key in the response comes from `\SWH\Rest\V1\PublicSettingsAllowlist::resolve()`; the file is the single source of truth. Wildcards, prefix matches, and "loop all options" patterns are forbidden — see the no-wildcard static test.

Locked response (v4.1.0):

```json
{
  "priority_labels": ["Low", "Medium", "High"],
  "status_labels":   ["Open", "In Progress", "Resolved", "Closed"],
  "sla_thresholds":  { "low": null, "medium": 4, "high": 8, "urgent": null },
  "features":        { "csat": true, "portal": true, "attachments": true }
}
```

`sla_thresholds` keys are priority buckets; values are hours. Today the plugin stores a single warn/breach pair (not per-priority) — `medium` mirrors `swh_sla_warn_hours`, `high` mirrors `swh_sla_breach_hours`, `low` and `urgent` are null until per-priority thresholds ship in a future release.

`features` is **booleans only** — never the underlying config values. `portal` is true iff `swh_ticket_page_id > 0`; the page ID is not exposed. `attachments` is true iff `swh_max_upload_count > 0`; the cap is not exposed.

Phase 7 (#367) adds a per-IP rate limit of **20 requests / minute** to this route. The bucket key is `swh_rl_ip_<hash_hmac('sha256', $ip, wp_salt('nonce'))>` — see `security-model.md` for why the IP is hashed.

## Rate limiting (Phase 7, #367)

Two buckets:

- **Per-API-key bucket** (`swh_rl_api_<key_id>`) — applies to every authenticated `swh/v1/*` route. Default: **60 requests / minute**. Operators can override per-key in the *Settings → Integrations → API Keys* form (1–10000). The override on the key entry wins; absent override falls back to the 60/min default.
- **Per-IP bucket** (`swh_rl_ip_<hmac>`) — applies only to the anonymous `GET /settings/public` route. Fixed at **20 requests / minute**, not operator-configurable.

The two buckets are independent. Exhausting `/settings/public` from one IP does not affect that IP's authenticated calls; exhausting a key does not affect other keys, and does not affect logged-in WP-user auth on the same route.

**Window semantics.** Fixed 60-second rolling window keyed on first request. The 61st request in the window returns `429 swh_rest_rate_limited` and the response includes `Retry-After: <seconds-to-window-end>`. When the window expires, the counter resets to 1 on the next request and the reset timestamp jumps forward by 60 seconds.

**Response headers (emitted on every response — success AND 429):**

| Header | Value |
|---|---|
| `X-RateLimit-Limit` | the active budget (60 default, or per-key override, or 20 for the public route) |
| `X-RateLimit-Remaining` | `max(0, limit - count)` for the current window |
| `X-RateLimit-Reset` | unix timestamp when the current window ends |
| `Retry-After` | seconds-to-reset; emitted **only** when status is 429 |

**429 body** uses the standard error envelope:

```json
{ "code": "swh_rest_rate_limited", "message": "API rate limit exceeded. Try again later.", "data": { "status": 429, "retry_after": 17 } }
```

**Storage.** WP transients with a `wp_options` floor (key prefix `_swh_rl_`). The floor exists so sites without persistent object cache still have a stable counter when the transient store evicts under memory pressure. The `RateLimiter::check()` return shape (`{ allowed, remaining, reset, limit }`) is the contract — the storage layer is private.

**Bucket-name privacy.** Raw client IPs **never** appear in transient keys — see `security-model.md` for the HMAC + salt rationale.

## REST envelope contract

All `swh/v1` endpoints follow a uniform envelope shape. JS clients (admin-inbox, portal-v2, reports-v2) rely on these shapes — changing one is a breaking change that must update consumers in the same PR. Contract tests in `tests/Unit/Rest/Contracts/` lock these shapes.

| Status | Envelope | Used by | Notes |
|---|---|---|---|
| `200` / `201` | resource-keyed: `{ view }`, `{ views }`, `{ tickets }`, full ticket payload | success | The HTTP status code carries the success bit; the body carries the resource. No `success: true` redundancy. |
| `200` (delete) | `{ deleted: bool }` | DELETE `/views/{id}` | Distinguishes "operation rejected" (would be 403) from "already gone" (`deleted: false` + 404). |
| `200` (bulk) | `{ succeeded: int[], conflicts: [{ id, version_now, error? }] }` | POST `/bulk-action` | Per-ticket conflicts carry `version_now`; `error` field is present only for non-version conflicts (`not_found`, `forbidden`). Absence of `error` ⇒ stale-version conflict, safe to retry. |
| `400` | `{ error: <code>, ...extras }` | validation failures | `extras` may include caps like `max`. Example: `{ error: 'too_many_tickets', max: 200 }`. |
| `403` | `{ error: 'forbidden' }` | capability failures | Returned by `ViewsController` when a non-admin attempts a global-scope mutation. |
| `404` | `{ error: 'not_found' }` | missing ticket / view | Same shape used by `TicketController` and `ViewsController` update paths. |
| `409` | `{ error: 'version_conflict', current: <full ticket payload> }` | stale optimistic-lock | The `current` payload lets the React inbox reconcile without an extra round-trip. `current.version` reflects server state; client retries with that value. |
| `500` | `{ error: <code> }` | internal failure | Codes in use: `comment_insert_failed`, `save_failed`. |

Error envelopes have exactly the keys named above — no extras unless documented per-endpoint (`extras` column in the bulk row). The contract test base asserts both presence of required keys AND absence of undocumented ones.

## REST endpoints

> **The `helpdesk_ticket` CPT is intentionally NOT registered with `show_in_rest = true`.** The standard `/wp-json/wp/v2/helpdesk_ticket` routes do **not** exist for this post type — any request to them returns `404 rest_no_route`. Read/write access is via the plugin's custom `/swh/v1/...` endpoints (below) or via WP-CLI / direct DB access. This is by design — exposing the CPT through the default REST surface would bypass the plugin's `permission_callback` per-ticket gates. (#441)

> **All `swh/v1` endpoints authenticate via cookie OR application password — not raw HTTP Basic Auth with a regular WP password.** A curl invocation using a regular admin password as the Basic Auth secret will return `401 rest_forbidden` even when the user has full admin capabilities. For programmatic / integration access, operators MUST create an [application password](https://wordpress.org/documentation/article/application-passwords/) (Users → Profile → Application Passwords) and use that as the Basic Auth password. Example:
> ```bash
> curl -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
>      https://example.com/wp-json/swh/v1/palette/suggest?q=test
> ```
> (#442)

| Method | Path | Auth | Body | Response |
|---|---|---|---|---|
| `POST` | `/wp-json/swh/v1/inbound-email` | `Authorization: Bearer <swh_inbound_secret>` header. `permission_callback` is `__return_true` (auth enforced inside the handler). | Parsed-email JSON; the handler reads `from`, `subject`, `body`. Subject must contain `[TKT-XXXX]`. Sender email is compared to `_ticket_email` of the matched ticket via `hash_equals`. | `200` on accepted reply; `4xx` on auth / parse / match failure. |
| `POST` | `/wp-json/swh/v1/bulk-action` | `edit_posts` | `{ action, params, ticket_ids[], version_map{} }` | `{ succeeded[], conflicts: [{ id, version_now }] }`. Cap: 200 tickets. Introduced 4.0.0 #353. |
| `GET` | `/wp-json/swh/v1/palette/suggest` | `edit_posts` | — | `{ tickets: [{ id, title, status }] }`. Param `q` (string). Up to 8 results. Introduced 4.0.0 #351. |
| `GET` | `/wp-json/swh/v1/views` | `edit_posts` | — | `{ views: [{ id, name, scope, filters, sort, columns }] }`. Introduced 4.0.0 #352. |
| `POST` | `/wp-json/swh/v1/views` | `edit_posts` (personal) / `manage_options` (global) | `{ name, scope, filters, sort, columns }` | `{ view }` at 201. Introduced 4.0.0 #352. |
| `PATCH` | `/wp-json/swh/v1/views/{id}` | `edit_posts` (personal) / `manage_options` (global) | `{ scope, patch }` | `{ view }` at 200. Introduced 4.0.0 #352. |
| `DELETE` | `/wp-json/swh/v1/views/{id}` | `edit_posts` (personal) / `manage_options` (global) | — | `{ deleted: bool }`. Param: `scope`. Introduced 4.0.0 #352. |

Registered at `simple-wp-helpdesk/simple-wp-helpdesk.php:213`. Handler at `simple-wp-helpdesk/includes/class-email.php:222`.

**Idempotency:** the handler does not de-duplicate inbound messages; the upstream MTA / inbound-parse provider is responsible for at-most-once delivery. The handler is safe to call repeatedly — it will insert a reply comment each time.

**Authorization header note:** Apache running in front of PHP-FPM strips the `Authorization` header by default. Operators must configure `.htaccess` or vhost to pass it through, or use a server-internal call path. Test section 47 documents the bypass used in CI (calling `swh_handle_inbound_email()` directly via `wp eval`).

## AJAX endpoints

| Action | Auth | Purpose |
|---|---|---|
| `wp_ajax_nopriv_swh_submit_csat` (also `wp_ajax_swh_submit_csat`) | nonce | Records a 1–5 CSAT rating into `_ticket_csat` post meta. **Public surface.** | 
| `wp_ajax_swh_merge_ticket` | `manage_options` + nonce | Admin-only ticket merge. **Internal.** |
| `wp_ajax_swh_send_test_email` | `manage_options` + nonce | Admin-only test email sender. **Internal.** |
| `wp_ajax_swh_report_data` | `manage_options` + nonce | Reporting data fetch with transient caching. **Internal.** |

> **Reports v2 (#359):** `swh_report_data` action accepts optional params:
> - `range_start`, `range_end` (ISO `Y-m-d` dates) — constrain the reporting window.
> - `compare_to_prior` (`1`/`0`; default `1`) — compute prior-period data.
>
> Response shape extends with `range`, `prior`, and `prior_range` keys when range params are supplied.
> Existing callers that ignore those keys keep working. Transient cache is bypassed when a custom range is provided.

Registered:

- `class-ticket.php:391-392` — CSAT.
- `simple-wp-helpdesk.php:173` — merge.
- `admin/class-settings.php:1035` — test email.
- `admin/class-reporting.php:12` — report data.

## Shortcodes

Both shortcodes are dispatched by separate handlers — `[submit_ticket]` → `swh_ticket_frontend()` and `[helpdesk_portal]` → `swh_helpdesk_portal_shortcode()`, both in `frontend/class-shortcode.php`.

| Shortcode | Attribute | Default | Effect |
|---|---|---|---|
| `[submit_ticket]` | `show_priority` | `yes` | Show/hide the priority select field. |
| `[submit_ticket]` | `default_priority` | `Medium` (from `swh_default_priority`) | Pre-select a priority value. |
| `[submit_ticket]` | `default_status` | `Open` (from `swh_default_status`) | Pre-set the new ticket status on save. |
| `[submit_ticket]` | `show_lookup` | `yes` | Show/hide the "lost your link?" lookup form below the submit form. |
| `[helpdesk_portal]` | (same attributes) | | When the URL lacks a `token`, renders either the My Tickets dashboard (logged-in users) or the lookup form (guests). |

> **Portal v2 (#354):** Portal v2 moves reply/close/reopen from inline PHP POST handling (same page) to dedicated `admin-post.php` handlers registered as both `admin_post_nopriv_*` and `admin_post_*`: `swh_portal_reply`, `swh_portal_close`, `swh_portal_reopen`, `swh_portal_csat`. All require `ticket_id` + `token` (verified via `hash_equals`) and `swh_portal_reply_nonce` (verified via `wp_verify_nonce`). On success, each handler does `wp_safe_redirect` back to the portal URL. The reply form field is `reply_body`; the nonce field is `swh_portal_reply_nonce`. The v2 JS sends the reply via `fetch()` optimistically and falls back to a standard full-page form submit if JS is unavailable.

## Constants

| Constant | Type | Source of truth |
|---|---|---|
| `SWH_VERSION` | string | `simple-wp-helpdesk/simple-wp-helpdesk.php:19` |
| `SWH_PLUGIN_DIR` | string | bootstrap |
| `SWH_PLUGIN_URL` | string | bootstrap |
| `SWH_PLUGIN_FILE` | string | bootstrap |
| `SWH_ICON_1X`, `SWH_ICON_2X`, `SWH_MENU_ICON` | string | bootstrap (icon URLs) |

These are guaranteed across releases. Renaming or removing any of them is MAJOR.

## Deprecation policy

When a public hook or function must be removed:

1. The new replacement ships first, in a MINOR release.
2. The old name continues to fire (or function) for at least one MAJOR cycle, implemented in `simple-wp-helpdesk/includes/deprecations.php`.
3. Each shim calls `_deprecated_hook()` or `_deprecated_function()` once per page load (WP's own throttle) so debug logs surface usage.
4. The shim is removed only in the MAJOR release **after** the introduction MAJOR.

See `docs/developer/deprecations.md` for the current shim list.

## Update protocol

Update this doc when:
- Any item in the "public surface" lists above is added, removed, renamed, or changes signature.
- A new filter hook is introduced — add a row to the filter table.
- The SemVer taxonomy is amended.
- A deprecation enters or leaves `includes/deprecations.php`.
