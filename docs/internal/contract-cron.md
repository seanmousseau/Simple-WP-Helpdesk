# Contract: Scheduled Work (Cron)

Every recurring job the plugin runs, what it does, what it touches, and how it's tested. Cron is invisible — this doc is how you avoid breaking it accidentally.

> Touch this contract before adding, removing, or changing the offset of a scheduled job.

---

## Job inventory

All four jobs are registered in `swh_activate()` (`simple-wp-helpdesk/includes/class-installer.php`) and cleared in `swh_deactivate()`. All run **hourly**, offset by 30 minutes from each other so they don't all fire in the same tick.

| Hook | First-fire offset | Handler | Purpose |
|---|---|---|---|
| `swh_autoclose_event` | `time()` (immediate) | `swh_run_autoclose()` | Closes `Resolved` tickets after N days of inactivity |
| `swh_retention_tickets_event` | `time() + 1800` (+30 min) | `swh_run_retention_tickets()` | Deletes tickets inactive for N days |
| `swh_retention_attachments_event` | `time() + 3600` (+60 min) | `swh_run_retention_attachments()` | Deletes physical files older than N days |
| `swh_sla_check_event` | `time() + 5400` (+90 min) | `swh_run_sla_check()` | Flags tickets exceeding SLA warn / breach thresholds |

The staggered offsets are deliberate. Don't change them without recomputing collisions.

---

## Auto-close — `swh_autoclose_event`

**Trigger:** hourly cron.

**Inputs:**
- `swh_autoclose_days` setting (0 = disabled).
- `swh_resolved_status` / `swh_closed_status` settings.

**Behavior:**
1. If `swh_autoclose_days <= 0`, exit.
2. Query `helpdesk_ticket` posts with `_ticket_status = swh_resolved_status` and `post_modified <` (now − N days).
3. For each match: `swh_set_ticket_status( $ticket_id, $closed_status )`.
4. Send admin auto-close notification via `swh_send_email( …, 'swh_em_admin_autoclose_*', … )`.
5. Send client auto-close notification.

**Batching:** capped at 50 tickets per tick to avoid timeouts on large sites. Remaining tickets caught on the next hour.

**Hooks fired:** `swh_ticket_status_changed`, `swh_ticket_closed` (via `swh_set_ticket_status`).

**Failure modes:**
- Disabled (`days = 0`) → no-op.
- Status label mismatch (operator renamed but settings out of sync) → no tickets match; logs nothing.
- `wp_query` returns 0 → no-op.

---

## Ticket retention — `swh_retention_tickets_event`

**Trigger:** hourly cron, +30 min from auto-close.

**Inputs:**
- `swh_retention_tickets_days` setting (0 = disabled).

**Behavior:**
1. If `days <= 0`, exit.
2. Query tickets where `post_modified <` (now − N days) AND status not in `[swh_default_status, in-progress, …]` — i.e. anything that has progressed.
3. For each match: delete ticket + all `helpdesk_reply` comments + all attached files (`swh_delete_ticket_files`).
4. Bypass trash (force delete).

**Batching:** 25 tickets per tick. Lower than auto-close because each deletion involves several filesystem ops.

**Hooks fired:** standard WP `deleted_post`, `delete_attachment`. No SWH-specific delete hook today (potential roadmap item).

**Safety:**
- Attachment paths validated via `realpath()` against the protected upload dir before delete.
- Never deletes anything outside `wp-content/uploads/swh-helpdesk/`.

---

## Attachment retention — `swh_retention_attachments_event`

**Trigger:** hourly cron, +60 min from auto-close.

**Inputs:**
- `swh_retention_attachments_days` setting (0 = disabled).

**Behavior:**
1. If `days <= 0`, exit.
2. Find attachments under `wp-content/uploads/swh-helpdesk/` older than N days.
3. Delete the **file only** — the attachment post stays so the conversation history reads "[file deleted]" rather than disappearing.
4. Update the attachment post's `_wp_attached_file` meta to flag deletion (or remove the meta, depending on implementation).

**Batching:** 100 files per tick.

**Safety:** every delete path goes through `realpath()` check.

**Difference from ticket retention:** this deletes binaries only; ticket retention deletes the whole ticket. Operators can run both with different thresholds (e.g. wipe binaries after 30 days, wipe tickets after 365).

---

## SLA check — `swh_sla_check_event`

**Trigger:** hourly cron, +90 min from auto-close.

**Inputs:**
- `swh_sla_warn_hours` setting (0 = disabled).
- `swh_sla_breach_hours` setting (0 = disabled).
- `swh_sla_open_statuses` filter (default: every status except resolved / closed).

**Behavior:**
1. If both warn and breach are 0, exit.
2. Query open tickets (status in `swh_sla_open_statuses`).
3. For each ticket, compute age = now − `post_date`.
4. Compare age against thresholds; update `_swh_sla_state` meta:
   - `none` (under warn threshold)
   - `warn` (between warn and breach)
   - `breach` (above breach threshold)
5. On first transition into `breach`, fire `swh_sla_breached( $ticket_id, $minutes_over )`. Subsequent ticks for the same ticket do not re-fire.
6. Aggregate a digest of breached tickets and send to the fallback alert address via `swh_send_email( …, 'swh_em_sla_breach_*', … )`.

**Batching:** none today — the SLA check is computationally cheap (a meta read + diff per ticket). May need batching if open-ticket counts grow beyond ~10k.

**Hooks fired:** `swh_sla_breached` (first transition only).

**UI side effects:**
- Status badge classes (`.swh-badge-sla-warn`, `.swh-badge-sla-breach`) rendered based on `_swh_sla_state` meta.
- Polite ARIA-live announcement on the badge when it transitions.

---

## Invariants

1. **All four hooks registered on activation, cleared on deactivation.** No orphan jobs after plugin removal.
2. **Offsets are staggered.** 0 / +30 / +60 / +90 min. Don't change without recomputing.
3. **Each job is idempotent.** Re-firing the same hook for the same ticket in the same tick must produce the same end state.
4. **Each job is bounded.** Batch caps exist on every job that touches multiple records.
5. **All filesystem deletions are `realpath`-validated.**
6. **Status mutations always use `swh_set_ticket_status()`.** Direct `update_post_meta` would skip lifecycle hooks.
7. **All four jobs respect their "0 = disabled" semantic.** Setting any of `swh_autoclose_days`, `swh_retention_*_days`, `swh_sla_*_hours` to 0 fully disables that job's effects (the cron still ticks; it just no-ops).

---

## Testing

### Unit (PHPUnit)

- Each handler has a unit test that:
  - Verifies the disabled-setting code path (no-op).
  - Verifies the happy path on a fixture-built ticket.
  - Verifies the batch cap.
- Tests live in `tests/Unit/Cron/`.

### E2E

- Triggered via `wp cron event run <hook>` inside the Docker test container, then asserted via the admin UI or REST.
- The auto-close and SLA flows have dedicated Playwright sections.

### Manual

- WP_DEBUG-enabled dev instance: change cron schedule to `oneminute` via the `swh_cron_schedule` filter (dev only), or trigger via `wp cron event run` to verify.

---

## Failure modes

| Scenario | Behavior |
|---|---|
| WordPress cron not firing | Jobs simply don't run. Documented in end-user troubleshooting (set a real cron via `curl wp-cron.php`). |
| Setting at 0 | Job ticks but no-ops. |
| Status label out of sync (operator renamed `Resolved` to `Done` but didn't update setting) | Auto-close finds no matches; user-visible regression. Document this in `config-reference.md`. |
| Filesystem error during attachment delete | Logged; per-file failure does not block remaining files in the same batch. |
| `wp_query` timeout on a huge site | Mitigated by batch caps. If reproducibly hit, lower the cap. |

---

## Adding a new cron job

Five steps:

1. **Pick a unique offset** that doesn't collide with the existing four. Current free slots within the first hour: +15 min, +45 min. Don't reuse 0 / +30 / +60 / +90.
2. **Register in `swh_activate()`**: `wp_schedule_event( time() + OFFSET, 'hourly', 'swh_<event>' );`.
3. **Clear in `swh_deactivate()`**: `wp_clear_scheduled_hook( 'swh_<event>' );`.
4. **Hook the handler**: `add_action( 'swh_<event>', 'swh_run_<event>' );`.
5. **Add a unit test** in `tests/Unit/Cron/` covering disabled / happy-path / batch-cap.

Update this doc with the new job in the inventory table.

---

## Routing

| Touching … | Read also |
|---|---|
| Auto-close behavior | This doc + `contract-ticket-lifecycle.md` |
| Retention | This doc + `contract-file-uploads.md` + `data-dictionary.md` |
| SLA logic | This doc + `contract-ticket-lifecycle.md` |
| Adding a new scheduled job | This doc + `data-dictionary.md` (any new state meta) + the relevant feature contract |
| Cron debugging | End-user `docs/troubleshooting.md` (operator-facing) + this doc |
