# v4.1.0 — RC Sign-off

**Branch tested:** `dev` @ `12083be` (Gate 2 merge of `release/v4.1.0`)
**Deployed:** plugin v4.1.0 on `$WP_TESTING_URL` (rsync from local `simple-wp-helpdesk/`; `wp plugin get` confirms `Version: 4.1.0`, `swh_db_version = 4.1.0`)
**Tested by:** Claude Opus via /godmode v4.1.0 release execution
**Date:** 2026-05-27

## Extended Test Suite

### Scripted prerequisites

| Section | Result | Notes |
|---|---|---|
| 0 — Env verify (`make test-extended-verify`) | ✅ PASS | 24 env vars, password integrity, SSH+docker+wp-cli, IMAP login, SMTP STARTTLS+AUTH |
| Pre-flight cleanup | ✅ PASS | No CLAUDE-TEST-* tickets to remove; no stale mailbox messages |

### Section 8.5 — REST API smoke (v4.1.0-critical)

| Step | Result | Notes |
|---|---|---|
| 8.5.1 — Generate write-scope key | ✅ PASS | `\SWH\Rest\ApiKeys::generate()` via wp-cli (UI flow not browser-driven in this run) |
| 8.5.2 — POST ticket via REST (HTTPS) | ✅ PASS | Created ticket 938 via curl + bearer; response matches `Schemas/Ticket` allowlist shape |
| 8.5.3 — GET list + pagination | ✅ PASS | `X-WP-Total: 3`, `X-WP-TotalPages: 1`, `X-RateLimit-Limit: 60`, `X-RateLimit-Remaining/-Reset` all present |
| 8.5.x — PATCH via curl (HTTPS) | ⚠ ENV BLOCKED | Apache 403 on PATCH methods — see Issue #2. WP-layer dispatch (`rest_do_request`) returns 200 with correct status change. |
| 8.5.4 — OpenAPI JSON endpoint | ✅ PASS | `info.version: 4.1.0`, 7 paths, 9 schemas, `bearerAuth` security scheme present |
| 8.5.4 — Admin Redoc viewer | ⏸ NOT VERIFIED | Browser-driven; deferred to visual regression run |
| 8.5.5 — Revoke key + post-revoke fail | ✅ PASS | Post-revoke bearer call returns `swh_rest_auth_invalid` (401) |
| Rate-limit (60/min default) | ❌ **FAIL** | **Real v4.1.0 bug — see Issue #1.** Counter decrements ~5 per request instead of 1; 429 hits at request 13 instead of 61. |
| Replies — internal note via REST | ✅ PASS | POST `/swh/v1/tickets/938/replies` with `is_internal_note: true` → 201, reply 618 created, flag persisted (WP-layer dispatch) |
| Audit log writes when toggle enabled | ✅ PASS | Toggle off (default) → no meta writes. Toggle on → 3 GETs produced 3 log entries with correct route + action + key_label |
| Allowlist drift (`settings/public`) | ✅ PASS | Response contains only 4 allowlisted keys. No secrets leaked. |

### Section 5.2 (#504 closure)

| Step | Result | Notes |
|---|---|---|
| Doc wording vs. behavior | ✅ PASS | Updated §5.2 correctly describes client-side filter + reload-snap-back to "All" per §5.1 default-view contract. Matches #504 fix option #1. |

### Sections 1–7 (standard) — NOT FULLY RUN

Marked **SKIPPED** this run. Rationale: scripted sections (verify_env, REST smoke, audit log) cover the v4.1.0 surfaces; the standard browser-driven sections would require driving Chrome on the dev server. The recent regression-fix PR #519 was verified to recover all 10 cliff tests via `make e2e-docker × 2` runs against this same code (covers sections 5.x and 6.x indirectly via the e2e suite).

**If full Gate 3 sign-off requires the browser-driven sections, those need a follow-up pass.**

## Manual Visual Regression

**NOT CAPTURED this run.** Defer to a follow-up browser-driven session, or accept the e2e suite (80/80 on PR #520 + 73/73 on PR #519) as the visual signal.

Surfaces that would need capture for full Gate 3:
- Settings → Integrations → API Keys (empty + active+revoked rows)
- Generate Key shown-once modal · Revoke confirmation modal
- Tools → Helpdesk API Reference (Redoc viewer)
- API Access meta box on ticket editor
- All standard surfaces, light + dark

## Issues found

### Issue #1 — Rate-limit counter increments ≈5× per request (HIGH — v4.1.0 regression)

**Symptom:** After 1 authenticated REST call: `X-RateLimit-Remaining: 59` (correct). After 6 calls: `X-RateLimit-Remaining: 29` — should be 54. After ~12 calls: 429. Default 60/min limit gives users effectively ~12 requests/min.

**Reproducible:** Yes. Fresh API key, sequence of `curl -H "Authorization: Bearer swhk_..." GET /swh/v1/tickets/938`. Remaining drops by ~5 per request.

**Suspect cause:** `SWH\Rest\RateLimiter::check()` invoked multiple times per request lifecycle. Likely candidates: `Auth::check_request()` (permission_callback), `ResponseDecorator::with_rate_limit_headers()`, and/or the `rest_pre_dispatch` / `rest_post_dispatch` filter pair.

**Severity:** HIGH — the headline feature ships with a 5× tighter limit than documented. Not a security issue (more restrictive than intended) but a real UX defect for the headline v4.1.0 feature.

**Fix scope estimate:** Small (1–2 files). Either dedupe `check()` calls per request, or make `check()` idempotent within a request via a request-scoped static cache.

**Files to inspect:**
- `simple-wp-helpdesk/src/Rest/Auth.php`
- `simple-wp-helpdesk/src/Rest/RateLimiter.php`
- `simple-wp-helpdesk/src/Rest/V1/ResponseDecorator.php`
- `simple-wp-helpdesk/src/Rest/Bootstrap.php`

### Issue #2 — Apache rejects PATCH method (ENVIRONMENTAL, not a v4.1.0 bug)

**Symptom:** `curl -X PATCH .../wp-json/swh/v1/tickets/938` returns Apache 403 Forbidden on both `dev.seanmousseau.com` and `dev-direct.seanmousseau.com`. Apache is the source of the 403 (Apache header in response body).

**Not affected:** `rest_do_request()` via wp-cli returns 200 with correct behavior. GET, POST work fine via curl.

**Cause:** Apache config on the dev server (ModSecurity rule, `LimitExcept`, or similar) restricting non-CRUD methods.

**Severity:** ENVIRONMENTAL. Not a v4.1.0 release blocker. Fix dev server Apache config separately.

### Issue #3 — Priority enum case sensitivity (LOW)

**Symptom:** POST `{"priority": "medium"}` → 400. `"Medium"` accepted. Validation against `swh_priority_labels` option is case-sensitive; OpenAPI spec doesn't document an enum.

**Recommendation (v4.1.1):** Either case-insensitive comparison + canonical-form storage, OR document the case sensitivity in OpenAPI via enum.

### Issue #4 — POST response `status` is empty string (LOW)

**Symptom:** POST without `status` returns `"status": ""` in the response. Subsequent GET reflects the actual stored status. Visual paper cut only.

**Recommendation (v4.1.1):** `Schemas\Ticket::from_post()` should read the stored status, not echo input.

## Gate 3 recommendation

**HOLD.** Fix Issue #1 (rate-limit double-count) before merging `dev → main`. The bug ships the headline feature in a degraded state. Fix scope is small; cost of shipping the bug to API-quality story is not.

**Alternative path:** SHIP v4.1.0 with Issue #1 documented as known and a v4.1.1 hotfix queued. Risk: operators tuning around the 60/min default discover the real limit is ~12 and lose confidence in the rate-limit defense.

**Strong recommendation: HOLD and fix.**

## Memory MCP

Final observation written on `project:simple-wp-helpdesk:release:v4.1.0` with the HOLD outcome + Issue #1 root cause notes for the follow-up.
