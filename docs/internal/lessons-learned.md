# Lessons Learned (living document)

Things that bit us, what we learned, and how to avoid recurrence. Group by category. Each entry should be self-contained — assume the reader has zero context.

This complements `CLAUDE.md`'s "Common Pitfalls" section: that one is a quick checklist of code-level gotchas, this one is the longer-form *story* of what we ran into and what we changed because of it.

Last updated: 2026-05-04

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

---

## How this doc stays current

- After any "we wasted N hours on X" moment, drop an entry here.
- After any CI/CR pattern that took thinking, drop an entry here.
- Keep entries **specific to past incidents** — not aspirational best practices. If it didn't bite us, it doesn't go here.
- When the same lesson keeps recurring across projects, lift it to `~/.claude/CLAUDE.md` (global scope).
- When a lesson stops applying (e.g. a workflow change makes it impossible to recur), strike through with a date and reason.
