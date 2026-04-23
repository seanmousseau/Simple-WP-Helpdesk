---
title: Hooks Reference
nav_order: 8
parent: Development Guide
---

# Hooks Reference

Simple WP Helpdesk exposes the following WordPress filters and actions for customization. All hooks use the `swh_` prefix.

---

## Filters

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

### `swh_pre_ticket_create`

Fires immediately before the ticket post is inserted into the database. Use this to validate submission data or trigger external integrations.

```php
add_action( 'swh_pre_ticket_create', function( array $data ): void {
    // Log subject rather than raw email to avoid PII in logs
    error_log( 'New ticket incoming: ' . ( $data['title'] ?? '' ) );
} );
```

**Parameters:** `$data` (array) — the submission data array (same shape as `swh_submission_data`).

---

### `swh_ticket_created`

Fires after a new ticket has been successfully created and all meta has been saved. The ticket ID is available and all post meta is already stored.

```php
add_action( 'swh_ticket_created', function( int $ticket_id, array $data ): void {
    // Sync the new ticket to an external CRM
    my_crm_create_ticket( $ticket_id, $data['email'], $data['title'] );
}, 10, 2 );
```

**Parameters:** `$ticket_id` (int) — the WP post ID of the new ticket; `$data` (array) — the submission data array.
