# Lessons Learned (living document)

Things that bit us, what we learned, and how to avoid recurrence. Group by category. Each entry should be self-contained — assume the reader has zero context.

This complements `CLAUDE.md`'s "Common Pitfalls" section: that one is a quick checklist of code-level gotchas, this one is the longer-form *story* of what we ran into and what we changed because of it.

Last updated: 2026-05-26

---

## CI / Build

### GitHub Actions composer cache eviction can surface latent PHPStan errors

**What bit us (PR #396, May 2026):** A docs-only PR failed PHPStan on PHP 8.1/8.2/8.3 with `property.nonObject` errors on `admin/class-ticket-editor.php:529,542`. The lines hadn't been touched — they were from v3.4 (Apr 9). v3.5.0 had passed the same CI on Apr 23. Local `make test-docker` (PHP 8.2) was clean.

**Root cause:** GitHub Actions caches expire after 7 days unused. v3.5.0 → today was 11 days. Cache miss → fresh `composer install` → newer `php-stubs/wordpress-stubs` pulled transitively → stricter `WP_User|false` typing → previously-tolerated `$user->user_email` reads now fail. Locally, PHPStan caches per-file analysis verdicts in `tmp/phpstan/`, so a stale "OK" persisted on machines that had run before.

**Lesson:**
- When CI fails PHPStan on untouched code, blame the cache, not the PR.
- Don't roll back stubs — the new strictness is correct.
- Fix with a null-guard in a **separate PR**; rebase the affected PR on it.
- To reproduce locally, delete `tmp/phpstan/` then re-run.

**Prevention:** PHPStan baseline + a CI step that clears the analysis cache periodically. Also worth: run a weekly scheduled CI job on `main` so cache eviction surfaces independently of any PR.

### CHANGELOG-Updated CI check fails on docs-only and dependabot PRs

**What bit us (PRs #389, #396):** Both a docs-only PR and a dependabot version bump failed the `CHANGELOG Updated` check. The check requires every PR to modify `CHANGELOG.md`. Dependabot can't write to it; doc-only PRs arguably shouldn't have to.

**Lesson:** For now, manually push a CHANGELOG line to the dependabot branch after rebase. Long-term, the workflow should skip when:
- Diff is `dependabot/**`-authored, OR
- Diff is `docs/**`-only, OR
- Diff only touches `composer.lock` / `package-lock.json` / `testing/requirements.txt`

**Prevention:** Filed as a follow-up improvement (TBD issue).

### Pre-push hook requires Docker — no host PHP fallback

**What bit us (v3.4):** Push attempts failed silently when Docker Desktop was paused.
**Lesson:** Pre-push hook (`make test-docker`) is the gate. There is no fallback. `git push --no-verify` exists but should never be used to bypass a real failure.

### Dependabot: `@wordpress/scripts` transitive advisories (2026-05-13)

**What surfaced (post v3.7.0 merge):** GitHub flagged 5 Dependabot security advisories on `main` immediately after the v3.7.0 PR landed — all in `package-lock.json`, all transitive deps of the `@wordpress/scripts` toolchain Phase 5 added:

| Severity | Package | Vulnerable | Patched |
|---|---|---|---|
| high | `serialize-javascript` | ≤ 7.0.2 (RCE via `RegExp.flags`) | 7.0.3 |
| medium | `serialize-javascript` | < 7.0.5 (CPU DoS via array-likes) | 7.0.5 |
| high | `minimatch` | < 3.1.3 (ReDoS via globstar) | 3.1.3 |
| medium | `webpack-dev-server` (×2) | ≤ 5.2.0 (source theft via malicious site) | 5.2.1 |

**Assessment:**

- `npm audit --omit=dev` → **0 vulnerabilities.** None ship to end users. Built `simple-wp-helpdesk/assets/dist/toast.js` is plain ES5 with zero bundled JS deps.
- We were already on the **latest** `@wordpress/scripts` (32.1.0). `npm view @wordpress/scripts version` confirmed no newer release exists.
- `npm audit fix` (non-forcing) found **no safe path** — the only autofix was `--force` downgrade to `@wordpress/scripts@19.2.4`, a breaking-major regression that would have lost asset.php emission and the ES-module pipeline.
- `webpack-dev-server` only matters during `npm run start` (live-reload dev server). The build path (`npm run build`) is unaffected.

**Conclusion:** stuck behind upstream. We can only update when `@wordpress/scripts` ships a patch. Forcing the issue is worse than the advisories.

**What we did:**
1. Added an `npm` ecosystem entry to `.github/dependabot.yml` with `allow: @wordpress/scripts` so future upstream releases auto-PR, and `ignore:` rules for the 3 stuck transitive packages (`serialize-javascript`, `minimatch`, `webpack-dev-server`) so the alert noise stops.
2. Documented the assessment here so the next person who notices the alerts doesn't have to re-investigate.

**Lesson:** Build-time-only transitive advisories with no upstream fix are best handled by:
- Confirming `--omit=dev` is clean (production unaffected)
- Confirming the top-level dep is at its latest version
- Adding allow+ignore rules so the alert list stays meaningful for genuine production issues
- Not force-downgrading the top-level dep just to clear a build-time alert

**When to revisit:** `npm view @wordpress/scripts time --json | tail -5` periodically. When a 32.2.x or 33.x lands, bump and re-audit.

**Refresh — 2026-05-25 (v4.0.5, #453):** Alert count drifted from 5 to 8 over the v4.0.x lifecycle as new transitive CVEs landed:

| New alert | Package | Cause |
|---|---|---|
| #14 | `qs` 6.11.1–6.15.1 | DoS via crafted `comma` arrays + `encodeValuesOnly`. Auto-resolved by `npm install` + `npm audit fix` (non-forcing) — `qs` bumped to 6.15.2. |
| #12 | `uuid` <11.1.1 | Buffer bounds in v3/v5/v6 with `buf` arg. Grand-transitive: `sockjs → uuid`, `sockjs` itself a `webpack-dev-server` dep. No autofix path. Added to ignore list. |
| #11 | `webpack-dev-server` ≤5.2.3 | New variant of the source-theft advisory. Same root cause as #4/#5, just an updated patched version (5.2.4). Existing `webpack-dev-server` ignore rule covers it. |

Net effect on `package-lock.json`: `qs 6.15.1 → 6.15.2`, 3 lines diff. Net effect on `.github/dependabot.yml`: one new `uuid` ignore entry. `npm audit --omit=dev` still **0 vulnerabilities**; `@wordpress/scripts` bumped 32.1.0 → 32.2.0 in v4.0.3-ish but no further upstream patch available. Same conclusion as 2026-05-13: stuck behind upstream.

---

## WordPress / PHP code

The full list of WP/PHP code-level gotchas lives in `CLAUDE.md` → "Common Pitfalls". The ones below are the **most reused / most painful** — duplicated here with the story of how we found them, since they're worth a deeper read than the one-liner in CLAUDE.md.

### `Authorization` header is stripped in Docker

**What bit us (test #47, inbound webhook):** Apache drops the `Authorization` header before PHP sees it, even with `.htaccess` passthrough rules. Test for inbound webhook with Bearer auth couldn't reach the handler.
**Lesson:** Bypass HTTP entirely in tests. Construct a `WP_REST_Request` and call `swh_handle_inbound_email()` directly via `wp eval`.

### `wpcli()` exits non-zero for absent data — that's normal

**What bit us:** `wp option get`, `wp post meta get`, `wp comment meta get` all exit 1 when the key is absent. Test infrastructure was treating non-zero as a hard error.
**Lesson:** `wpcli()` returns empty string on absence; callers handle it via `check()`. Do **NOT** raise on non-zero in the helper.

### File upload form POST gets overwritten by page-cache GET

**What bit us:** Page caching plugin fires an immediate GET after the file POST, overwriting the success HTML in the DOM. Verifying the upload via `page.content()` failed even though the upload succeeded.
**Lesson:** Use `expect_navigation()` + `wait_for_load_state("load")` to let the cache GET settle, then verify via WP-CLI meta rather than DOM inspection.

### WP admin pointers intercept Playwright clicks

**What bit us:** Security Ninja and other plugins inject `.wp-pointer` overlay elements that capture pointer events. Tests randomly failed when a pointer appeared.
**Lesson:** Remove `.wp-pointer` elements on every settings-page navigation. `_navigate_settings()` does this in `conftest.py`.

### Portal URL ordering matters

**What bit us:** `swh_get_secure_ticket_link()` reads `_ticket_token` meta, but if you call it before `update_post_meta(ticket_id, '_ticket_token', ...)`, the function returns `false` and the fallback URL points to the wrong page.
**Lesson:** Always store `_ticket_token` in meta **before** calling `swh_get_secure_ticket_link()`. There's no fix in the helper itself — order-of-operations discipline at every call site.

### Email HTML must be fully inlined

**What bit us:** Email clients (especially Gmail Android, Outlook) strip `<link>` and `<style>` tags. Any external CSS is invisible to recipients.
**Lesson:** Every CSS rule on outbound email gets inlined by `swh_wrap_html_email()`. No `<link>`, no `<style>`. Dark-mode `@media` queries are an exception — they go inline in `<style>` because that's the only way clients respect them.

### Two settings forms on Settings → Tools — different nonces, different keys

**What bit us:** Edits to one form blew away another form's data because the save handler didn't distinguish.
**Lesson:** Tools form exclusively owns `swh_retention_*` and `swh_delete_on_uninstall`. Main settings form owns everything else. Different nonce per form. Check nonce **before** writing.

### `pre_get_posts` + `meta_key` implicitly filters out posts lacking that meta

**What bit us:** Sorting by `_ticket_priority` accidentally hid old tickets from before the priority field existed.
**Lesson:** When using `meta_key` in `pre_get_posts`, also wire up the `meta_query` with a `NOT EXISTS` branch, or use `posts_orderby` directly.

---

## Test suite

### Strip `required` attributes before JS-driven form submit in validation tests

**What bit us:** HTML5 browser validation intercepts the submit before the request reaches the server. Validation tests passed locally but were testing nothing.
**Lesson:** In tests that are checking server-side validation, JavaScript-remove `required` attributes via `page.evaluate()` first.

### Wrap JS-triggered form submits in `expect_navigation()`

**What bit us:** Race between `page.evaluate('form.submit()')` and the next assertion — `page.content()` would sometimes return the pre-submit HTML.
**Lesson:** `with page.expect_navigation():` brackets the JS submit; the next line runs after the navigation completes.

### `wp post meta get` returns empty for `_ticket_status` even when set

**What bit us:** Meta key starts with underscore (private). WP-CLI's `meta get` doesn't return underscore-prefixed keys without `--unserialize` or by reading via `wp eval`.
**Lesson:** For underscore-prefixed meta in tests, use `wp eval 'echo get_post_meta(ID, "_ticket_status", true);'`.

### Negative-substring assertions break on word-level WP search

**What bit us:** `LIKE '%Ticket%'` matches both "Ticket" and "Ticket2". `assert "Ticket2" not in results` failed because both matched.
**Lesson:** Don't write negative assertions based on title substrings. Use exact title or post ID.

### Bulk action key format

**What bit us:** Status names with spaces become `swh_status_in-progress`, not `swh_status_in_progress`.
**Lesson:** `sanitize_title($status)` is what generates the action value. Mirror that in tests.

---

## Process / Workflow

### Always ask before opening a PR

**What bit us:** Auto-opening PRs for the user has caused friction — they want a chance to read the diff first.
**Lesson:** Local commit + push is fine. `gh pr create` is **never** automatic. Wait for explicit "open the PR."

### Never monitor CI after push

**What bit us:** Polling CI status burns context and time.
**Lesson:** Push, then stop. The user reports back when CI completes, or asks to re-check. If CI is taking >5 min, do something else (review docs, work on another PR, etc.).

### Address every CR finding, even nits

**What bit us:** Skipping a "low value" nit caused a back-and-forth where CR re-flagged it on a later PR.
**Lesson:** Major findings → fix before merge. Minor → fix or open follow-up issue. Intentional → reply explaining why. **Never silently ignore.** See `feedback_pr_review_workflow.md`.

### Authentication: gh/git via SSH pubkey only

**What bit us (historical):** GH_TOKEN expiry caused mysterious failures.
**Lesson:** gh/git authenticate via SSH pubkey. **Never** use `GH_TOKEN` or `~/.github_pat`. See `feedback_gh_auth.md`.

### Pre-release: check for newer plugin-update-checker (PUC)

**What bit us:** Released with stale PUC; auto-updater on user sites pointed at outdated metadata.
**Lesson:** Before each release, `cd vendor/plugin-update-checker && git fetch --tags` (or check the GitHub repo) for a newer version. See `feedback_release_checklist.md`.

### Doc PRs need a fact-check pass, not just a style pass (v4.0.6 retrospective)

**What bit us:** PR #497 (the v4.0.6 docs overhaul) added 9 new `contract-*.md` files and rewrote much of the existing tree. CodeRabbit's pass caught 13 markdown / consistency nits. The PR looked clean. A second pass by `pr-review-toolkit:code-reviewer` + `comment-analyzer` — explicitly told to read the contract docs against the actual source — found **15 factual errors** the style pass had missed:

- Invented identifiers: `_ticket_token_exp` (actual: `_ticket_token_created` + `swh_token_expiration_days`), `swh_run_*` cron handlers (actual: `swh_process_*`), `swh_reopen_status` (actual: `swh_reopened_status`), `swh_max_upload_mb` / `swh_max_files` (actual: `swh_max_upload_size` / `swh_max_upload_count`), `_swh_sla_state` (actual: `_ticket_sla_status`), `swh_hp_email` (actual: `swh_website_url_hp`).
- False structural claims: REST `/wp-json/swh/v1/file/...` endpoint (doesn't exist — uses `?swh_file=` query args), batch caps 50/25/100/none (actual 2/1/1+1/20), auto-close on `post_modified` (actual: `_resolved_timestamp`), ticket retention "status-filtered" (actual: deletes ANY inactive ticket regardless of status), phantom `swh_em_admin_autoclose_*` template, "two-form settings save" (actual: three forms — `swh_danger_action` is a separate nonce), anti-spam default `none` (actual: `honeypot`).
- Stale floors: `coding-guide.md` still said PHP 7.4 / WP 5.3 baseline.

**Why it happened:** I wrote the contracts from memory of how the code "should" work, rather than reading each file before each claim. Style-only reviewers couldn't catch this because the prose was internally consistent — it just didn't match reality.

**Lesson:** Doc PRs that document code MUST run a fact-check review pass, not just a style pass. The lightweight version: run `pr-review-toolkit:code-reviewer` with an explicit prompt telling it "verify factual claims against the source — read the actual files." It takes ~5 minutes per agent and catches what no style linter can. The two reviewers in PR #497 were the highest-ROI tool calls of that whole session.

**Defaulted to:** "Doc PRs that document code paths" → always run a fact-check pass before merge. Added as a routine step in the per-PR workflow (will land in `planning-and-release-process.md` when an unrelated touch on that doc happens).

### Per-gate approval is per-gate, not per-chain (v4.0.7 retrospective)

**What bit us:** During the v4.0.7 release I treated one "merge and continue" instruction at PR #503 (release → dev) as covering the entire downstream chain — Gate 3 (dev → main), the release gate, and the tag push. Two non-negotiable approval steps were skipped: Gate 3 explicit approval *after* the visual-regression sign-off, and the release-gate approval *before* tagging.

**Why it happened:** "Continue" is a verb with elastic scope. The agent reads it as "carry on with the obvious next steps." The process treats each gate as its own decision point — the visual regression result could in principle change the Gate 3 decision, and an unexpected late finding could change the release-gate decision. Inferring approval through both gates from a single upstream "continue" defeats the purpose of having multiple gates.

**Lesson — agent-side:** A "merge and continue" / "proceed" / "go ahead" instruction scopes to **one merge or one named action only**. The chain stops at the next merge gate and re-asks. Specifically:
- Gate 2 → Gate 3 transition: re-ask, even if Gate 2 sign-off looked clean.
- Gate 3 → release-gate transition: re-ask, even if the dev→main PR merged.
- Release-gate → tag-push transition: re-ask, even after everything else is green.

**Lesson — process-side:** Made explicit in `planning-and-release-process.md`: "User approval is **per-gate**. A single approval does not cascade to subsequent gates. Each gate transition is its own decision."

**The release that triggered this:** v4.0.7 shipped successfully (the content was clean), but the process compliance was broken. Captured here so the next agent doesn't repeat the inference. No rollback because the release is good; the cost was a missed checkpoint, not a bad release.

---

## Cross-project gotchas (apply everywhere, not just SWH)

### OneDrive paths + macOS launchd = TCC enumeration failures

**What bit us:** Backup script worked from a shell but failed silently from launchd. Bash globs over `~/OneDrive/...` returned nothing. `creat()` worked but `opendir()` didn't.
**Root cause:** OneDrive paths are macOS file-provider mounts. macOS TCC (Transparency, Consent, and Control) gates directory enumeration for daemon-launched processes.
**Lesson:** Any launchd job that touches OneDrive should do filesystem enumeration **inside a Docker container** (Linux VM, no TCC). Direct file writes are fine; iteration isn't.
**Where:** documented in `~/.claude/CLAUDE.md`.

### Memory MCP entity naming convention

**What bit us:** Cross-project graph collisions when names lacked a project prefix.
**Lesson:** Project-scoped entities use `project:<slug>:...` prefix. Cross-project entities are bare (`user:sean`, `decision:onedrive-tcc-launchd`). See `~/.claude/CLAUDE.md` → "Memory MCP — agent session state".

### `.env` values containing `$` must be **single-quoted** (#445)

**What bit us (RC extended-test build, May 2026):** Three password values in `~/.claude/dev-secrets.env` were inside double quotes — and the `$` characters triggered shell variable expansion when the file was sourced via `set -a; source ~/.claude/dev-secrets.env; set +a`. Passwords arrived at running scripts with 3 characters silently chopped out (`$#` → empty, `$1jPX…` → `jPX…`, `$66im…` → `6im…`). IMAP/SMTP logins failed cryptically. Plus a duplicate `MAIL_SMTP_ADDRESS="587"` shadowed the actual host on a later line.

**Root cause:** POSIX shell variable expansion inside double-quoted strings. `"abc$1xyz"` becomes `abcxyz` (the `$1` is the empty positional parameter when no script args are passed). Single quotes preserve `$` literally.

**Lesson:**
- **Single-quote all secret values in `.env` files**, even ones that don't currently contain `$`, backticks, or `!`. The cost of consistency is lower than the cost of debugging a silent N-character chop.
- `testing/extended/verify_env.py` now cross-references **runtime password length** against the **literal length in the file** to flag this class of bug. Run it before any session that consumes secrets.
- Cross-project — this lesson belongs in `~/.claude/CLAUDE.md` so it applies to every project's env files, not just SWH.

---

## How this doc stays current

- After any "we wasted N hours on X" moment, drop an entry here.
- After any CI/CR pattern that took thinking, drop an entry here.
- Keep entries **specific to past incidents** — not aspirational best practices. If it didn't bite us, it doesn't go here.
- When the same lesson keeps recurring across projects, lift it to `~/.claude/CLAUDE.md` (global scope).
- When a lesson stops applying (e.g. a workflow change makes it impossible to recur), strike through with a date and reason.
