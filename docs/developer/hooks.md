# Action Hooks Reference

Stable, public action hooks integrators can hook into to react to ticket lifecycle events.

All actions live in the global `do_action` namespace and follow WordPress conventions: register listeners with `add_action()`, use the documented signature, and treat the action as fire-and-forget (return values are ignored).

> Filter hooks live in [filters.md](filters.md) (forthcoming). This page covers actions only.

---

## Pre-existing actions

### `swh_pre_ticket_create`

*Since 2.1.0.*

Fires immediately before a new ticket is inserted, after submission data has been sanitized and the `swh_submission_data` filter has run. Use this to validate or reject submissions before they hit the database.

```php
add_action( 'swh_pre_ticket_create', function ( array $data ) {
    // Sniff for obvious abuse before the post is created.
    if ( str_contains( strtolower( $data['title'] ?? '' ), 'http://' ) ) {
        wp_die( 'Links in titles are not permitted.', 'Submission blocked', array( 'response' => 400 ) );
    }
}, 10, 1 );
```

**Args:**
- `array<string,string> $data` — sanitized submission fields: `name`, `email`, `title`, `message`, `priority`, `status`.

### `swh_ticket_created`

*Since 2.1.0.*

Fires after a new ticket has been fully created — meta written, attachments stored, assignment rules applied, and confirmation emails dispatched.

```php
add_action( 'swh_ticket_created', function ( int $ticket_id, array $data ) {
    // Push a card into a Trello inbox.
    wp_remote_post(
        'https://api.trello.com/1/cards',
        array(
            'body' => array(
                'idList' => '5f1234abcd',
                'name'   => sprintf( '[%s] %s', $data['ticket_id'], $data['title'] ),
                'desc'   => $data['admin_url'],
                'key'    => MY_TRELLO_KEY,
                'token'  => MY_TRELLO_TOKEN,
            ),
        )
    );
}, 10, 2 );
```

**Args:**
- `int $ticket_id` — the new ticket post ID.
- `array<string,string> $data` — submission data including `ticket_id` (UID), `ticket_url`, `email`.

---

## Lifecycle actions (v3.7.0+)

### `swh_ticket_replied`

*Since 3.7.0.*

Fires after a `helpdesk_reply` comment is inserted on a ticket. Triggered for staff replies, client replies, and system-generated comments (auto-close notes, retention purge notes, merge breadcrumbs). Use the `$is_staff_reply` argument to distinguish staff from non-staff.

`$is_staff_reply` is `true` only when the comment author has the `edit_post` capability on the ticket (administrators and technicians). Portal client replies, inbound-email replies, and system comments report `false`.

```php
add_action( 'swh_ticket_replied', function ( int $ticket_id, int $comment_id, bool $is_staff_reply ) {
    // Slack-notify the team when a client replies on a ticket that has gone quiet.
    if ( $is_staff_reply ) {
        return;
    }
    $uid = get_post_meta( $ticket_id, '_ticket_uid', true );
    wp_remote_post(
        MY_SLACK_INBOX_WEBHOOK,
        array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode(
                array( 'text' => sprintf( ':speech_balloon: Client reply on %s', $uid ) )
            ),
        )
    );
}, 10, 3 );
```

**Args:**
- `int $ticket_id` — ticket post ID.
- `int $comment_id` — the inserted comment ID.
- `bool $is_staff_reply` — `true` when the comment author has `edit_post` cap on the ticket.

### `swh_ticket_status_changed`

*Since 3.7.0.*

Fires whenever a ticket's `_ticket_status` post meta changes to a different value. Does **not** fire on initial ticket creation (use `swh_ticket_created` for that) or when the new status matches the existing one (no-op).

```php
add_action( 'swh_ticket_status_changed', function ( int $ticket_id, string $old_status, string $new_status ) {
    // Mirror status to an external CRM.
    my_crm_set_ticket_status( $ticket_id, $new_status );
}, 10, 3 );
```

**Args:**
- `int $ticket_id` — ticket post ID.
- `string $old_status` — previous status label.
- `string $new_status` — new status label.

### `swh_ticket_closed`

*Since 3.7.0.*

Fires after `swh_ticket_status_changed` when the transition takes the ticket from a non-closed status into the configured closed status (`swh_closed_status` option).

```php
add_action( 'swh_ticket_closed', function ( int $ticket_id, string $previous_status ) {
    // Stop the SLA timer in PagerDuty.
    pagerduty_resolve_incident( get_post_meta( $ticket_id, '_pagerduty_incident_id', true ) );
}, 10, 2 );
```

**Args:**
- `int $ticket_id` — ticket post ID.
- `string $previous_status` — status the ticket transitioned from.

### `swh_ticket_reopened`

*Since 3.7.0.*

Fires after `swh_ticket_status_changed` when the transition takes the ticket from the closed status back into any non-closed status.

```php
add_action( 'swh_ticket_reopened', function ( int $ticket_id, string $previous_status ) {
    // Re-open the linked PagerDuty incident.
    pagerduty_trigger_incident_from_ticket( $ticket_id );
}, 10, 2 );
```

**Args:**
- `int $ticket_id` — ticket post ID.
- `string $previous_status` — closed status the ticket transitioned from.

### `swh_ticket_assigned`

*Since 3.7.0.*

Fires whenever the `_ticket_assigned_to` user changes. Does **not** fire on a `0 → 0` no-op (unassigned → unassigned). Fires on `0 → user`, `user → 0`, and `userA → userB`.

```php
add_action( 'swh_ticket_assigned', function ( int $ticket_id, int $old_user_id, int $new_user_id ) {
    // DM the new assignee in Slack.
    if ( $new_user_id > 0 ) {
        $slack_id = get_user_meta( $new_user_id, 'slack_user_id', true );
        my_slack_dm( $slack_id, sprintf( 'Ticket #%d is yours.', $ticket_id ) );
    }
}, 10, 3 );
```

**Args:**
- `int $ticket_id` — ticket post ID.
- `int $old_user_id` — previous assignee user ID (0 if unassigned).
- `int $new_user_id` — new assignee user ID (0 if unassigned).

### `swh_sla_breached`

*Since 3.7.0.*

Fires the first time a ticket transitions to SLA breach state during the hourly `swh_sla_check_event` cron run. Will not fire again on subsequent runs for the same ticket unless the breach flag is manually cleared.

```php
add_action( 'swh_sla_breached', function ( int $ticket_id, int $minutes_over ) {
    // Page the on-call engineer when a ticket goes red.
    pagerduty_trigger_incident(
        sprintf( 'Ticket %d is %d minutes over SLA.', $ticket_id, $minutes_over )
    );
}, 10, 2 );
```

**Args:**
- `int $ticket_id` — ticket post ID.
- `int $minutes_over` — minutes the ticket is over the configured breach threshold.

### `swh_csat_submitted`

*Since 3.7.0.*

Fires after a client successfully submits a CSAT (customer satisfaction) rating via the post-close prompt.

```php
add_action( 'swh_csat_submitted', function ( int $ticket_id, int $rating ) {
    // Forward the score to a metrics warehouse.
    metrics_warehouse_record_event(
        'helpdesk.csat',
        array(
            'ticket_id' => $ticket_id,
            'rating'    => $rating,
            'assignee'  => (int) get_post_meta( $ticket_id, '_ticket_assigned_to', true ),
        )
    );
}, 10, 2 );
```

**Args:**
- `int $ticket_id` — ticket post ID.
- `int $rating` — submitted rating, integer 1–5.

---

## Stability policy

These action signatures are public API. They will not change within the v3.x line. Removal or signature changes follow the [deprecation policy](deprecations.md) (forthcoming): two-minor-release deprecation window before removal.
