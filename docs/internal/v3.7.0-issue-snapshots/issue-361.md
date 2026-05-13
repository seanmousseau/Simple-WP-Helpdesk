# Issue #361: Add ticket lifecycle action hooks (reply, status, assign, close, reopen, SLA, CSAT)

**Labels:** enhancement
**Milestone:** v3.7.0 — v4 Foundations

## Body

Ship a complete set of `do_action` calls so integrators can react to every meaningful state change. Today only `swh_pre_ticket_create` and `swh_ticket_created` fire.

## New actions

| Action | Fires from | Args |
|---|---|---|
| `swh_ticket_replied` | comment insert in `class-ticket.php` + portal reply handler | `$ticket_id, $comment_id, $is_staff_reply` |
| `swh_ticket_status_changed` | `swh_save_ticket_data()` + portal close/reopen | `$ticket_id, $old_status, $new_status` |
| `swh_ticket_assigned` | assignee change in save handler + `swh_apply_assignment_rules()` | `$ticket_id, $old_user_id, $new_user_id` |
| `swh_ticket_closed` | status transition to closed | `$ticket_id, $previous_status` |
| `swh_ticket_reopened` | status transition from closed → open/in-progress | `$ticket_id, $previous_status` |
| `swh_sla_breached` | `swh_sla_check_event` cron when breach flag set | `$ticket_id, $minutes_over` |
| `swh_csat_submitted` | AJAX CSAT handler | `$ticket_id, $rating` |

## Acceptance criteria

- Actions fire exactly once per event (guarded against loops)
- Args documented in `docs/developer/hooks.md` with an example listener per action
- Each action has at least one unit test verifying it fires with correct args
- Hook reference regenerated (`docs/hooks-reference.md` or equivalent)

## Out of scope

- REST exposure (separate issue)
- Outbound webhook consumption (v4.2)

## Dependency

Blocks everything else in v4.1 and all of v4.2.
