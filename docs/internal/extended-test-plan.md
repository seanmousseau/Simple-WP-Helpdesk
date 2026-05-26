# Extended Test Plan — Release-Candidate Sign-Off Runbook

**Audience:** the person preparing a `dev → main` release-candidate PR.
**When to run:** **once per RC**, after all per-push CI gates are green.
**Where:** against the dev WordPress instance (URL in `$WP_TESTING_URL`).
**Expected duration:** 60–90 minutes.
**Output:** sign-off block at the bottom of this document, copy-pasted into the RC PR description.

## What this is and isn't

This is the **slow, human-paced sanity check** that runs once before promoting a feature branch to `main`. It exercises the surfaces that the Docker-based per-push CI cannot meaningfully cover:

- The real inbound-email webhook (SMTP→WP path, IMAP visibility, real MTA-induced timing)
- Real-WP deliverability through the actual SMTP queue
- Real role hierarchy (3 technician accounts) interacting under capability restrictions
- Visual and accessibility judgment on real markup at real screen sizes
- Multi-step operator flows (Cmd+K → drawer edit → email sent → client receives → client clicks portal link → CSAT → reports update)

**This is NOT a replacement for the per-push gates.** All of these stay in place:
`make test-docker`, `make e2e-docker`, PHPUnit suite (234 tests / 448 assertions today), `bin/matrix-lint.php`, invariant tests, REST envelope contract tests, PHPCS, PHPStan L9, Semgrep.

## How to use this document

1. Open a real RC PR titled `release/vX.Y.Z` (target branch `main`).
2. Source the secrets file and set the wp-cli bridge env vars:
   ```bash
   set -a; source ~/.claude/dev-secrets.env; set +a
   export SSH_TARGET=<user@host> WP_CONTAINER=<container> WP_PATH=<path>
   ```
3. **Run Section 0 first — `python3 testing/extended/verify_env.py`**. This catches dev-secrets.env issues (missing vars, password mangling, IMAP/SMTP login failures) before they derail later sections. Don't proceed until this is green.
4. Run pre-flight cleanup: `make test-extended-cleanup CONFIRM=1` (deletes any leftover `CLAUDE-TEST-*` tickets and stale test-mailbox messages).
5. Work through each numbered section below. Each step has three parts:
   - **Do** — the action to perform (with the exact command or click path).
   - **Expect** — what should happen.
   - **Verify** — how to confirm; often a CLI command whose output you paste into the PR.
6. As you go, tick the checkbox at the end of each section.
7. At the bottom, fill in the **Sign-off block** and paste it into the PR description.

Any failure during the runbook blocks the RC. File an issue with the section number and the actual-vs-expected output, then fix and re-run **only the failed section** (unless the fix touches other surfaces).

## Environment

| Resource | Where | Credentials |
|---|---|---|
| WordPress test instance | `$WP_TESTING_URL` | `$WP_TESTING_USER` / `$WP_TESTING_PASS` |
| Technician #1 | same | `$WP_TESTING_TECH_USER_1` / `$WP_TESTING_TECH_PASS_1` |
| Technician #2 | same | `$WP_TESTING_TECH_USER_2` / `$WP_TESTING_TECH_PASS_2` |
| Technician #3 | same | `$WP_TESTING_TECH_USER_3` / `$WP_TESTING_TECH_PASS_3` |
| Test mailbox (IMAP) | `$MAIL_IMAP_ADDRESS:$MAIL_IMAP_PORT` | `$MAIL_USERNAME` / `$MAIL_PASSWORD` |
| Test mailbox (SMTP) | `$MAIL_SMTP_ADDRESS:$MAIL_SMTP_PORT` | same |
| Webmail (visual confirmation) | `$MAIL_WEB` | same |
| Webhook inbound addresses | comma-separated list in `$WP_WEBHOOK_TIED_TO` | (no auth — wired to the plugin's inbound endpoint) |
| WP-CLI bridge (read/write) | `ssh $SSH_TARGET → docker exec $WP_CONTAINER → wp --path=$WP_PATH` | SSH key from operator's machine |

**Env file gotcha:** values containing `$` must be **single-quoted** in `dev-secrets.env`. Double quotes trigger shell variable expansion when the file is sourced — `$1xyz` becomes empty (or whatever `$1` is set to), silently mangling passwords. The verifier in section 0 cross-checks runtime length against the literal value-length in the file and will flag any mismatch.

All test tickets created by this runbook must use the prefix **`CLAUDE-TEST-`** in the title so the cleanup script can find them later. Scripts auto-append a UTC timestamp (`CLAUDE-TEST-WEBHOOK-YYYYMMDD-HHMMSS-<random>`) so two operators running concurrently don't collide and stale runs are visually distinguishable.

---

## Section 0 — Environment verification (≈1 min — **scripted**)

Always run this BEFORE any other section. Catches dev-secrets.env issues
that have historically derailed runbook starts (missing vars, password
mangled by shell expansion, IMAP/SMTP not reachable).

- **Do:**
  ```bash
  set -a; source ~/.claude/dev-secrets.env; set +a
  export SSH_TARGET=root@... WP_CONTAINER=... WP_PATH=...
  python3 testing/extended/verify_env.py
  ```
- **Expect:** `PASS — environment is ready for the runbook.` and a list of
  green ticks for each required check.
- **Verify:** Exit code 0. If anything is red, fix the dev-secrets file
  (typically: switch a double-quoted password to single quotes) and
  re-source before continuing.

- [ ] Section 0 complete.

---

## Section 1 — Authentication and access control (≈8 min)

### 1.1 — Admin login

- **Do:** Open `$WP_TESTING_URL/wp-login.php` and log in with `$WP_TESTING_USER` / `$WP_TESTING_PASS`.
- **Expect:** Land on the WP admin dashboard. No PHP notices in the page source. `Simple WP Helpdesk` appears in the left sidebar.
- **Verify:** View source, search for `xdebug` / `Notice:` / `Warning:` / `Fatal` — none should be present.

### 1.2 — Technician role exists with documented caps

- **Do:** Open `$WP_TESTING_URL/wp-admin/users.php?role=technician`.
- **Expect:** All three technician users (`$WP_TESTING_TECH_USER_1`, `$WP_TESTING_TECH_USER_2`, `$WP_TESTING_TECH_USER_3`) listed.
- **Verify:** Each row shows role `Technician` (not `Administrator`, not `Subscriber`).

### 1.3 — Subscriber blocked from helpdesk admin

- **Do:** Open a private window. Log in as any subscriber-level user (or create one with WP-CLI: `wp user create test-sub test-sub@example.com --role=subscriber --user_pass=x`). Navigate to `$WP_TESTING_URL/wp-admin/edit.php?post_type=helpdesk_ticket`.
- **Expect:** "Sorry, you are not allowed to access this page." OR redirect to dashboard with no Helpdesk menu visible.
- **Verify:** No ticket list rendered. No `swh-inbox-*` markup.

### 1.4 — Restrict-to-assigned permission gate

- **Do:** As admin, enable `Settings → Routing → Restrict technicians to assigned tickets`. Create three `CLAUDE-TEST-RTA-{1,2,3}` tickets via the public form. Assign #1 to tech1, #2 to tech2, leave #3 unassigned. In a private window, log in as tech1 and open the inbox.
- **Expect:** tech1 sees ONLY ticket #1. Tickets #2 and #3 are absent.
- **Verify:** Manually attempt to open ticket #2's edit URL (`/wp-admin/post.php?post=NNN&action=edit`) directly. Expect HTTP 403 — "You are not assigned to this ticket."
- **Cleanup at end:** disable the setting again so other sections work normally.

- [ ] Section 1 complete.

---

## Section 2 — Inbound-email webhook (≈10 min — **scripted**)

This is the most-critical-and-most-likely-to-break surface. We have a dedicated script.

### 2.1 — Pre-flight: secret is configured

- **Do:** Confirm `Settings → Integrations → Inbound Webhook Secret` is populated. Note its value (mask in any pasted output).
- **Expect:** Non-empty value.
- **Verify:** `wp option get swh_settings_integrations --format=json | jq '.inbound_secret | length'` — should be > 20.

### 2.2 — Round-trip: real SMTP send → WP comment

- **Do:** Run `python3 testing/extended/webhook_roundtrip.py`. The script:
  1. Creates a ticket via WP REST tagged `CLAUDE-TEST-WEBHOOK-<timestamp>`.
  2. Extracts the ticket's `_ticket_uid` (`TKT-NNNN`).
  3. SMTP-sends an email FROM `$MAIL_USERNAME` TO the first address in `$WP_WEBHOOK_TIED_TO` with `Re: [TKT-NNNN] roundtrip` in the subject.
  4. Polls WP REST every 2 seconds (max 60 seconds) for a new helpdesk_reply comment on the ticket.
  5. Reports `PASS` or `FAIL` with the comment ID and body, or a diagnostic if it timed out.
- **Expect:** `PASS` with comment ID > 0 and body containing "roundtrip".
- **Verify:** Paste the script's full output into the PR.

### 2.3 — Round-trip: second inbound address

- **Do:** Same as 2.2 but with `--inbound-address <second entry from $WP_WEBHOOK_TIED_TO>`. (Inspect the env var, pick the second comma-separated address.)
- **Expect:** `PASS`. Every inbound address listed in `$WP_WEBHOOK_TIED_TO` must route to the same webhook.
- **Verify:** Paste output.

### 2.4 — Sender mismatch is rejected

- **Do:** `python3 testing/extended/webhook_roundtrip.py --expect-rejection sender_mismatch`. The script auto-uses `$WP_TESTING_CLIENT_EMAIL_2` as the sender (a different authorized alias from the same SMTP account, since the relay rejects MAIL FROM addresses it doesn't own).
- **Expect:** `PASS — webhook correctly rejected sender_mismatch`.
- **Verify:** Paste output.

> **SMTP relay constraint (#444):** The `--sender-override` option cannot use an arbitrary external address (e.g. `nobody@example.invalid`). Credentialed SMTP relays — including cowmail.ca and most providers — reject such addresses at the MAIL FROM stage with `5.7.1 Sender address rejected: not owned by user`, so the script can't even deliver the message to test WP's rejection path. Use a different alias of the **same authenticated account** (e.g. `$WP_TESTING_CLIENT_EMAIL_2`) so the relay accepts the envelope and WP's `hash_equals` check on `_ticket_email` becomes the rejection point.

### 2.5 — No `[TKT-XXXX]` in subject is rejected

- **Do:** `python3 testing/extended/webhook_roundtrip.py --subject-override "Plain subject no tag" --expect-rejection no_ticket_id`. **The `--subject-override` is required for this test** — without it the script falls back to `Re: [TKT-NNNN] roundtrip` and the webhook (correctly) accepts the message. Unlike `ticket_not_found` (which auto-crafts a `[TKT-999999]` subject), `no_ticket_id` requires the caller to supply a subject that genuinely lacks the tag.
- **Expect:** `PASS — webhook correctly rejected no_ticket_id (no comment appeared within 60s).`
- **Verify:** Paste output.

### 2.6 — Subject references a non-existent ticket UID

- **Do:** `python3 testing/extended/webhook_roundtrip.py --expect-rejection ticket_not_found`. The script crafts a subject like `Re: [TKT-999999] …` referencing a UID that doesn't exist.
- **Expect:** `PASS — webhook correctly rejected ticket_not_found`.
- **Verify:** Paste output.

### 2.7 — Auth-layer rejections (bypassing SMTP)

SMTP can't exercise the bearer-auth code paths because by the time SMTP completes, the message is already inbound. These tests POST directly to the webhook with wrong/missing auth:

- **Do:**
  ```bash
  # Wrong bearer token
  python3 testing/extended/webhook_direct_post.py --post-id <any-real-ticket-id> \
      --bearer "wrong-secret" --expect-status 401
  # Missing Authorization header
  python3 testing/extended/webhook_direct_post.py --post-id <any-real-ticket-id> \
      --no-auth-header --expect-status 401
  ```
- **Expect:** Both return `PASS` with HTTP 401 and `code: "swh_unauthorized"`.
- **Verify:** Paste output.

- [ ] Section 2 complete.

---

## Section 3 — Outbound email deliverability (≈8 min — **scripted**)

### 3.1 — Admin reply lands in client mailbox

- **Do:**
  1. Create `CLAUDE-TEST-OUT-1` ticket as client (use `$WP_TESTING_CLIENT_EMAIL_1` as the requester address). Capture the `_ticket_uid`.
  2. As admin, open the ticket in the drawer and post a reply containing the marker string `OUTBOUND-DELIVERY-CHECK-<timestamp>`.
  3. Run `python3 testing/extended/email_delivery_check.py --to "$WP_TESTING_CLIENT_EMAIL_1" --subject-contains "TKT-" --body-contains "OUTBOUND-DELIVERY-CHECK-<timestamp>" --timeout 60`.
- **Expect:** `PASS` — message arrived in the client address's mailbox within 60s with the expected ticket UID in the subject and marker in the body.
- **Verify:** Paste output.

### 3.2 — Mark-resolved email differs from generic reply email

- **Do:** Continuing from 3.1's ticket: post a second reply with the "Mark resolved" checkbox checked, marker `RESOLVED-CHECK-<timestamp>`. Wait, then check the inbox again.
- **Expect:** Email subject reflects the `em_user_resolved` template (typically contains "resolved"), not the plain reply template. Body contains the marker.
- **Verify:** `python3 testing/extended/email_delivery_check.py --to "$WP_TESTING_CLIENT_EMAIL_1" --subject-contains "resolved" --body-contains "RESOLVED-CHECK-<timestamp>" --timeout 60` returns `PASS`.

### 3.3 — Assignment email fires for the new assignee

- **Do:** Create `CLAUDE-TEST-OUT-3` ticket. In the drawer, set the assignee to tech1.
- **Expect:** Tech #1's email address (`$WP_TESTING_TECH_EMAIL_1`) receives an "assigned" email within 60s.
- **Verify:** `python3 testing/extended/email_delivery_check.py --to "$WP_TESTING_TECH_EMAIL_1" --subject-contains "assigned" --timeout 60`.

- [ ] Section 3 complete.

---

## Section 4 — Portal flow (client-facing) (≈10 min)

### 4.1 — Token link opens portal

- **Do:** From the email received in 3.1, click the "View ticket" link (in a private browser window — must work unauthenticated).
- **Expect:** Portal v2 page loads. Shows the ticket subject, status, and the full reply thread (including the admin reply from 3.1, no internal notes).
- **Verify:** Inspect the URL — must contain `swh_ticket=` and `token=` query params. Page title is the ticket subject.

### 4.2 — Client reply via portal

- **Do:** In the portal, post a reply with marker `PORTAL-REPLY-<timestamp>`.
- **Expect:** Reply appears in the thread immediately (no full page reload, or reload completes within 2s). Status shown remains the same (or moves to "Open" if it was "Pending").
- **Verify:** Admin inbox shows the ticket with the new client reply highlighted as unread. The reply marker is visible in the activity log.

### 4.3 — Client close → reopen does not get rate-limited

- **Do:** From the portal, click "Close ticket" (or equivalent). Immediately click "Reopen ticket".
- **Expect:** Both actions succeed within ~3s of each other. **No "please wait" / rate-limit error.** (If you see one, gap #9 has regressed — file a P0.)
- **Verify:** Ticket status returns to Open in the admin inbox.

### 4.4 — CSAT prompt after close

- **Do:** From the portal, close the ticket. The page should display a 1–5 satisfaction prompt.
- **Expect:** Prompt is visible, keyboard-focusable. Submit a rating of 4.
- **Verify:** As admin, navigate to `Reports`. Ticket's CSAT shows as 4.

### 4.4a — CSAT can be disabled (v4.0.3 #471)

- **Do:** As admin, go to Settings → Portal → uncheck "Show CSAT widget". Save. Reload the page; verify the checkbox is unchecked. As a client, close a fresh ticket via the portal.
- **Expect:** No 1–5 star widget renders. Only the close-success message appears.
- **Verify:** View page source — search for `swh-csat`. Must yield zero matches.
- **Restore:** Re-enable the toggle so subsequent runs/tests keep the default behaviour.

### 4.4b — Custom CSAT prompt text persists (v4.0.3 #471)

- **Do:** At Settings → Portal, change the "Prompt text" field to a unique marker like `RC-CSAT-PROMPT-<timestamp>`. Save. Close a fresh ticket as a client.
- **Expect:** The widget heading shows the custom marker exactly.
- **Restore:** Reset to default via the "Reset to default" link on the field.

### 4.5 — Internal note is NOT visible in portal

- **Do:** As admin, add an internal note containing the marker `INTERNAL-LEAK-CHECK-<timestamp>`. Refresh the portal page in the client's window.
- **Expect:** Internal note is **absent** from the client's portal view. The reply thread does not contain the marker.
- **Verify:** View page source in the portal window. Search for the marker — must yield zero matches.

- [ ] Section 4 complete.

---

## Section 5 — Admin inbox surfaces (≈10 min)

### 5.1 — Default view per role

- **Do:** As admin, navigate to the inbox. As tech1 in a private window, do the same.
- **Expect:** Both default to the documented "All" view (NOT "Unassigned" — that v4.0.0 default regression must not return).
- **Verify:** Read the active view label at the top of the left rail.

### 5.2 — Filters apply via URL hash and persist

- **Do:** Click `Status: Open` filter. Observe URL change. Reload the page.
- **Expect:** Filter persists after reload. URL hash contains `status=open` (or equivalent).
- **Verify:** URL bar inspection. Filter chip remains active.

### 5.3 — Saved view CRUD

- **Do:** Click `+ Add view`, name it `CLAUDE-TEST-VIEW`, save. Switch to it. Switch back to All. Delete it via the ⋯ menu.
- **Expect:** View appears under "My views" after save. Switching activates it. Deletion removes it and the confirmation dialog appears.
- **Verify:** After delete, `My views` no longer shows the row. (If `+ Add view` is hidden because v4.1 features are still deferred, mark this step as N/A and note in the sign-off.)

### 5.4 — Bulk actions

- **Do:** Select 2 tickets via row checkboxes. Bulk bar should appear at the bottom. Click "Close".
- **Expect:** Both tickets transition to Closed. Bulk bar disappears. Inbox refreshes to reflect new statuses.
- **Verify:** Confirm via `wp post meta get <id> _ticket_status` for both.

### 5.5 — Cmd+K palette

- **Do:** Press `Cmd+K` (or `Ctrl+K` on Linux). Type a ticket UID's numeric suffix (e.g. `42`).
- **Expect:** Palette suggests matching tickets within 200ms. Hitting Enter opens that ticket's drawer.
- **Verify:** Drawer opens with correct ticket subject.

### 5.6 — Drawer field edits + optimistic version

- **Do:** In the drawer, change the priority and the status. Save.
- **Expect:** Both changes persist on reload. No 409 conflict (you are the only editor).
- **Verify:** Refresh, confirm new values.

- [ ] Section 5 complete.

---

## Section 6 — Settings (≈10 min)

For each settings tab (General, Assignment & Routing, Portal, Email Templates, Messages, Anti-Spam, Integrations, Canned Responses, Templates, Tools):

### 6.x.1 — Tab loads without PHP notices

- **Do:** Navigate to the tab.
- **Expect:** Page renders. No PHP notices/warnings in page source.

### 6.x.2 — One field saves and persists across reload

- **Do:** Change a single field on the tab (e.g. on General, edit "Default priority"; on Email, edit "Email Header Logo URL"; on Portal, edit "Thank-you message"; on Integrations, edit "Webhook secret"). Save.
- **Expect:** Toast/admin notice confirms save. Reload the page.
- **Verify:** Field value persists.

Tick each tab below:

- [ ] General
- [ ] Assignment & Routing
- [ ] Portal (v4.0.3)
- [ ] Email Templates
- [ ] Messages
- [ ] Anti-Spam
- [ ] Integrations (v4.0.3)
- [ ] Canned Responses
- [ ] Templates
- [ ] Tools

### 6.x.3 — Reply-by-Email webhook (v4.0.3 relocation)

- **Do:** Confirm the Reply-by-Email webhook URL + secret fields are on the **Integrations** tab (moved from Email Templates in v4.0.3).
- **Expect:** Both fields visible under "Reply-by-Email Webhook" fieldset. The webhook URL is non-editable code text. The secret is a `type=password` field with autocomplete off.
- **Verify:** The Email Templates tab no longer shows the webhook URL or secret.

### 6.8 — `wp option get swh_settings_<group>` reflects writes

- **Do:** Pick one tab you just edited. Run `wp option get swh_settings_<group> --format=json`.
- **Expect:** The changed key shows the new value.

### 6.9 — Grouped + flat mirror consistency (v4.0.0 + v4.0.3)

- **Do:** Edit a setting in any non-General tab via the UI (e.g. Portal → "Skip link label" to a unique marker). Save.
- **Expect:** Both `wp option get swh_settings_<group>` AND `wp option get swh_<key>` return the new value — `swh_set_option()` mirrors writes to the legacy flat option for back-compat. Reads in `swh_get_string_option()` rely on this mirror.
- **Why this matters:** If the mirror is broken, runtime reads (e.g., the inbound webhook auth) would silently use stale data even though the UI shows the new value.

- [ ] Section 6 complete.

---

## Section 7 — Reports + dark mode (≈10 min — visual)

### 7.1 — Reports KPIs render against real ticket history

- **Do:** Navigate to Reports.
- **Expect:** All KPI tiles render with numeric values (not "—" or "Loading…" stuck). Charts paint within 3 seconds.
- **Verify:** Eye check + screenshot for the sign-off block.

### 7.2 — Range selector reactively filters

- **Do:** Click `7d`, then `30d`, then `90d`. Observe KPI tiles.
- **Expect:** Tile values change between ranges (unless the test data is genuinely identical). Active button is visually distinct.
- **Verify:** Eye check.

### 7.3 — Prior-period comparison delta chip

- **Do:** Enable "Compare to prior period" if not already on.
- **Expect:** Delta chip appears next to each KPI showing a percentage change.
- **Verify:** Chip is visible AND its color reflects the direction of change (red for worsening metrics, green for improving).

### 7.4 — Dark mode visual sanity (**partly scripted**)

- **Do:** Run `python3 testing/extended/capture_screenshots.py`. The script logs in as admin, toggles `Settings → General → Admin theme` through each of `light` / `dark` / `auto`, and captures the inbox, settings, and reports pages at each setting. PNGs land in `testing/extended/screenshots/<timestamp>/`.
- **Expect:** Three screenshots per page × three themes = 9 files, no failures reported.
- **Verify:** Open the 9 PNGs and eye-check: no unreadable text, no broken contrast (light-on-light or dark-on-dark), no leftover light-mode borders/backgrounds in dark mode. Attach the dark and auto shots to the PR.
- **Manual supplement:** Also open the portal in a private (non-admin) window. Confirm `Portal → Theme = Auto` respects your OS-level preference. (The capture script is admin-only.)

- [ ] Section 7 complete.

---

## Section 8 — Accessibility spot-check (≈5 min)

### 8.1 — Keyboard nav through the inbox

- **Do:** Tab through the inbox from the URL bar focus. Use only the keyboard.
- **Expect:** Visible focus ring on every interactive element. Skip-to-content link works if present. Drawer opens via Enter on a row.
- **Verify:** No focus traps. No "invisible" focus states.

### 8.2 — Screen-reader landmark sanity

- **Do:** Inspect the inbox in DevTools accessibility tree (Chrome DevTools → Accessibility → enable full tree).
- **Expect:** A single `main` landmark, named region for the left rail, name for the drawer when open.
- **Verify:** Tree structure looks like the documented layout in `design-document.md`.

- [ ] Section 8 complete.

---

## Section 9 — Post-run cleanup (≈2 min)

- **Do:** Run `python3 testing/extended/cleanup.py --confirm`.
- **Expect:** Script reports how many `CLAUDE-TEST-` tickets it deleted and how many test mailbox messages it expunged.
- **Verify:** Inbox no longer shows any `CLAUDE-TEST-` titles. The test mailbox's INBOX no longer holds the round-trip test messages.

- [ ] Section 9 complete.

---

## Sign-off block (paste into RC PR description)

```
Extended Test Plan — RC Sign-Off
================================
Date          : YYYY-MM-DD
Operator      : <github-username>
Plugin version: <X.Y.Z>
WP version    : <site's WP version at run time>
Branch        : <release/vX.Y.Z>
Commit        : <git rev-parse HEAD>

Section results:
[ ]  1. Auth and access control
[ ]  2. Inbound-email webhook (scripts: webhook_roundtrip.py)
[ ]  3. Outbound email deliverability (scripts: email_delivery_check.py)
[ ]  4. Portal flow
[ ]  5. Admin inbox surfaces
[ ]  6. Settings (each of 7 tabs)
[ ]  7. Reports + dark mode visual
[ ]  8. Accessibility spot-check
[ ]  9. Post-run cleanup

Findings:
- <none / list any failures, partial passes, deferred items>

Screenshots attached:
- light-mode-inbox.png
- dark-mode-inbox.png
- auto-mode-inbox.png
- reports-kpis.png
- <any failure shots>

Approval: I, <github-username>, have completed every section above
and confirm the RC is shippable to main.
```

---

## What's NOT in this runbook (and why)

- **Cron jobs** — automated tests cover the early-return guards and lock semantics. Manual verification of a 24-hour autoclose window or a 7-day retention sweep isn't practical inside a 90-minute runbook. If you suspect a cron regression, file a separate issue and probe with `wp cron event run swh_autoclose_event`.
- **Migration** — settings migration from v3 → v4 is unit-tested (`SettingsMigrationTest`). The runbook doesn't repeat the migration on a fresh install because the dev WP is long past v4.0.
- **Multisite** — the dev WP is single-site. Multisite verification needs its own throwaway environment when we get to v4.2+.
- **Browser matrix** — Chromium-only here. Firefox/Safari is owned by per-push CI (which today only runs Chromium too — that's a separate gap, not this runbook's problem).
- **i18n / RTL** — out of scope until the plugin actually ships a translation; mentioned for completeness.

## Maintenance

Every PR that adds a user-visible feature must also update this runbook. The PR template (per the matrix migration plan, PR 6) will include a checkbox: "If this PR adds a feature, the RC runbook has a step for it." A red cell in the test-coverage matrix's L4 column whose feature is not also covered by a runbook step is a process bug — fix the runbook before merging.
