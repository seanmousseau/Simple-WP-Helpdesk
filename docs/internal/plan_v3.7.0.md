# v3.7.0 — v4 Foundations: Implementation Plan

**Milestone:** [#22](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/22) — 7 issues
**Theme:** Non-breaking groundwork that de-risks v4.0 / v4.1. Same PHP 7.4 / WP 5.3 minimums.
**Roadmap source:** `docs/internal/release_v3.x.x_roadmap.md` lines 42–79.

Each phase is self-contained — run it in a fresh chat with this file as context. Phases are ordered by dependency, not by issue number.

---

## Phase 0 — Documentation Discovery

**Goal:** Confirm the actual APIs and call sites each phase will touch, before any code is written. No code changes in this phase.

### Tasks for the executor

1. Re-read these in full:
   - `docs/internal/release_v3.x.x_roadmap.md` (lines 42–79: v3.7.0 section)
   - `docs/internal/release_v4.x.x_roadmap.md` (downstream consumers)
   - `CLAUDE.md` (project conventions, security, test gate)
   - `CHANGELOG.md` (recent format for v3.6.0 entry)
2. `gh issue view` each of: **#361, #390, #391, #392, #393, #394, #395** and save the bodies into `docs/internal/v3.7.0-issue-snapshots/` (one `.md` per issue). These are the source of truth for acceptance criteria — quote them, do not paraphrase.
3. Grep the codebase and report counts for:
   - `get_option(\s*['"]swh_options['"]` — every read site that Phase 1 will rewrite
   - `do_action\(\s*['"]swh_` — current action hook surface
   - `apply_filters\(\s*['"]swh_` — current filter hook surface
   - `require_once SWH_PLUGIN_DIR` — current bootstrap require list
4. Confirm WordPress core APIs that the plan assumes exist:
   - `apply_filters_deprecated()` and `do_action_deprecated()` — available since WP 4.6 (plugin min is 5.3, safe)
   - `wp_set_post_terms()`, `get_post_meta()`, `update_post_meta()` — already in use
5. List every `comment_type = 'helpdesk_reply'` insert site (Phase 2 wires `swh_ticket_replied` there).
6. Confirm `swh_sla_check_event` cron exists in `includes/class-cron.php` and find where the breach flag is set (Phase 2 fires `swh_sla_breached` there).
7. Confirm CSAT AJAX handler name (`wp_ajax_nopriv_swh_submit_csat`) and locate it (Phase 2 fires `swh_csat_submitted` there).

### Deliverables

- `docs/internal/v3.7.0-issue-snapshots/*.md` (7 files)
- A short "Allowed APIs" note appended to this plan file under each phase (executor adds it on first read of that phase)
- A grep-counts summary saved to `docs/internal/v3.7.0-discovery.md`

### Anti-patterns

- Do **not** start coding in this phase.
- Do **not** trust this plan's API claims without verifying with grep/Read.
- If an API claim here disagrees with the source, the source wins — flag it and stop.

### Branch

Create `release/v3.7.0` from latest `main`. All subsequent phases commit to this branch (or short-lived feature branches that merge into it).

---

## Phase 1 — `swh_get_option()` helper (#391)

**Why first:** Pure refactor. No behavior change. Every later phase reads options, so landing this first means later code is written in the new style.

### What to implement — copy, don't invent

**REVISED 2026-05-13 (Path B):** Phase 0 discovery established that **no monolithic `swh_options` bag exists** — every setting is a top-level option (`swh_spam_method`, `swh_assignment_rules`, etc., registered in `swh_get_defaults()` at `simple-wp-helpdesk/includes/helpers.php:19-121`). The original plan's helper body would brick the plugin (every setting → default). Path B keeps the de-risking goal: the **signature** captures group intent at call sites today; **only the body** changes in v4.0 #356 when the bag actually exists.

Add to `simple-wp-helpdesk/includes/helpers.php`:

```php
/**
 * Read a SWH setting.
 *
 * The $group argument is advisory in v3.7 — it captures the logical settings
 * group at each call site so v4.0 (#356) can change the helper body without
 * touching any caller. In v3.7 the body reads directly from top-level options.
 *
 * @param string $group   Logical group: 'general', 'email', 'portal',
 *                        'notifications', 'tools', 'routing', 'integrations'.
 *                        Ignored in v3.7; consumed by v4.0 schema split.
 * @param string $key     Option key WITHOUT the 'swh_' prefix
 *                        (e.g. 'assignment_rules' for the option 'swh_assignment_rules').
 * @param mixed  $default Returned when the option is absent.
 * @return mixed
 */
function swh_get_option( $group, $key, $default = null ) {
    return get_option( 'swh_' . $key, $default );
}
```

The signature **must** stay exactly this shape — v4.0 #356 changes only the body.

### Migration scope

Replace every read of the form `get_option('swh_options')[ 'foo' ]` (and the common `$opts = get_option('swh_options'); $opts['foo']` pattern) in `admin/`, `includes/`, `frontend/` with `swh_get_option( $group, 'foo', $default )`.

Use exactly these 7 group names (locked in 2026-05-13 — feed forward into v4.0 #356):

| Group | Contents |
|---|---|
| `general` | Site identity, default status/priority, page IDs (`swh_ticket_page_id`, etc.) |
| `email` | SMTP toggles, branding (`swh_email_logo_url`), templates, color scheme |
| `portal` | `swh_portal_theme`, lookup form toggles, CSAT settings |
| `notifications` | Admin/client/CC email enable flags |
| `tools` | Retention, delete-on-uninstall, SLA thresholds |
| `routing` | `swh_assignment_rules`, `swh_default_assignee` |
| `integrations` | `swh_inbound_secret` (and future outbound webhook config) |

### Migration scope (revised)

Phase 0 grep counted **30 top-level `get_option('swh_*')` reads** in `simple-wp-helpdesk/{admin,includes,frontend}/`. There is no `swh_options` bag — every setting is its own top-level option.

Replace each `get_option( 'swh_FOO', $default )` with `swh_get_option( $group, 'FOO', $default )`:
- `$key` = the option name with `swh_` prefix stripped
- `$group` = one of the 7 names above (use the 7-group table to pick)
- `$default` = whatever the current call passes (must match `swh_get_defaults()` value for that key)

Do **not** touch the *write* side (`update_option('swh_...', …)`). v4.0 #356 owns the schema split.

### Verification

- [ ] `grep -rn "get_option(\s*['\"]swh_" simple-wp-helpdesk/{admin,includes,frontend}/` returns only `update_option` lines and the helper definition itself (all read sites migrated)
- [ ] `make phpstan` (level 9) clean — helper has full PHPDoc types
- [ ] `make phpunit` — add 3 tests in `tests/Unit/`: default fallback, missing key, missing option entirely
- [ ] `make test-docker` green
- [ ] `make e2e` (or `make e2e-docker`) — full settings round-trip across all 8 settings tabs still saves and re-reads correctly

### Anti-patterns

- Do not add `wp_cache_get` / transient caching to the helper. v3.7 implementation must be a thin read-through wrapper, nothing more.
- Do not change the function signature once written. v4.0 builds on this exact shape.
- Do not migrate writes. Out of scope.

### Commit / CHANGELOG

`Added: swh_get_option() helper (preparing for v4.0 schema split).` under v3.7.0 in `CHANGELOG.md`.

---

## Phase 2 — Lifecycle action hooks (#361)

**Why second:** Load-bearing for every v4.1+ feature. Hooks are a one-way door once public — ship clean.

### Hooks to fire (verbatim from #361)

| Action | Where it fires | Args |
|---|---|---|
| `swh_ticket_replied` | comment insert in `includes/class-ticket.php` + portal reply handler in `frontend/class-portal.php` | `$ticket_id, $comment_id, $is_staff_reply` |
| `swh_ticket_status_changed` | `swh_save_ticket_data()` in `admin/class-ticket-editor.php` + portal close/reopen in `frontend/class-portal.php` | `$ticket_id, $old_status, $new_status` |
| `swh_ticket_assigned` | assignee change in save handler + `swh_apply_assignment_rules()` | `$ticket_id, $old_user_id, $new_user_id` |
| `swh_ticket_closed` | status transition to closed (in the status-change site, after the generic action) | `$ticket_id, $previous_status` |
| `swh_ticket_reopened` | status transition from closed → open/in-progress | `$ticket_id, $previous_status` |
| `swh_sla_breached` | `swh_sla_check_event` cron in `includes/class-cron.php`, only when breach flag is set | `$ticket_id, $minutes_over` |
| `swh_csat_submitted` | AJAX handler for `wp_ajax_nopriv_swh_submit_csat` | `$ticket_id, $rating` |

### Implementation rules

- Each action must fire **exactly once per event**. For status transitions, fire `swh_ticket_status_changed` *and then* the specialized `swh_ticket_closed` / `swh_ticket_reopened` only when the transition matches. Guard against infinite loops by detecting `$old === $new` and bailing.
- For `swh_ticket_assigned`: do not fire when going from unassigned (0) to unassigned (0). Do fire on 0 → user and user → 0.
- For `swh_sla_breached`: fire only the first time the breach flag transitions to `breach`. Use the existing `_ticket_sla_status` meta to detect the transition (was not `breach`, now `breach`).
- For `swh_ticket_replied`: **fire at all 11 `wp_insert_comment(comment_type='helpdesk_reply')` sites** (verified in Phase 0). This includes the 3 real reply sites (admin public reply at `admin/class-ticket-editor.php:573`, portal reply at `frontend/class-portal.php:199`, inbound email at `includes/class-email.php:319`) **and** the 8 system-generated breadcrumb comments (close/reopen/autoclose notes, merge breadcrumbs). `$is_staff_reply` is `true` when the comment's author is a logged-in user with `edit_post` cap on the ticket; `false` for system-generated comments where there's no human author. Integrators distinguish via the `$is_staff_reply` arg.

### Documentation

- New file: `docs/developer/hooks.md` — list every action with: signature, when it fires, an example listener that does something realistic (e.g. `swh_ticket_status_changed` → post to a Slack webhook).
- Update `docs/hooks-reference.md` if it exists; otherwise create it from the actions+filters grep produced in Phase 0.

### Tests

PHPUnit in `tests/Unit/Hooks/`:
- One test per action verifying it fires with the documented args. Use WP-Mock's `expectAction` or `wp_mock` action assertions.
- One test per action verifying it does **not** fire under the no-op condition (e.g. status save with no status change).

Playwright: extend an existing section (e.g. test_06 admin_update_ticket) with a `wp eval` that registers a temporary listener, runs the action, and reads back a transient set by the listener. Do not create a new test section just for this — the unit tests are the primary gate.

### Verification

- [ ] All 7 actions fire and are listed in `docs/developer/hooks.md`
- [ ] `make test-docker` green
- [ ] `make e2e` green
- [ ] `grep -rn "do_action\\(\s*['\"]swh_" includes/ admin/ frontend/` shows all 9 SWH actions (2 existing + 7 new)

### Anti-patterns

- Do not expose hooks via REST in this phase. Explicitly out of scope (issue #361 "Out of scope" section).
- Do not consume the hooks from inside the plugin (no internal listeners). They are pure outputs for integrators.
- Do not change existing `swh_pre_ticket_create` / `swh_ticket_created` signatures.

### CHANGELOG

`Added: 7 new ticket lifecycle action hooks for integrators. See docs/developer/hooks.md.`

---

## Phase 3 — Deprecation helper (#393)

**Why third:** Tiny, mechanical, and v4.0 (#360) deprecates pre-3.0 filters using exactly these helpers.

### What to implement — copy from issue #393

Create `includes/deprecations.php`:

```php
function swh_apply_deprecated_filter( $hook, $args, $version, $replacement = null, $message = '' ) {
    $msg = $message ?: sprintf( 'Use %s instead.', $replacement ?: 'the documented replacement' );
    return apply_filters_deprecated( $hook, $args, "SWH $version", $replacement, $msg );
}

function swh_do_deprecated_action( $hook, $args, $version, $replacement = null, $message = '' ) {
    $msg = $message ?: sprintf( 'Use %s instead.', $replacement ?: 'the documented replacement' );
    do_action_deprecated( $hook, $args, "SWH $version", $replacement, $msg );
}
```

Wire `require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';` into bootstrap (`simple-wp-helpdesk.php`) alongside the existing helper requires.

### Documentation

Create `docs/developer/deprecations.md`:
- Policy: minimum **2-minor-release window** before removal (e.g. deprecated in 3.7 → removed no earlier than 3.9 or 4.1, whichever ships first).
- Version-tag format: `SWH x.y` (e.g. `SWH 3.7`).
- Example usage snippet for both helpers.

### Tests

`tests/Unit/Deprecations/`:
- `swh_apply_deprecated_filter` fires the `deprecated_hook_run` action (WP core signal) and returns the filtered value unchanged when no listener registered.
- `swh_do_deprecated_action` fires `deprecated_hook_run` and does not return a value.
- Both helpers respect a custom `$message` when provided.

### Verification

- [ ] PHPStan level 9 clean (full PHPDoc types on both helpers)
- [ ] PHPUnit + Playwright + Semgrep + PHPCS all green via `make test-docker`
- [ ] **No existing hook is deprecated in this PR.** Verify: `grep -rn "swh_apply_deprecated_filter\|swh_do_deprecated_action" .` returns only the helper definitions and tests.

### Anti-patterns

- Do not deprecate any existing filters/actions yet. That's v4.0's job (#360).
- Do not change the helper signatures once written — v4.0's deprecations call sites depend on them.

### CHANGELOG

`Added: swh_apply_deprecated_filter() and swh_do_deprecated_action() helpers. See docs/developer/deprecations.md.`

---

## Phase 4 — PSR-4 autoload (#394)

**Why fourth:** Additive, low-risk, and unblocks v4.0/v4.1 from adding ~10 more `require_once` lines.

### What to implement — minimal viable migration

1. `composer.json`: add an `autoload` block:
   ```json
   "autoload": {
     "psr-4": { "SWH\\": "src/" }
   }
   ```
   Run `composer dump-autoload` and commit the regenerated `vendor/composer/autoload_*.php` files.
2. Bootstrap (`simple-wp-helpdesk.php`): require `vendor/autoload.php` early, *before* the existing `require_once` block. Existing requires stay — autoloader is additive, not a replacement.
3. **Proof-of-concept migration:** pick one class. The roadmap suggests `SWH\Email\Mailer`. Create `src/Email/Mailer.php` with namespace `SWH\Email` and class `Mailer`. Migrate the smallest cohesive piece of `includes/class-email.php` (or wrap the existing procedural functions in a thin OOP facade). Existing function calls (`swh_send_email`, etc.) must continue to work.
4. Update `docs/development.md` with the namespace + path convention (`SWH\Foo\Bar` → `src/Foo/Bar.php`).

### Verification

- [ ] `composer dump-autoload` produces no errors
- [ ] `make test-docker` and `make e2e` green — no regression in any v3.6 Playwright section
- [ ] `vendor/autoload.php` is required exactly once in bootstrap
- [ ] The PoC class is loadable via the autoloader: `php -r 'require "vendor/autoload.php"; var_dump(class_exists("SWH\\Email\\Mailer"));'` prints `bool(true)`
- [ ] PHPStan level 9 clean for `src/`

### Anti-patterns

- Do not migrate multiple classes in this PR. **One PoC class only.** Full migration happens organically across v4.x.
- Do not rename `SWH_Settings` / other public class symbols in v3.7 — that's a v4.0 break.
- Do not delete or modify any existing `require_once` line. Additive only.

### CHANGELOG

`Changed: Added PSR-4 autoload for plugin classes (additive — existing require_once calls still work).`

---

## Phase 5 — JS architecture decision + smallest consumer (#390)

**Why fifth:** Decision is independent of the PHP-side work, but lands a real refactor into one existing file. Doing this after PSR-4 means the JS bundling decision is the only build-system question outstanding.

### Tasks

**Decision (locked in 2026-05-13): Option B with `@wordpress/scripts`.**

Rationale: `@wordpress/components` ships `Modal`, `Notice`, `Button`, `ComboboxControl`, `Popover` — directly covers v4.0's modal/drawer/command-bar/tabs gaps without a second component library. `.asset.php` files auto-generate the `wp_enqueue_script` dependency array. WP 6.0 (the v4.0 minimum) already loads `wp-element` (React) for the block editor, so declaring it as an external dependency adds ~0 KB to the plugin bundle. Webpack rebuild speed (1–3s) is fine for this plugin's JS surface area.

1. Write `docs/internal/js-architecture.md`:
   - Decision: `@wordpress/scripts`, source in `assets/src/`, built to `assets/dist/`.
   - Rationale (the paragraph above).
   - Bundle-size budget: **≤40 KB gzip** for the admin entrypoint (excludes WP-shipped externals: `wp-element`, `wp-components`, `wp-i18n`, `wp-data`).
   - Component primitives to build on top of `@wordpress/components`: `Drawer`, `VirtualList`, `CommandBar` (the gaps WP doesn't provide).
   - Migration path: existing `assets/js/swh-admin.js` stays for now; new modules go to `assets/src/`. `swh-admin.js` migrates piece-by-piece in v4.x. v3.7 only refactors one consumer.

2. Add `@wordpress/scripts` to `package.json` devDependencies. Add `build`, `start`, `lint:js` scripts. Configure to emit `assets/dist/swh-admin.js` + `assets/dist/swh-admin.asset.php`.

3. Land **one** consumer to validate the pattern. Pick toast notifications: create `assets/src/toast/index.js`, import from `@wordpress/components` if it simplifies (`Notice`), update `class-settings.php` (or wherever toasts enqueue) to read deps from the generated `.asset.php`. Existing toast behavior must be visually identical — test_57 (toast_notifications) is the gate.

4. CI: add `npm ci && npm run build` to the Docker test setup so `assets/dist/` is built before E2E runs. Commit `assets/dist/` to the repo so end users installing from the WP.org ZIP don't need Node — `release.yml` should include `dist/` in the built ZIP.

5. Update `CONTRIBUTING.md` with the JS conventions section (`assets/src/` for sources, `npm run build` before commit, dist files checked in).

### Verification

- [ ] `docs/internal/js-architecture.md` exists with decision + rationale
- [ ] One JS consumer refactored
- [ ] Bundle-size measurement documented (use `gzip -c assets/js/swh-admin.js | wc -c` or the build tool's size output)
- [ ] No regression in any v3.6 Playwright section — specifically test_57 (toast_notifications) must pass
- [ ] `make e2e` green

### Anti-patterns

- Do not refactor more than one consumer. The point is to validate the pattern, not to do a full sweep.
- Do not add a build system without explicit user sign-off — adds significant CI surface area.
- Do not change any user-facing JS behavior.

### CHANGELOG

`Changed: JS module pattern documented and toast notifications migrated to it (foundation for v4.0 admin UI).`

---

## Phase 6 — Component inventory (#392)

**Why sixth:** Pure documentation. Best done after Phase 5 because the JS-pattern decision affects how new components will be described.

### Deliverable

`docs/internal/component-inventory.md` with two sections.

**Existing primitives** (each entry: class names, tokens consumed, a11y notes, example markup, file location):
- `.swh-empty-state` (+ `.swh-empty-state-icon`, `.swh-empty-state-title`, `.swh-empty-state-desc`) — also document the `swh_render_empty_state()` PHP helper (v3.6.0)
- `.swh-toast` (success/error/info variants)
- `.swh-badge` (+ `.swh-badge-{slug}`)
- `.swh-bubble` (note, user, tech variants)
- `.swh-panel-group` (+ `.swh-panel-group-label`)
- `.swh-skeleton` shimmer
- `.swh-helpdesk-wrapper` (+ `data-swh-theme` attribute)
- `.swh-skip-link` (v3.6)
- `.swh-danger-zone`
- `.swh-ticket-card-list`

**v4.0 gaps** (planned but not yet built — flag, do not implement):
- `.swh-modal`
- `.swh-drawer` (quick-reply)
- `.swh-virtual-list` (inbox)
- `.swh-command-bar` (Ctrl/Cmd+K palette)
- `.swh-tabs` (saved views)

Link to this file from `docs/internal/release_v4.x.x_roadmap.md`.

### Verification

- [ ] Every existing primitive has all 5 fields filled in
- [ ] Every gap entry has the v4.0 issue link (#349/#350/#351/#352)
- [ ] `release_v4.x.x_roadmap.md` links to the inventory

### Anti-patterns

- Do not implement any of the v4.0 gap components. This is docs-only.
- Do not invent token names — grep `assets/css/swh-shared.css` for the actual `--swh-*` tokens each component consumes.

### CHANGELOG

(No CHANGELOG entry — internal docs only.)

---

## Phase 7 — Performance baseline (#395)

**Why seventh:** Measurement-only, runs against a stable foundation, and gives v4.0 a regression target.

### Scenarios (verbatim from #395)

1. Admin ticket list page load — at 100, 500, 1000 tickets (TTFB + DOMContentLoaded)
2. Settings save round-trip — full `swh_options` write + redirect (TTFB)
3. `swh_sla_check_event` cron run — at 100, 500 open tickets (wall clock)
4. `swh_report_kpi_data()` cold + warm (transient miss vs hit)
5. Portal ticket view (admin token) — TTFB
6. Submission form POST — full POST → redirect to portal (TTFB)
7. REST inbound webhook — bench under `siege` at 10/30/60 RPS

### Implementation

1. `testing/scripts/seed_perf.php` — WP-CLI script that creates N tickets with realistic spread (varying statuses, ages, assignees). Parameterized: `wp eval-file testing/scripts/seed_perf.php --count=500`.
2. `testing/scripts/bench.sh` — drives the scenarios using `curl -w` format strings for server timings, plus Playwright performance traces for client timings.
3. Run on the Docker stack (`make e2e-docker` baseline environment) so numbers are reproducible.
4. Output to `docs/internal/performance-baseline.md`:
   - Date + commit SHA
   - Hardware/Docker version line
   - Table: scenario × (count, median, p95, p99)
5. Optional but recommended: `make bench` target in `Makefile`.

### Verification

- [ ] Seed script runs without errors at counts 100, 500, 1000
- [ ] Bench script produces a complete table for all 7 scenarios
- [ ] `docs/internal/performance-baseline.md` committed with numbers + SHA
- [ ] v4.0 release-process note: add a line to `CLAUDE.md` "Release Process" instructing v4.0 to re-run `make bench` and compare against this baseline. **Append, do not replace.**

### Anti-patterns

- Do not optimize anything based on the numbers. This is a baseline, not a perf-improvement PR.
- Do not run benchmarks against the SSH dev server — only against the local Docker stack (reproducibility).

### CHANGELOG

(No user-facing CHANGELOG entry — internal docs/tooling only. Note it under a "Development" sub-heading if desired.)

---

## Phase 8 — Release verification + PR

**Why last:** Standard release process per `CLAUDE.md` "Release Process" section.

### Tasks

1. Bump `Version:` header + `SWH_VERSION` constant in `simple-wp-helpdesk.php` to `3.7.0`.
2. Update `simple-wp-helpdesk/readme.txt` stable tag + changelog section.
3. Finalize `CHANGELOG.md` v3.7.0 entry. Sections: Added, Changed, Fixed (none expected), Internal. Consolidate the per-phase CHANGELOG lines.
4. Update `docs/internal/release_v3.x.x_roadmap.md`: mark v3.7.0 as `✅ SHIPPED <date>`, link the merged PR, check off each issue.
5. Run the full test gate **in this order**:
   - [ ] `make test-docker` — required local gate
   - [ ] `make e2e-docker` — full Playwright suite (62 sections + any new in Phase 2)
   - [ ] `make phpstan` — level 9
   - [ ] `make semgrep` — SAST clean
6. **Ask the user before opening the PR** (CLAUDE.md rule).
7. Open PR from `release/v3.7.0` → `main` with body that:
   - Lists the 7 closed issues with checkboxes
   - Links the v4.x consumers each phase unblocks
   - Includes the perf baseline summary table from Phase 7
8. Run `/review` (CodeRabbit) on the PR. Address all actionable findings.
9. After user approves, merge to `main`. Push tag `v3.7.0`. The `release.yml` workflow builds the ZIP and creates the GitHub Release.
10. **Do not monitor the CI run.** Wait for the user to report results.

### Verification

- [ ] Full test gate green
- [ ] CHANGELOG, readme.txt, version constants all match `3.7.0`
- [ ] All 7 milestone issues closed via the merged PR
- [ ] GitHub Release exists at tag `v3.7.0` with notes from CHANGELOG and the built ZIP attached

### Anti-patterns

- Do not skip hooks (`--no-verify`) if pre-push gate fails — investigate and fix root cause.
- Do not open the PR before user sign-off.
- Do not push the tag before the PR is merged to `main`.

---

## Cross-phase rules

- **Each phase is one PR** (or one tight series of commits on `release/v3.7.0`). Do not bundle phases.
- **Memory MCP:** at the start of each phase, `search_nodes("project:simple-wp-helpdesk")` and `open_nodes(["user:sean"])`. At the end of each phase, `add_observations` to `project:simple-wp-helpdesk:roadmap:v3.7.0` with: short commit hash, files touched, test results, the issue number closed.
- **Security:** every code change passes `make semgrep` and the semgrep MCP scan before commit. Apply `php-wordpress` skill conventions (nonces, capability checks, escaping).
- **Test-first where practical:** Phases 1–4 should write PHPUnit tests before or alongside implementation, per the project's TDD bias.
- **Anti-pattern across all phases:** inventing API methods, adding undocumented parameters, skipping verification. If the docs don't list it, it doesn't exist — find another way.

---

## Quick reference — phase order

| # | Issue | Title | Risk | Depends on |
|---|---|---|---|---|
| 0 | — | Discovery | none | nothing |
| 1 | #391 | `swh_get_option()` helper | low (pure refactor) | 0 |
| 2 | #361 | Lifecycle action hooks | medium (load-bearing) | 0 |
| 3 | #393 | Deprecation helper | low (additive) | 0 |
| 4 | #394 | PSR-4 autoload (PoC) | low (additive) | 0 |
| 5 | #390 | JS architecture (`@wordpress/scripts`) | medium | 0 |
| 6 | #392 | Component inventory | none (docs) | 5 |
| 7 | #395 | Performance baseline | none (measurement) | 1–4 |
| 8 | — | Release + PR | low | 1–7 |
