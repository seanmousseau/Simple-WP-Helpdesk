# v4.1.0 — Event Surface & REST API: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL — use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans`. Each phase below corresponds to one PR into `release/v4.1.0`. Phases are ordered by dependency; do not reorder without an explicit replan.

**Goal:** Ship a public REST API v1 with scoped API keys, per-key rate limiting, an OpenAPI 3.1 contract (admin viewer + public site + JSON endpoint), and an opt-in per-ticket API access audit log. Lay the foundation for v4.2 outbound webhooks.

**Theme:** Public REST API + key management + machine-readable contract.

**Milestone:** [#19](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/19) — 10 issues (9 REST + #504 docs).

**Roadmap source:** `docs/internal/release_v4.x.x_roadmap.md` lines 105–125.

**Depends on:**
- v3.7 lifecycle action hooks ([#361](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/361)) — already shipped.
- v4.0 settings schema migration ([#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356)) — already shipped, gives us the `integrations` group to attach API keys to.

**Scope decisions locked at kickoff (2026-05-27):**
- All 9 REST issues + #504 stay in scope (no carve-outs).
- #504 (extended-test-plan 5.2 doc drift) folded into v4.1.0 as a docs touch.
- **OpenAPI spec hosting:** admin-hidden Redoc viewer **AND** publish to the GitHub Pages docs site (operator decision — both surfaces).
- **Rate-limit storage:** WP transients (per `_swh_check_rate_limit()` precedent; honours the no-custom-tables invariant).

---

## Issue → PR map (at a glance)

| Phase | PR slug | Closes | Net new |
|---|---|---|---|
| 0 | (no PR — discovery only) | — | `docs/internal/v4.1.0-discovery.md` |
| 1 | `feat/v4.1-api-key-storage` | #365 (storage half) | `swh_api_keys` option + helpers + data-dictionary updates |
| 2 | `feat/v4.1-rest-bootstrap` | #366 | `swh_rest_require()` helper + namespace registration + error envelope |
| 3 | `feat/v4.1-tickets-endpoints` | #362 | `swh/v1/tickets` (list / single / create / update) |
| 4 | `feat/v4.1-replies-endpoints` | #363 | `swh/v1/tickets/{id}/replies` (list / create) |
| 5 | `feat/v4.1-ancillary-endpoints` | #364 | `users/technicians`, `categories`, `settings/public` |
| 6 | `feat/v4.1-api-key-ui` | #365 (UI half) | Settings → Integrations → API keys table + generate modal |
| 7 | `feat/v4.1-rate-limit` | #367 | Per-key rate limiting + `X-RateLimit-*` headers |
| 8 | `feat/v4.1-openapi` | #368 | `docs/openapi.yaml` + Redoc admin page + `swh/v1/openapi.json` + Pages site embed |
| 9 | `feat/v4.1-api-audit-log` | #369 | `_swh_api_access_log` post meta + meta box + setting toggle |
| 10 | `test/v4.1-playwright-rest` | #370 | Playwright sections 57–63 + shared helpers |
| 11 | `docs/v4.1-final-sweep` | #504 + release docs | All internal/end-user doc updates, CHANGELOG, version bump |
| 12 | (release branch) `release/v4.1.0` → `dev` → `main` → tag `v4.1.0` | — | Release bundle in `releases/v4.1.0/` |

**Branching:** Cut `release/v4.1.0` from `dev` at the start of Phase 1. Every feature branch above PRs into `release/v4.1.0`. See `planning-and-release-process.md` "Branching & PR methodology" for the full flow.

---

## Phase 0 — Documentation discovery

**Goal:** Confirm every API claim in this plan against actual code before writing any v4.1.0 code. **No code changes.**

### Tasks

1. Re-read end-to-end:
   - `docs/internal/planning-and-release-process.md` — the rails for this release.
   - `docs/internal/design-document.md` — invariant table.
   - `docs/internal/security-model.md` — auth + rate-limit precedents.
   - `docs/internal/api-contract.md` — current public surface.
   - `docs/internal/data-dictionary.md` — options/meta layout.
   - `docs/internal/contract-settings.md` — settings save flow.
   - `docs/internal/release_v4.x.x_roadmap.md` lines 105–125.
2. `gh issue view` each of **#362, #363, #364, #365, #366, #367, #368, #369, #370, #504** and snapshot bodies into `docs/internal/v4.1.0-issue-snapshots/` (one `.md` per issue). These are the source of truth — quote, do not paraphrase.
3. Grep & report counts (save to `docs/internal/v4.1.0-discovery.md`):
   - `register_rest_route\(` — current REST surface (where to register the new namespace).
   - `swh_check_rate_limit\(` — current rate-limit helper signature.
   - `do_action\(\s*['"]swh_ticket_` — verify lifecycle hooks landed in v3.7 actually exist.
   - `comment_type\s*=>?\s*['"]helpdesk_reply` — every reply-insert site (Phase 4 must use the same write path).
   - `password_hash\(`, `password_verify\(` — confirm they're used anywhere already; PHP 8.0 floor guarantees availability either way.
   - `hash_equals\(` — confirm precedent for constant-time comparison (it's invariant #3).
4. Confirm WP core / PHP APIs the plan assumes:
   - `WP_REST_Controller` and `WP_REST_Response` — WP 4.7+, safe (floor is 6.0).
   - `register_rest_route()` with `permission_callback` — same.
   - `password_hash( ..., PASSWORD_BCRYPT )` — PHP 8.0 floor OK.
   - `wp_generate_password( 32, false )` for raw key material — already in use.
5. Confirm the inbound-webhook secret precedent (`contract-inbound-webhook.md`) — we will mirror its bearer-token verification idiom for API keys.
6. Confirm settings storage layout for the `integrations` group (v4.0 #356) — that is where `swh_api_keys` belongs. Read `simple-wp-helpdesk/includes/Settings/Migration.php` to confirm the group exists and is writable.
7. Identify the existing settings tabs registration (`contract-settings.md`) — Phase 6 adds an "API keys" subsection inside the existing **Integrations** tab; do **not** create a new tab.
8. Confirm `make e2e-docker` Playwright section numbering — last shipped section is **75 (Integrations)** per the v4.0.3 plan. v4.1 sections start at **76** unless discovery proves otherwise. **Update the issue-#370 acceptance criteria if discovery disagrees with its "starting at 57" claim** (that number is stale; #370 was filed pre-v4.0).

### Deliverables

- `docs/internal/v4.1.0-issue-snapshots/*.md` (10 files).
- `docs/internal/v4.1.0-discovery.md` containing grep counts + a short "Allowed APIs" note + the resolved Playwright start number.
- A list of any plan assumptions discovery falsified, appended to this plan under a `## Phase 0 corrections` section. **If discovery contradicts the plan, the source wins — flag it and stop until the user re-approves.**

### Anti-patterns

- Do not start coding in this phase.
- Do not trust this plan's API claims without grep/Read verification.
- Do not begin Phase 1 until discovery is signed off by the user.

### Branch

None. All deliverables commit directly to a `chore/v4.1-discovery` branch into `release/v4.1.0` (cut from `dev` at the start of this phase).

---

## Phase 1 — API key storage layer (#365 — storage half)

**Why first:** Every REST endpoint needs a way to authenticate. Building the storage + verification helpers up front means Phase 2's `permission_callback` can lean on them.

**Branch:** `feat/v4.1-api-key-storage`

### Files

- **Create:** `simple-wp-helpdesk/includes/REST/ApiKeys.php` (namespace `SWH\REST\ApiKeys`).
- **Modify:** `simple-wp-helpdesk/includes/Settings/Migration.php` — register `swh_api_keys` default (`[]`) under the `integrations` group.
- **Modify:** `simple-wp-helpdesk/includes/helpers.php` — no changes; consumers use the new class directly.
- **Test:** `tests/Unit/REST/ApiKeysTest.php`.
- **Docs:** `docs/internal/data-dictionary.md` (new entry), `docs/internal/security-model.md` (auth flow), `docs/internal/contract-settings.md` (new option).

### Data shape

`swh_api_keys` — array of entries. Each entry:

```php
[
    'id'           => 'k_<uuid-v4>',           // stable identifier (URL-safe)
    'label'        => 'CI integration',         // operator-supplied display name
    'scope'        => 'read' | 'write',         // 'write' implies 'read'
    'prefix'       => 'swhk_a1b2c3d4',          // first 12 chars of plaintext, shown in UI
    'hash'         => '<password_hash output>', // bcrypt cost 12 of plaintext
    'created_at'   => 1717029600,               // unix
    'created_by'   => 1,                        // WP user id of issuer
    'last_used_at' => 0 | unix,                 // 0 = never used
    'rate_limit'   => null | int,               // null = use default
    'revoked_at'   => null | unix,
]
```

**Plaintext key format:** `swhk_<base62 32-char>`. Prefix stored = first 12 chars of plaintext. Never store plaintext anywhere.

### Public API (this phase)

```php
namespace SWH\REST;

final class ApiKeys {
    /** @return array{plaintext:string, entry:array} */
    public static function generate( string $label, string $scope, ?int $rate_limit = null ): array;

    /** @return array|null  The entry, or null if not found / revoked. */
    public static function find_by_plaintext( string $plaintext ): ?array;

    public static function revoke( string $id ): bool;

    public static function touch_last_used( string $id ): void;       // rate-limited internally to once per 5 min

    /** @return array<int,array> */
    public static function all(): array;
}
```

### Acceptance criteria

- `generate()` returns plaintext exactly once; subsequent reads expose only the prefix.
- `find_by_plaintext()` uses `password_verify()` and rejects revoked keys.
- All comparisons that take untrusted bytes use `hash_equals()` (invariant #3) — relevant when comparing prefixes during lookup short-circuit.
- `touch_last_used()` write-rate-limited to 5 min via a transient guard (no write amplification).
- PHPStan level 9 clean.
- PHPUnit covers: generate / verify / revoke / lookup-after-revoke / wrong-key / rate-limited touch.

### Doc updates (must land in this PR)

- `data-dictionary.md` — add `swh_api_keys` row under "Options".
- `security-model.md` — add "API key authentication" subsection: bcrypt hash, prefix-only display, scope enum, revocation semantics.
- `contract-settings.md` — note that `swh_api_keys` lives under the `integrations` group and is not surfaced by `swh_get_option( 'integrations', ... )` consumer-style (it's managed by the REST keys controller only).

### Anti-patterns

- Do **not** store plaintext.
- Do **not** roll your own crypto. Use `password_hash`/`password_verify` (PHP std), `wp_generate_password()` (WP std).
- Do **not** put the keys list anywhere reachable by `GET /settings/public` — Phase 5 enforces an allowlist that already excludes it; verify the allowlist contains no wildcards.

---

## Phase 2 — REST bootstrap + permission helper (#366)

**Why second:** Every later endpoint phase pulls in the helper. Land it once.

**Branch:** `feat/v4.1-rest-bootstrap`

### Files

- **Create:** `simple-wp-helpdesk/includes/REST/Bootstrap.php` — registers the `swh/v1` namespace on `rest_api_init`.
- **Create:** `simple-wp-helpdesk/includes/REST/Auth.php` — bearer parser + `swh_rest_require( string $scope ): callable` factory.
- **Create:** `simple-wp-helpdesk/includes/REST/Errors.php` — `Errors::respond( string $code, int $status, string $message ): WP_Error` for the unified envelope.
- **Modify:** `simple-wp-helpdesk/simple-wp-helpdesk.php` — `require_once` the three above; hook bootstrap on `plugins_loaded`.
- **Test:** `tests/Unit/REST/AuthTest.php`, `tests/Unit/REST/ErrorsTest.php`.
- **Docs:** `docs/internal/api-contract.md` (new "Public REST API v1" section header + auth schema), `docs/internal/security-model.md` (extend Phase 1's section with the per-route capability matrix).

### Error envelope (locked)

```json
{ "code": "swh_rest_<reason>", "message": "Human readable", "data": { "status": 401 } }
```

Error code enum (used across all phases):

| Code | Status | When |
|---|---|---|
| `swh_rest_auth_required` | 401 | No / malformed `Authorization` header on a protected route |
| `swh_rest_auth_invalid` | 401 | Bearer token doesn't resolve to a valid, non-revoked key |
| `swh_rest_scope_insufficient` | 403 | Valid key but scope insufficient for route |
| `swh_rest_capability_required` | 403 | Logged-in WP user lacks the capability |
| `swh_rest_rate_limited` | 429 | Rate limit exceeded (Phase 7 emits this) |
| `swh_rest_not_found` | 404 | Resource lookup failed |
| `swh_rest_validation_failed` | 400 | Schema / input validation failure |

### Capability matrix (from #366 — locked)

| Route group | Logged-in user | API key scope |
|---|---|---|
| `GET /tickets*`, `GET /replies*` | `edit_posts` | `read` |
| `POST /tickets`, `PATCH /tickets/*`, `POST /replies` | `edit_posts` | `write` |
| `GET /users/technicians`, `GET /categories` | `edit_posts` | `read` |
| `GET /settings/public` | **public** (no auth) | n/a |
| `GET /openapi.json` | **public** | n/a (Phase 8) |

### Acceptance criteria

- `swh_rest_require( 'read' )` accepts either: (a) a logged-in WP user passing the capability check, or (b) a valid `read`-or-higher API key. Same for `'write'`.
- Missing header → `swh_rest_auth_required` (not WP's default 401 page).
- Revoked key → `swh_rest_auth_invalid`.
- Scope mismatch (`read` key on a `write` route) → `swh_rest_scope_insufficient`.
- All comparisons use `hash_equals` / `password_verify` (invariant #3).
- PHPUnit covers every row of the matrix above × every error case.
- PHPStan level 9 clean.

### Doc updates

- `api-contract.md` — open the new section, document the namespace + auth + error envelope. **Do not** document any endpoints yet (Phases 3–5 do that).
- `security-model.md` — paste the capability matrix; reference the inbound-webhook precedent for bearer-token verification.

### Anti-patterns

- Do not register endpoints in this PR. The bootstrap file should only set up the namespace + helper.
- Do not log plaintext bearer tokens (server logs are out-of-band but the rule is no plaintext anywhere outside the in-memory request scope).

---

## Phase 3 — Tickets CRUD endpoints (#362)

**Branch:** `feat/v4.1-tickets-endpoints`

### Files

- **Create:** `simple-wp-helpdesk/includes/REST/TicketsController.php` — extends `WP_REST_Controller`.
- **Create:** `simple-wp-helpdesk/includes/REST/Schemas/Ticket.php` — single source of truth for the ticket shape (used by both the controller and Phase 8's OpenAPI).
- **Modify:** `simple-wp-helpdesk/includes/REST/Bootstrap.php` — register the controller on `rest_api_init`.
- **Test:** `tests/Unit/REST/TicketsControllerTest.php` (validation + capability), `tests/Integration/REST/TicketsCrudTest.php` (full CRUD via `WP_REST_Server` if integration harness exists; otherwise unit-level controller test with mocked save helpers).
- **Docs:** `docs/internal/api-contract.md` (endpoints), `docs/internal/contract-ticket-lifecycle.md` (note REST as a new entry point).

### Endpoints (locked, from #362)

| Method | Path | Cap | Scope | Returns |
|---|---|---|---|---|
| GET | `/swh/v1/tickets` | `edit_posts` | `read` | 200 + array, with `X-WP-Total` + `X-WP-TotalPages` headers |
| GET | `/swh/v1/tickets/{id}` | `edit_posts` | `read` | 200 ticket / 404 |
| POST | `/swh/v1/tickets` | `edit_posts` | `write` | 201 ticket |
| PATCH | `/swh/v1/tickets/{id}` | `edit_posts` | `write` | 200 ticket / 404 |

### List filters (locked)

`status[]`, `priority`, `assignee`, `category` (term id), `after` (ISO-8601), `before` (ISO-8601), `search`, `page` (default 1), `per_page` (default 20, max 100).

### Ticket response schema (locked — also the source of truth for Phase 8 OpenAPI `Ticket` component)

```
id              int
subject         string
status          enum(open, on-hold, in-progress, resolved, closed)
priority        enum(low, medium, high, urgent)
assignee        { id:int|null, display_name:string|null }
requester       { email:string, name:string|null }
category        { id:int|null, name:string|null, slug:string|null }
sla             { due_at:int|null, breached:bool }
csat            { score:int|null, comment:string|null }
created_at      int   (unix)
updated_at      int   (unix)
first_response_at int|null
links           { self:string, replies:string }
```

### Acceptance criteria

- **Writes route through existing handlers.** `POST` / `PATCH` MUST call the same helpers that `swh_save_ticket_data()` calls — no duplicated sanitization or email side-effect logic in the controller. Cite the helper(s) used in the PR description.
- **Status mutations** go through `swh_set_ticket_status()` only (invariant #1). The controller never calls `update_post_meta( $id, '_ticket_status', ... )` directly.
- 201 on create, 200 on update, 404 on missing.
- All routes use `swh_rest_require()` (Phase 2) — no inline `current_user_can` calls.
- PHPStan level 9 clean.
- PHPCS clean.
- PHPUnit covers: schema validation, capability errors, status enum rejection, filter combos, pagination headers.

### Doc updates

- `api-contract.md` — endpoint reference table + request/response examples.
- `contract-ticket-lifecycle.md` — add "REST API" to the list of entry points that mutate status (mirroring the existing portal/admin entries).

### Anti-patterns

- Do not duplicate save logic from `swh_save_ticket_data()`. The controller is a thin adapter.
- Do not invent new statuses. The enum is locked to the existing values.
- Do not leak internal fields (e.g. `_swh_unread`, `_swh_api_access_log`). Schema is allowlist, not denylist.

---

## Phase 4 — Replies endpoints (#363)

**Branch:** `feat/v4.1-replies-endpoints`

### Files

- **Create:** `simple-wp-helpdesk/includes/REST/RepliesController.php`.
- **Create:** `simple-wp-helpdesk/includes/REST/Schemas/Reply.php`.
- **Modify:** `simple-wp-helpdesk/includes/REST/Bootstrap.php` — register.
- **Test:** `tests/Unit/REST/RepliesControllerTest.php`.
- **Docs:** `api-contract.md`, `contract-ticket-lifecycle.md`, `contract-file-uploads.md` (REST attachment path).

### Endpoints

| Method | Path | Cap | Scope |
|---|---|---|---|
| GET | `/swh/v1/tickets/{id}/replies` | `edit_posts` | `read` |
| POST | `/swh/v1/tickets/{id}/replies` | `edit_posts` | `write` |

### Request shape (POST)

```
{ body: string, is_internal_note: bool, attachments: int[]  /* media IDs */ }
```

### Behaviour (locked from #363)

- Reply insert MUST use the same write path that the admin "Add Reply" form uses — find it during Phase 0 discovery and reuse. Inserts a comment with `comment_type = 'helpdesk_reply'`.
- `is_internal_note = true` requires `write` scope AND the requesting key must have `scope = 'write'`. Client-scope keys get `swh_rest_scope_insufficient` even on `is_internal_note = false` if Phase 2's `swh_rest_require( 'write' )` rejects them.
  - (Note: today there is no separate "client" scope — only `read` and `write`. Internal notes need `write`.)
- Internal notes are **excluded from GET responses returned to `read`-scope callers**. They are visible to `write`-scope callers. *(Restated for clarity vs. the issue body, which assumed a separate client scope.)*
- Body sanitized via `wp_kses_post` (invariant: no raw HTML, no script tags).
- Attachments validated through the existing upload validator (`contract-file-uploads.md`): MIME allowlist, size cap, must-be-under-uploads check.
- A successful staff reply fires `swh_ticket_replied` (the v3.7 hook) and triggers the existing email + `_swh_unread` updates — same as the admin UI.

### Acceptance criteria

- `swh_ticket_replied` fires on REST-created replies (PHPUnit assertion).
- Internal notes are not returned to `read`-scope keys.
- `body` is `wp_kses_post`-sanitized.
- Attachments fail validation cleanly (400 `swh_rest_validation_failed` with detail in `data.attachments`).
- PHPStan level 9 clean, PHPUnit covers happy + each rejection path.

### Doc updates

- `api-contract.md` — endpoint reference.
- `contract-ticket-lifecycle.md` — REST as a reply entry point.
- `contract-file-uploads.md` — note that REST attachment IDs go through the same validator.

### Anti-patterns

- Do not bypass `wp_kses_post`.
- Do not re-implement attachment validation.
- Do not emit `wp_mail()` directly (invariant #2 — `swh_send_email()` only). The existing reply handler already does this; reuse it.

---

## Phase 5 — Ancillary read-only endpoints (#364)

**Branch:** `feat/v4.1-ancillary-endpoints`

### Files

- **Create:** `simple-wp-helpdesk/includes/REST/UsersController.php`.
- **Create:** `simple-wp-helpdesk/includes/REST/CategoriesController.php`.
- **Create:** `simple-wp-helpdesk/includes/REST/SettingsController.php`.
- **Create:** `simple-wp-helpdesk/includes/REST/PublicSettingsAllowlist.php` — single source of truth for which settings are public-safe.
- **Modify:** `Bootstrap.php` — register the three controllers.
- **Test:** `tests/Unit/REST/UsersControllerTest.php`, `CategoriesControllerTest.php`, `SettingsControllerTest.php`, `PublicSettingsAllowlistTest.php`.
- **Docs:** `api-contract.md`, `security-model.md` (allowlist), `data-dictionary.md` (note allowlist contents).

### Endpoints

| Method | Path | Auth | Returns |
|---|---|---|---|
| GET | `/swh/v1/users/technicians` | `edit_posts` / `read` | `[{ id, display_name, email }]` (email omitted if `swh_hide_staff_email` filter true) |
| GET | `/swh/v1/categories` | `edit_posts` / `read` | `[{ id, name, slug, parent, count }]` |
| GET | `/swh/v1/settings/public` | **public** | allowlisted subset only |

### Public settings allowlist (locked)

```
priority_labels      => from swh_priority_labels option
status_labels        => from swh_status_labels option
sla_thresholds       => { low: int|null, medium: int|null, high: int|null, urgent: int|null } (hours)
features             => { csat: bool, portal: bool, attachments: bool }
```

**Hard rule:** the allowlist is an explicit array of (option key → response key). Adding a setting requires editing `PublicSettingsAllowlist.php` AND updating `security-model.md`. Wildcard exposure is forbidden.

### Acceptance criteria

- `settings/public` returns ONLY allowlisted keys. PHPUnit adds an arbitrary unrelated option and asserts it is absent from the response.
- Allowlist rejects any key whose option value would contain secrets (inbound webhook secret, SMTP creds, API keys, email template bodies). The test should assert each known-secret option is **not** in the allowlist.
- `users/technicians` honours `swh_hide_staff_email` filter.
- All endpoints emit the same error envelope on failure.

### Doc updates

- `api-contract.md` — endpoint reference + allowlist contents.
- `security-model.md` — "Public settings allowlist" subsection.
- `data-dictionary.md` — cross-reference allowlist for each listed option.

### Anti-patterns

- No wildcard / prefix-match exposure. Hard allowlist only.
- Do not return user roles, capabilities, login names, or any field not in the locked shape.
- Do not skip the Phase 7 rate limit for `settings/public`. It's the only public endpoint; rate-limit per IP (Phase 7 handles this).

---

## Phase 6 — API key management UI (#365 — UI half)

**Branch:** `feat/v4.1-api-key-ui`

### Files

- **Modify:** Settings → Integrations tab template (locate via `contract-settings.md` Phase 0 discovery). Add an **API Keys** section.
- **Create:** `simple-wp-helpdesk/assets/src/admin/api-keys.js` — handles the AJAX flow for generate / revoke + the "shown-once" modal.
- **Modify:** `simple-wp-helpdesk/assets/src/admin/index.js` — register the entrypoint.
- **Create:** `simple-wp-helpdesk/includes/Admin/ApiKeysAjax.php` — AJAX endpoints (`wp_ajax_swh_api_key_generate`, `wp_ajax_swh_api_key_revoke`) using `check_ajax_referer` + `current_user_can( 'manage_options' )`.
- **Modify:** `simple-wp-helpdesk/includes/Settings/Migration.php` — confirm `swh_api_keys` registered (carried from Phase 1).
- **Test:** `tests/Unit/Admin/ApiKeysAjaxTest.php`.
- **Docs:** `contract-settings.md`, `component-inventory.md` (new modal pattern), `accessibility.md` checklist.

### UI

- Table columns: **Label** · **Scope** · **Prefix** (`swhk_a1b2c3d4…`) · **Last used** · **Rate limit** · **Created** · **Actions** (Revoke).
- **Generate new key** form (above the table):
  - Label (required, max 60 chars).
  - Scope (radio: Read · Read+Write).
  - Rate limit override (optional, integer 1–10000, blank = default 60/min).
- **Generate flow:** server returns `{ plaintext, prefix }`. Modal shows plaintext with a Copy button + a clear "you will not see this again" warning. Closing the modal removes plaintext from the DOM.
- **Revoke flow:** confirm modal → AJAX → row updates to show "revoked at <date>". Revoked rows kept for audit; greyed out.

### Accessibility (`accessibility.md` checklist)

- Modal traps focus; ESC closes; ARIA `dialog` role + `aria-labelledby`.
- Plaintext input has `readonly` + `aria-label="Generated API key (copy now — you will not see this again)"`.
- Table is a real `<table>` with `<th scope="col">`. Revoke buttons have `aria-label` including the key label.

### i18n (`i18n.md` checklist)

- All operator-facing strings wrapped with `esc_html__( ..., 'simple-wp-helpdesk' )` (or `_e` / `_x` / `_n` as appropriate).
- New strings added to the `.pot` file in Phase 11's final sweep.

### UI/UX

- **Reach for `ui-ux-pro-max`** for the modal + the generate flow. The "shown-once" warning needs unambiguous visual hierarchy and the copy interaction needs to feel reliable. Pull the modal pattern into `component-inventory.md` after design lock.

### Acceptance criteria

- Plaintext key shown exactly once, never persisted server-side or client-side after modal close.
- Revoke is immediate — Phase 2's `find_by_plaintext` returns null for revoked keys.
- AJAX endpoints check nonce + `manage_options` capability.
- PHPCS + PHPStan clean.

### Doc updates

- `contract-settings.md` — API keys section.
- `component-inventory.md` — "Shown-once secret modal" pattern.
- `accessibility.md` — new entries on the per-component checklist.
- End-user `docs/integrations.md` (or new `docs/api-keys.md`) — operator-facing usage guide.

### Anti-patterns

- Do not store plaintext in `localStorage`, `sessionStorage`, or `window`.
- Do not echo plaintext in a server-rendered page; only the AJAX response (over admin-context HTTPS) returns it.
- Do not soft-delete by mutating the entry shape; revocation sets `revoked_at` only.

---

## Phase 7 — Per-key rate limiting (#367)

**Branch:** `feat/v4.1-rate-limit`

### Files

- **Create:** `simple-wp-helpdesk/includes/REST/RateLimiter.php` — `RateLimiter::check( string $bucket_key, int $limit ): array{ allowed:bool, remaining:int, reset:int }`.
- **Modify:** `simple-wp-helpdesk/includes/REST/Auth.php` — after successful auth, call rate-limiter with `swh_rl_api_<key_id>` and the per-key limit (or default 60).
- **Modify:** `simple-wp-helpdesk/includes/REST/Bootstrap.php` — for the public `settings/public` route, call rate-limiter with `swh_rl_ip_<sha256(ip)>` and limit 20.
- **Modify:** every controller — emit `X-RateLimit-Limit / -Remaining / -Reset` headers on every response (success AND 429).
- **Test:** `tests/Unit/REST/RateLimiterTest.php`.
- **Docs:** `api-contract.md`, `security-model.md`, `config-reference.md` (the 60/min default).

### Storage

WP transients (locked at kickoff):
- Key shape: `swh_rl_api_<key_id>` or `swh_rl_ip_<sha256(ip,salt)>`.
- Value: `[ 'count' => int, 'window_start' => unix ]`.
- TTL: 60 s rolling.
- Fallback to `wp_options` when persistent cache is absent (reuse the precedent in `_swh_check_rate_limit()`).
- Counters cleared by existing `_clear_rate_limits()` test helper; extend it to cover the new prefixes.

### Behaviour

- Within a 60-second window, increment counter; if > limit, return `swh_rest_rate_limited` 429 with `Retry-After: <seconds until window end>`.
- Always emit headers:
  - `X-RateLimit-Limit: <limit>`
  - `X-RateLimit-Remaining: max(0, limit - count)`
  - `X-RateLimit-Reset: <window_start + 60>` (unix ts)
- IP hash salt = `wp_salt( 'nonce' )` to ensure the hashed IP isn't reversible from logs.

### Acceptance criteria

- 61st request inside a rolling 60s window returns 429 (PHPUnit + Playwright loop).
- Reset header is monotonically non-decreasing within a window and increments by 60 at rollover.
- Per-key override in the key entry takes precedence over the 60 default.
- IP-bucket and key-bucket are independent (one IP exhausting `/settings/public` does not affect that IP's authenticated calls).

### Doc updates

- `api-contract.md` — rate-limit section: defaults, headers, override behavior.
- `security-model.md` — IP-bucket hashing approach (salt source).
- `config-reference.md` — note the 60/min default; per-key override exposed in UI.

### Anti-patterns

- Do not put raw IPs in transient keys (privacy + reversibility).
- Do not use the same bucket key for read and write paths of the same API key — the limit is on requests, not on actions.
- Do not skip emitting headers on the 429 response itself; integrators need them to back off.

---

## Phase 8 — OpenAPI 3.1 spec + Redoc viewer + public docs site (#368)

**Branch:** `feat/v4.1-openapi`

### Files

- **Create:** `docs/openapi.yaml` — OpenAPI 3.1 covering every `swh/v1` endpoint. Schemas reference `simple-wp-helpdesk/includes/REST/Schemas/*` semantically (manually kept in sync; CI step below enforces this).
- **Create:** `simple-wp-helpdesk/includes/REST/OpenApiController.php` — registers `GET /swh/v1/openapi.json` (public, rate-limited per Phase 7) that returns the spec JSON.
- **Create:** `simple-wp-helpdesk/includes/Admin/ApiReferencePage.php` — hidden admin page at **Tools → API Reference** (registered via `add_submenu_page` under the Tools menu, gated to `manage_options`).
- **Create:** `simple-wp-helpdesk/assets/vendor/redoc/redoc.standalone.js` — vendored Redoc bundle (pin to the latest stable; add a `dependencies.md` row with the pinned version + license + upgrade cadence note).
- **Modify:** `simple-wp-helpdesk/assets/src/admin/index.js` — entry for the Redoc page.
- **Create:** `docs/api-reference.md` (end-user / Pages site) — a thin page that embeds Redoc against `/openapi.yaml` (served by Pages from `docs/`).
- **Modify:** `docs/_config.yml` (or whichever Jekyll/Pages config is canonical — confirm in Phase 0) — add the api-reference page to the nav.
- **Modify:** `.github/workflows/<existing CI>.yml` — add a `swagger-cli validate docs/openapi.yaml` step (or `redocly lint` if preferred; pick one in discovery).
- **Test:** `tests/Unit/REST/OpenApiControllerTest.php` (JSON endpoint returns 200 + valid JSON), Playwright section 82 (or whatever number Phase 0 decides) for Redoc offline-load.
- **Docs:** `api-contract.md` (link to the OpenAPI surfaces), `dependencies.md` (Redoc), `parked_features.md` (only if anything from #368 gets parked — should not happen).

### Acceptance criteria

- `docs/openapi.yaml` validates against the OpenAPI 3.1 schema in CI on every push to a v4.1 branch.
- Every endpoint defined in Phases 3–5 has:
  - Schema `$ref`'d (not inlined).
  - Request / response examples.
  - All error codes listed under `responses:` (4xx + 429 + 5xx).
- Auth schemes documented: `bearerAuth` (custom API key) and `applicationPassword` (WP Application Password) per the issue's "Prefer WP Application Passwords for user-scoped access" note.
- Admin Redoc page loads with `window.navigator.onLine = false` — vendor bundle, no CDN.
- Public Pages site renders the same spec.
- Spec version (`info.version` in the YAML) bumps on every breaking API change. Document the rule in `api-contract.md`.

### Doc updates

- `api-contract.md` — "Machine-readable contract" section linking to admin page, JSON endpoint, and Pages site.
- `dependencies.md` — Redoc entry: version, license (MIT), upgrade cadence (every minor release), no-CDN rule.
- `planning-and-release-process.md` PR-time gates — add an "OpenAPI bumped?" row to the PR gate table for any future PR that changes a `swh/v1` route.

### Anti-patterns

- Do not load Redoc from a CDN. Vendor it.
- Do not split the spec across multiple files. One `openapi.yaml` keeps validation simple and the Pages embed trivial.
- Do not let the admin page expose any data the public site cannot; the admin page is just an authenticated wrapper around the same spec.

---

## Phase 9 — Opt-in API access audit log (#369)

**Branch:** `feat/v4.1-api-audit-log`

### Files

- **Modify:** Settings → Integrations tab — add **Enable API access log** checkbox (default off). Setting key: `swh_api_audit_log_enabled` under the `integrations` group.
- **Modify:** `simple-wp-helpdesk/includes/REST/TicketsController.php` and `RepliesController.php` — after a successful authenticated request whose route resolves to a single ticket id, append an entry to `_swh_api_access_log` post meta on that ticket, gated by the setting toggle.
- **Create:** `simple-wp-helpdesk/includes/Admin/ApiAccessLogMetaBox.php` — meta box registered on the `helpdesk_ticket` editor, collapsed by default, shown only when the toggle is on.
- **Modify:** `simple-wp-helpdesk/includes/Cron/Retention.php` (or wherever ticket-retention purge lives — confirm in Phase 0) — when purging a ticket, the meta is purged with it (post meta cascades automatically with `wp_delete_post`, but confirm).
- **Test:** `tests/Unit/REST/AuditLogTest.php`, `tests/Unit/Admin/ApiAccessLogMetaBoxTest.php`.
- **Docs:** `data-dictionary.md` (new meta), `config-reference.md` (new option), `contract-reporting.md` (note new meta box).

### Data shape

`_swh_api_access_log` — array, max 10 entries, FIFO:

```php
[
    'ts'        => 1717029600,
    'key_id'    => 'k_<uuid>',
    'key_label' => 'CI integration',
    'action'    => 'read' | 'write',
    'route'     => '/swh/v1/tickets/123',
]
```

### Acceptance criteria

- When toggle is off (default), no meta is written.
- When toggle is on, write occurs ≤ 5 ms after the response on average (PHPUnit micro-bench; if measurement is impractical in unit tests, capture in `performance-baseline.md` instead).
- Toggle off does NOT delete existing entries (idempotency).
- Max 10 entries enforced (FIFO trim on insert).
- No request body / query string in the entry. `route` is the path only.
- WP-Application-Password access (no API key) records `key_id = 'wp_application_password'`, `key_label = '<user login>'` so the log is still useful.

### Doc updates

- `data-dictionary.md` — meta entry.
- `config-reference.md` — toggle.
- `contract-reporting.md` — meta box description.

### Anti-patterns

- Do not log request bodies or query strings (privacy + size).
- Do not write to the log on failed-auth requests (no `key_id` to attribute, and it would be a logging-amplification vector for unauthenticated callers).
- Do not feature-creep into a full "audit trail" beyond the 10-entry per-ticket scope.

---

## Phase 10 — Playwright sections (#370)

**Branch:** `test/v4.1-playwright-rest`

### Files

- **Create:** `testing/e2e/sections/rest_auth.spec.ts` (and so on for each section).
- **Create:** `testing/e2e/helpers/api.ts` — `createApiKey()`, `apiRequest()`, `revokeAllTestKeys()`.
- **Modify:** `testing/e2e/playwright.config.ts` (if section registry exists), `testing/e2e/global-setup.ts` (if teardown is centralized).
- **Modify:** `docs/internal/testing-overview.md`, `docs/internal/testing-guide.md`, `docs/internal/test-coverage-matrix.md` (every new section listed).
- **Modify:** `docs/internal/extended-test-plan.md` — RC-level smoke for at least one REST happy-path.

### Sections (numbers TBD by Phase 0 — issue says 57–63 but that predates v4.0 numbering; verify in discovery, then renumber here)

| Section | Name | Covers |
|---|---|---|
| N | `rest_auth` | Bearer header parsing, 401 on missing/invalid key, WP Application Password auth |
| N+1 | `rest_tickets_crud` | GET list (filters, pagination), GET single, POST create, PATCH update |
| N+2 | `rest_replies` | List + create, internal-note scope, attachment validation |
| N+3 | `rest_ancillary` | technicians, categories, settings/public allowlist enforcement |
| N+4 | `rest_rate_limit` | 60/min default, 429 + headers, per-key override |
| N+5 | `rest_capability` | read-scope key on write route returns `swh_rest_scope_insufficient` |
| N+6 | `rest_openapi` | `/openapi.json` valid; admin Redoc page loads offline; Pages render check (smoke) |

### Helpers (locked shape)

```ts
export async function createApiKey(
    page: Page,
    scope: 'read' | 'write',
    label: string,
    rateLimit?: number
): Promise<{ id: string; plaintext: string }>;

export async function apiRequest(
    page: Page,
    method: 'GET' | 'POST' | 'PATCH',
    path: string,
    key: string,
    body?: unknown
): Promise<APIResponse>;

export async function revokeAllTestKeys( page: Page ): Promise<void>;
```

### Acceptance criteria

- All sections pass against the Docker stack.
- No flakes across 3 consecutive runs (`make e2e-docker` × 3).
- `revokeAllTestKeys()` runs in `globalTeardown` so the stack is left clean.
- Test-coverage-matrix.md updated; "every E2E test uses semantic selectors" exit criterion preserved.

### Anti-patterns

- Do not seed keys via direct option writes — use the AJAX UI flow (validates the UI alongside the API).
- Do not skip rate-limit teardown — leaving a transient with `count = 60` blocks the next run.

---

## Phase 11 — Final docs sweep + #504 + release prep

**Branch:** `docs/v4.1-final-sweep`

### Files / actions

1. **Close #504** — update `docs/internal/extended-test-plan.md` section 5.2 to drop the URL-hash persistence expectation (option 1 from the issue body). Note in the section that reload-snap-back to "All" is intentional per the 5.1 default-view contract.
2. **Touched-docs review** — for every PR in Phases 1–10, confirm its doc updates are present. Cross-check against the table in `planning-and-release-process.md` "PR-time gates".
3. **CHANGELOG.md** — add the v4.1.0 section grouped by `### Added` / `### Changed` / `### Security` / `### Fixed`. One bullet per closed issue. Reference issue numbers and PR numbers.
4. **Version bumps:**
   - `simple-wp-helpdesk/simple-wp-helpdesk.php` header `Version:` → `4.1.0`.
   - `readme.txt` `Stable tag:` → `4.1.0`.
   - `package.json` (if version-tracked there) → `4.1.0`.
5. **`CLAUDE.md`** — update the version line + any invariants if v4.1.0 introduced new ones (likely: "API keys are bcrypt-hashed and prefix-displayed only" if we want it on the hot list — operator decides during review).
6. **`README.md`** — update the feature list if v4.1.0 surfaces something the README didn't previously claim (REST API!).
7. **`SECURITY.md`** — note the new API-key surface + how to report a key-related vuln; reference the bcrypt-hashed + revocable model.
8. **`CONTRIBUTING.md`** — if the dev flow changed (e.g. new `make` target for `openapi-validate`), note it.
9. **End-user docs:**
   - New `docs/api-keys.md` (operator how-to).
   - New `docs/api-reference.md` (Redoc embed page, from Phase 8).
   - Update `docs/index.md` nav.
   - Update `docs/hooks.md` only if any new hooks were added (Phase 4 reuses the v3.7 `swh_ticket_replied` — nothing new).
10. **Roadmap maintenance:**
    - `docs/internal/release_v4.x.x_roadmap.md` — mark v4.1.0 issues closed, move v4.1.0 section to "Shipped" once tag lands (Phase 12).
    - `docs/internal/lessons-learned.md` — append the v4.1.0 retrospective once Phase 12 closes (the lessons themselves are gathered during implementation; the doc edit lives here).
11. **Release bundle** — `releases/v4.1.0/` populated by the existing build workflow on tag push (Phase 12). Confirm the workflow handles the new vendored Redoc bundle without exceeding the size budget (`js-architecture.md`).

### Acceptance criteria

- Every doc named in the per-phase "Doc updates" sections above is updated.
- CHANGELOG entry covers every closed issue.
- `make test-docker` + `make e2e-docker` green.
- Extended-test runbook (`extended-test-plan.md`) re-run against `release/v4.1.0` and signed off (per Gate 3 expectations).
- Manual visual regression suite (`visual-regression-suite.md`) re-run for any UI touched by Phases 6, 8, 9.

---

## Phase 12 — Release

**No new branch** — uses existing `release/v4.1.0`, `dev`, `main`.

### Steps

1. **Gate 2** (per `planning-and-release-process.md`): `release/v4.1.0` → `dev` PR.
   - All feature/step branches merged. ✅
   - Docs + test updates complete. ✅
   - Release bundle in `releases/v4.1.0/`. ✅
   - CI green. ✅
   - Functional review, documentation review, test-suite review. ✅
   - **Pause → ask user for approval.**
2. **Gate 3:** `dev` → `main` PR.
   - Extended Test Suite + Manual Visual Regression Suite green (these are gate-3 specific). ✅
   - CI green. ✅
   - **Pause → ask user for approval.**
3. **Release gate:** Tag `v4.1.0` on `main`, push tag.
   - **Pause → ask user for approval.**
4. Release workflow builds the bundle + publishes the GitHub Release.
5. Archive this plan: `git mv docs/internal/plan_v4.1.0.md docs/internal/archive/plan_v4.1.0.md`.
6. Move the v4.1.0 section of `release_v4.x.x_roadmap.md` to the "Shipped" block.
7. Final Memory MCP observation: `project:simple-wp-helpdesk:release:v4.1.0` entity, with tag, merge commit, bundle SHA256, and the lessons-learned cross-link.

### Approval discipline

Per `planning-and-release-process.md` "Approval scope": each of the three "Pause → ask user" steps above is its **own** approval. A "merge" approval for Gate 2 does **not** imply Gate 3; a Gate 3 approval does **not** imply tag-push. Ask again every time. (Codified after the v4.0.7 retrospective.)

---

## Open questions (resolved at kickoff or before Phase 1)

| # | Question | Status |
|---|---|---|
| 1 | Issue #504 — fold or push? | **Resolved 2026-05-27:** fold into v4.1.0 (Phase 11). |
| 2 | OpenAPI hosting — admin only or also Pages? | **Resolved 2026-05-27:** both (Phase 8). |
| 3 | Rate-limit storage backend? | **Resolved 2026-05-27:** WP transients (Phase 7). |
| 4 | Playwright section starting number — issue #370 says 57; v4.0.3 took us through 75. | **OPEN — resolve in Phase 0 discovery.** Plan currently uses placeholders (`N`, `N+1`, …) until then. |
| 5 | `is_internal_note` scope — issue #363 implies a separate "client" scope; current plan has only `read`/`write`. | **Resolved in plan (Phase 4):** stick to `read`/`write`. Internal notes need `write`; `read` callers don't see them in responses. Restate in the issue body before closing #363. |
| 6 | Should `find_by_plaintext` short-circuit on prefix? | **Plan answer (Phase 1):** yes — index by prefix first to avoid running `password_verify` against every entry. `password_verify` only runs on the prefix match. |
| 7 | Indigo design refresh (#355) — does it touch v4.1? | **Plan answer:** no. #355 stays on the roadmap's "Open decisions" list; the v4.1 UI work (Phase 6) uses the current theme. **Confirm with user before Phase 6 design lock.** |

---

## Phase 0 corrections

*(Empty at plan-write time. Populated by Phase 0 if discovery contradicts any plan assumption. If non-empty at Phase 1 start, re-pause for user approval before coding.)*

---

## Memory MCP discipline (every phase)

Per `planning-and-release-process.md` and global `~/.claude/CLAUDE.md`:

- Session start: `open_nodes(["user:sean"])` + `search_nodes("project:simple-wp-helpdesk")`.
- Before each phase: create or load `project:simple-wp-helpdesk:epic:v4.1.0` and the relevant `:bug:` / `:roadmap:` entities.
- After every meaningful change: `add_observations` with one-line fact, files touched, commit hash, test results.
- Phase close: final observation with PR number, merge commit, gate results.
- Release close: `project:simple-wp-helpdesk:release:v4.1.0` entity with tag + bundle SHA256.

---

## Test-suite coverage map (release-level summary)

| Suite | New / modified |
|---|---|
| PHPUnit (`make phpunit`) | Phases 1, 2, 3, 4, 5, 6, 7, 8, 9 each add unit tests (per file paths above). |
| Playwright (`make e2e-docker`) | Phase 10 ships sections 57+ (numbering confirmed in Phase 0). |
| Extended test runbook (`extended-test-plan.md`) | Phase 11 adds a REST happy-path smoke. Section 5.2 fixed (#504). |
| Manual visual regression (`visual-regression-suite.md`) | Phase 11 re-runs against Phases 6, 8, 9 UI surfaces. |
| Performance baseline (`make bench`) | Phase 9 — confirm the audit log adds < 5ms; update baseline numbers if it does. |

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| REST controllers duplicate save-logic from `swh_save_ticket_data()` | Phase 0 discovery snapshots the helper; Phase 3 acceptance criterion requires the helper be called; PR review verifies. |
| OpenAPI spec drifts from controller schemas | `Schemas/*.php` are the single source of truth; spec validation in CI; PR-gate row added in `planning-and-release-process.md`. |
| Plaintext key leaks into logs or storage | Phase 1 invariant + Phase 6 modal-close DOM cleanup + PR-review checklist on every key-related PR. |
| Rate-limit transient eviction under cache pressure | Fallback to `wp_options` already established by `_swh_check_rate_limit()`; Phase 7 reuses it. |
| Vendored Redoc bundle bloats the plugin | `js-architecture.md` size budget; Phase 8 confirms bundle is loaded only on the admin page, not on every admin pageview. |
| #504 fix accidentally re-enables URL-hash persistence | Phase 11 PR is docs-only; no code change. |

---

## Final self-review checklist (writer ran this before saving)

- [x] Every issue in the v4.1.0 milestone (#362–#370 + #504) maps to a phase.
- [x] Every phase names exact files to create / modify / test.
- [x] Every phase names doc updates (linked to `planning-and-release-process.md` PR-time gate table).
- [x] Every phase names test-suite updates (PHPUnit + Playwright as applicable).
- [x] No "TBD" / "TODO" / placeholder steps EXCEPT the explicit "Phase 0 resolves this" placeholders (Playwright section numbering), which are tracked in "Open questions" #4.
- [x] Invariants (#1 status mutation, #2 mail dispatch, #3 hash_equals, #4 portal URL ordering) referenced where relevant.
- [x] Approval discipline matches the post-v4.0.7 retrospective rule (per-gate approval, no chaining).
- [x] Memory MCP discipline section present.

---

## Execution handoff

Plan saved to `docs/internal/plan_v4.1.0.md`.

**Two execution options (per the `writing-plans` skill):**

1. **Subagent-Driven (recommended)** — fresh subagent per phase, two-stage review (`pr-review-toolkit:code-reviewer` + `coderabbit:code-reviewer`) between phases, fast iteration. Aligns with the project's "prefer subagent-driven development" rule in `planning-and-release-process.md`.
2. **Inline Execution** — execute phases in this session using `superpowers:executing-plans`, batch with checkpoints.

**Which approach?**
