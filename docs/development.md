# Development Guide

---

## Repository Structure

```
Simple-WP-Helpdesk/
├── CHANGELOG.md
├── CLAUDE.md                            # AI assistant guidance
├── README.md
├── LICENSE
├── composer.json                        # Dev dependencies (PHPUnit, PHPCS, WPCS)
├── composer.lock
├── simple-wp-helpdesk.zip               # Distribution archive
├── vendor/                              # Composer dev tools (not distributed)
├── docs/                                # This documentation
└── simple-wp-helpdesk/
    ├── simple-wp-helpdesk.php           # Entire plugin — single file
    └── phpcs.xml                        # PHP CodeSniffer ruleset
```

> All plugin logic lives in **one file**: `simple-wp-helpdesk/simple-wp-helpdesk.php`.

---

## Setting Up

```bash
# Clone the repository
git clone https://github.com/seanmousseau/Simple-WP-Helpdesk.git
cd Simple-WP-Helpdesk

# Install dev dependencies
composer install
```

---

## Code Quality

### PHP CodeSniffer (WordPress Coding Standards)

```bash
# Check for violations
vendor/bin/phpcs --standard=simple-wp-helpdesk/phpcs.xml simple-wp-helpdesk/simple-wp-helpdesk.php

# Auto-fix fixable violations
vendor/bin/phpcbf --standard=simple-wp-helpdesk/phpcs.xml simple-wp-helpdesk/simple-wp-helpdesk.php
```

### PHPUnit

```bash
vendor/bin/phpunit
```

> Note: Automated test coverage is in progress. Test files are not yet present.

---

## Architecture Overview

### No Custom Database Tables

The plugin uses only WordPress core data structures:

| Data | Storage | Key Meta |
|------|---------|----------|
| Tickets | `helpdesk_ticket` Custom Post Type | `_ticket_uid`, `_ticket_token`, `_ticket_status`, `_ticket_priority`, `_ticket_email`, etc. |
| Replies & Notes | WP Comments on the CPT | `_is_internal_note`, `_is_user_reply`, `_attachments` |
| Settings | `wp_options` | All keys prefixed with `swh_` |

### Function Naming

All functions use the `swh_` prefix:

```php
swh_activate()
swh_get_defaults()
swh_ticket_frontend()
swh_save_ticket_data()
```

### Single Source of Truth for Defaults

**`swh_get_defaults()`** is the definitive list of every plugin option and its default value. It uses a `static $defaults` cache so it is only built once per request.

When adding a new option:
1. Add it to `swh_get_defaults()` with its default value.
2. It will automatically be registered by the upgrade routine, included in factory reset, and cleaned up on uninstall.

---

## Adding Features

### New Option

1. Add to `swh_get_defaults()`:
   ```php
   'swh_my_new_option' => 'default_value',
   ```
2. Add the field to the appropriate settings tab in `swh_render_settings_page()`.
3. Add it to the correct save handler (`swh_handle_settings_save()` or `swh_handle_tools_save()`).

### New Email Template

Add both variants to `swh_get_defaults()`:
```php
'swh_my_event_sub'  => 'Subject: {ticket_id}',
'swh_my_event_body' => 'Hello {name}, ...',
```

### New Cron Job

1. Register in `swh_activate()`:
   ```php
   wp_schedule_event( time() + OFFSET_SECONDS, 'hourly', 'swh_my_event' );
   ```
2. Clear in `swh_deactivate()`:
   ```php
   wp_clear_scheduled_hook( 'swh_my_event' );
   ```
3. Hook the handler:
   ```php
   add_action( 'swh_my_event', 'swh_process_my_event' );
   ```
4. Use a different offset from existing jobs (currently: +0 min, +30 min, +60 min) to avoid simultaneous execution.

---

## Settings Forms — Two Nonces

There are **two separate forms** on the settings page, each with its own nonce:

| Form | Nonce Action | Nonce Field | Owns |
|------|-------------|-------------|------|
| Main settings | `swh_save_settings_action` | `swh_settings_nonce` | Everything except Tools tab |
| Tools form | `swh_save_tools_action` | `swh_tools_nonce` | `swh_retention_*`, `swh_delete_on_uninstall` |

Never move `swh_delete_on_uninstall` or retention settings to the main form handler — they will reset silently.

---

## Building a Release ZIP

```bash
zip -r simple-wp-helpdesk.zip simple-wp-helpdesk/ --exclude "simple-wp-helpdesk/phpcs.xml"
```

The resulting archive contains `simple-wp-helpdesk/simple-wp-helpdesk.php` and is ready for manual installation via the WordPress dashboard (**Plugins → Add New → Upload Plugin**) or for attachment to a GitHub release.

---

## Versioning

1. Update `Version:` in the plugin header comment inside `simple-wp-helpdesk.php`.
2. Update `define( 'SWH_VERSION', 'X.Y' )`.
3. If the settings schema changed, add an upgrade path in `swh_run_upgrade_routine()`.
4. Add a version entry to `CHANGELOG.md`.
5. Build and attach the release ZIP to the GitHub release. The tag name must match the `Version:` header for the auto-updater to work correctly.
