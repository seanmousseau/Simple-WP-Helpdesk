---
title: Hooks Reference
nav_order: 1
parent: Development Guide
---

# Hooks Reference

Simple WP Helpdesk exposes the following WordPress filters and actions for customization. All hooks use the `swh_` prefix.

---

## Filters

> ⚠️ **All filters on this page are deprecated as of v4.0** and continue to fire only for back-compatibility (they emit `_doing_it_wrong` notices under `WP_DEBUG`). Earliest scheduled removal: **v4.2**, per the two-minor-release deprecation window. New integrations should subscribe to **action hooks** (next section) and watch the [Deprecation Policy](developer/deprecations) for replacements as v4.1 / v4.2 land. If you're already hooked into one of these filters, please open a feature request describing your use case so we can shape the right replacement before removal.

### `swh_ticket_statuses`

Modify the array of available ticket statuses.

```php
add_filter( 'swh_ticket_statuses', function( array $statuses ): array {
    $statuses[] = 'Waiting on Client';
    return $statuses;
} );
```

**Parameters:** `$statuses` (array) — the current list of status strings.

---

### `swh_ticket_priorities`

Modify the array of available ticket priorities.

```php
add_filter( 'swh_ticket_priorities', function( array $priorities ): array {
    $priorities[] = 'Critical';
    return $priorities;
} );
```

**Parameters:** `$priorities` (array) — the current list of priority strings.

---

### `swh_allowed_file_types`

Modify the array of allowed file extensions for ticket attachments.

```php
add_filter( 'swh_allowed_file_types', function( array $types ): array {
    $types[] = 'zip';
    $types[] = 'csv';
    return $types;
} );
```

**Parameters:** `$types` (array) — default: `['jpg', 'jpeg', 'jpe', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt']`.

---

### `swh_submission_data`

Filter the ticket data array immediately before the ticket post is created. Use this to add extra meta, override fields, or integrate with other plugins.

```php
add_filter( 'swh_submission_data', function( array $data ): array {
    // Add a custom meta value derived from the submission
    $data['my_extra_field'] = 'value';
    return $data;
} );
```

**Parameters:** `$data` (array) — associative array of ticket fields (name, email, title, message, priority, status, etc.).

---

### `swh_parse_template`

Filter the fully rendered email template string after all placeholders have been replaced.

```php
add_filter( 'swh_parse_template', function( string $template, array $data ): string {
    return str_replace( '{site_name}', get_bloginfo( 'name' ), $template );
}, 10, 2 );
```

**Parameters:** `$template` (string) — the rendered template; `$data` (array) — the placeholder data used during rendering.

---

### `swh_email_headers`

Modify the email headers array before any outgoing message is sent. Use this to add Reply-To, CC, or custom headers.

```php
add_filter( 'swh_email_headers', function( array $headers, string $to, string $subject ): array {
    $headers[] = 'Reply-To: support@example.com';
    return $headers;
}, 10, 3 );
```

**Parameters:** `$headers` (array), `$to` (string), `$subject` (string).

---

### `swh_autoclose_threshold`

Override the number of days after "Resolved" status before a ticket is automatically closed. Return `0` to disable auto-close entirely.

```php
add_filter( 'swh_autoclose_threshold', function( int $days ): int {
    return 7; // override to 7 days regardless of the Settings value
} );
```

**Parameters:** `$days` (int) — the value from Settings → General → Auto-Close Days.

---

### `swh_rate_limit_ttl`

Override the rate limit cooldown period (in seconds) for a specific action.

```php
add_filter( 'swh_rate_limit_ttl', function( int $ttl, string $action ): int {
    if ( 'submit' === $action ) {
        return 60; // 60-second cooldown for new ticket submissions
    }
    return $ttl;
}, 10, 2 );
```

**Parameters:** `$ttl` (int) — default TTL in seconds; `$action` (string) — the action key (e.g. `'submit'`, `'portal_reply_'`, `'portal_close_'`, `'portal_reopen_'`).

---

### `swh_sla_open_statuses`

Modify the list of statuses considered "open" for SLA breach calculations. Tickets in any of these statuses are checked against the warn/breach thresholds by the hourly cron.

```php
add_filter( 'swh_sla_open_statuses', function( array $statuses ): array {
    // Remove 'In Progress' from SLA tracking
    return array_diff( $statuses, array( 'In Progress' ) );
} );
```

**Parameters:** `$open_statuses` (array) — the current list of open status strings.

---

## Actions

All actions follow WordPress conventions: register with `add_action()`, use the documented signature, and treat the action as fire-and-forget (return values are ignored).

### `swh_pre_ticket_create`

*Since 2.1.0.*

Fires immediately before a new ticket is inserted, after submission data has been sanitized and the `swh_submission_data` filter has run. Use this to validate or reject submissions before they hit the database.

```php
add_action( 'swh_pre_ticket_create', function ( array $data ) {
    if ( str_contains( strtolower( $data['title'] ?? '' ), 'http://' ) ) {
        wp_die( 'Links in titles are not permitted.', 'Submission blocked', array( 'response' => 400 ) );
    }
}, 10, 1 );
```

**Args:** `$data` (array) — sanitized submission fields: `name`, `email`, `title`, `message`, `priority`, `status`.

---

### `swh_ticket_created`

*Since 2.1.0.*

Fires after a new ticket has been fully created — meta written, attachments stored, assignment rules applied, and confirmation emails dispatched.

```php
add_action( 'swh_ticket_created', function ( int $ticket_id, array $data ) {
    my_crm_create_ticket( $ticket_id, $data['email'], $data['title'] );
}, 10, 2 );
```

**Args:** `$ticket_id` (int) — the new ticket post ID; `$data` (array) — submission data including `ticket_id` (UID), `ticket_url`, `email`.

---

### `swh_ticket_replied`

*Since 3.7.0.*

Fires after a `helpdesk_reply` comment is inserted on a ticket. Triggered for staff replies, client replies, inbound-email replies, and system-generated comments. The `$is_staff_reply` argument is `true` only when the comment author has the `edit_post` capability on the ticket.

```php
add_action( 'swh_ticket_replied', function ( int $ticket_id, int $comment_id, bool $is_staff_reply ) {
    if ( $is_staff_reply ) {
        return;
    }
    // Notify Slack on client replies only.
    my_slack_notify( $ticket_id );
}, 10, 3 );
```

**Args:** `$ticket_id` (int), `$comment_id` (int), `$is_staff_reply` (bool).

---

### `swh_ticket_status_changed`

*Since 3.7.0.*

Fires whenever a ticket's `_ticket_status` changes to a different value. Does **not** fire on initial ticket creation (use `swh_ticket_created`) or on no-op writes.

```php
add_action( 'swh_ticket_status_changed', function ( int $ticket_id, string $old_status, string $new_status ) {
    my_crm_set_ticket_status( $ticket_id, $new_status );
}, 10, 3 );
```

**Args:** `$ticket_id` (int), `$old_status` (string), `$new_status` (string).

> Always mutate `_ticket_status` via `swh_set_ticket_status()` — direct `update_post_meta()` bypasses this hook.

---

### `swh_ticket_closed`

*Since 3.7.0.*

Fires after `swh_ticket_status_changed` when the transition takes the ticket into the configured closed status.

```php
add_action( 'swh_ticket_closed', function ( int $ticket_id, string $previous_status ) {
    pagerduty_resolve_incident( get_post_meta( $ticket_id, '_pagerduty_incident_id', true ) );
}, 10, 2 );
```

**Args:** `$ticket_id` (int), `$previous_status` (string).

---

### `swh_ticket_reopened`

*Since 3.7.0.*

Fires after `swh_ticket_status_changed` when the transition takes the ticket from closed back into any non-closed status.

```php
add_action( 'swh_ticket_reopened', function ( int $ticket_id, string $previous_status ) {
    pagerduty_trigger_incident_from_ticket( $ticket_id );
}, 10, 2 );
```

**Args:** `$ticket_id` (int), `$previous_status` (string).

---

### `swh_ticket_assigned`

*Since 3.7.0.*

Fires whenever `_ticket_assigned_to` changes. Does **not** fire on `0 → 0`. Fires on `0 → user`, `user → 0`, and `userA → userB`.

```php
add_action( 'swh_ticket_assigned', function ( int $ticket_id, int $old_user_id, int $new_user_id ) {
    if ( $new_user_id > 0 ) {
        my_slack_dm_user( $new_user_id, $ticket_id );
    }
}, 10, 3 );
```

**Args:** `$ticket_id` (int), `$old_user_id` (int, 0 if unassigned), `$new_user_id` (int, 0 if unassigned).

---

### `swh_sla_breached`

*Since 3.7.0.*

Fires the first time a ticket transitions to SLA breach state during the hourly `swh_sla_check_event` cron run. Will not fire again on subsequent runs for the same ticket unless the breach flag is manually cleared.

```php
add_action( 'swh_sla_breached', function ( int $ticket_id, int $minutes_over ) {
    pagerduty_trigger_incident( sprintf( 'Ticket %d is %d minutes over SLA.', $ticket_id, $minutes_over ) );
}, 10, 2 );
```

**Args:** `$ticket_id` (int), `$minutes_over` (int).

---

### `swh_csat_submitted`

*Since 3.7.0.*

Fires after a client successfully submits a CSAT rating via the post-close prompt.

```php
add_action( 'swh_csat_submitted', function ( int $ticket_id, int $rating ) {
    metrics_warehouse_record_event( 'helpdesk.csat', array(
        'ticket_id' => $ticket_id,
        'rating'    => $rating,
    ) );
}, 10, 2 );
```

**Args:** `$ticket_id` (int), `$rating` (int, 1–5).

---

## Stability policy

These hook signatures are public API and will not change within the v4.x line. Removal or signature changes follow the [Deprecation Policy](developer/deprecations): a two-minor-release deprecation window before removal.
