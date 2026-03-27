# CLAUDE.md — Simple WP Helpdesk

This file provides guidance for AI assistants working on the Simple WP Helpdesk codebase.

## Project Overview

Simple WP Helpdesk is a WordPress plugin that implements a complete ticketing/helpdesk system. It uses no custom database tables, relying entirely on WordPress core data structures (posts, comments, post meta, comment meta, options).

- **Plugin Version:** 1.4
- **WordPress Minimum:** 5.3+
- **PHP Minimum:** 7.2+
- **Author:** SM WP Plugins / seanmousseau
- **GitHub Repo:** seanmousseau/Simple-WP-Helpdesk

---

## Repository Structure

```
Simple-WP-Helpdesk/
├── CLAUDE.md                               # This file
├── README.md                               # End-user documentation
├── LICENSE                                 # License
├── composer.json                           # Dev dependencies (PHPUnit, PHPCS, WPCS)
├── composer.lock
├── vendor/                                 # Composer dev tools (not distributed)
└── simple-wp-helpdesk/
    ├── simple-wp-helpdesk.php              # Entire plugin — single file, 1710 lines
    └── phpcs.xml                           # PHP CodeSniffer rules
```

> **All plugin logic lives in one file:** `simple-wp-helpdesk/simple-wp-helpdesk.php`. There are no sub-files, partials, or separate class files (except the `SWH_GitHub_Updater` class defined at the bottom of that same file).

---

## Architecture

### Data Storage (No Custom Tables)

| Data Type         | WordPress Storage       | Key Meta Keys                                                                 |
|-------------------|-------------------------|-------------------------------------------------------------------------------|
| Tickets           | `helpdesk_ticket` CPT   | `_ticket_uid`, `_ticket_token`, `_ticket_url`, `_ticket_name`, `_ticket_email`, `_ticket_status`, `_ticket_priority`, `_ticket_assigned_to`, `_ticket_attachments`, `_resolved_timestamp` |
| Replies & Notes   | WP Comments on CPT      | `_is_internal_note`, `_is_user_reply`, `_attachments`                        |
| Plugin Settings   | `wp_options`            | All keys prefixed with `swh_` (see Settings section below)                   |

### Custom Post Type

- **Slug:** `helpdesk_ticket`
- **Visibility:** Admin only (`public => false`)
- **Supports:** `title`, `editor`
- **Icon:** `dashicons-tickets-alt`

### Function Naming Convention

All functions use the `swh_` prefix to avoid namespace collisions:
- `swh_activate()`, `swh_deactivate()`, `swh_uninstall()`
- `swh_get_defaults()`, `swh_get_statuses()`, `swh_get_priorities()`
- `swh_ticket_frontend()`, `swh_save_ticket_data()`, etc.

---

## Key Plugin Settings (wp_options keys)

All options are prefixed `swh_`. Defaults are defined in `swh_get_defaults()` (uses `static $defaults` cache).

| Option Key                        | Default                            | Purpose                                      |
|-----------------------------------|------------------------------------|----------------------------------------------|
| `swh_ticket_priorities`           | `Low, Medium, High`                | Comma-separated list                        |
| `swh_ticket_statuses`             | `Open, In Progress, Resolved, Closed` | Comma-separated list                     |
| `swh_default_priority`            | `Medium`                           |                                              |
| `swh_default_status`              | `Open`                             |                                              |
| `swh_resolved_status`             | `Resolved`                         | Triggers auto-close timer                    |
| `swh_closed_status`               | `Closed`                           | Disables further replies                     |
| `swh_reopened_status`             | `Open`                             | Assigned when client re-opens               |
| `swh_autoclose_days`              | `3`                                | Days after resolved until auto-closed        |
| `swh_max_upload_size`             | `5`                                | MB per file                                  |
| `swh_default_assignee`            | `''`                               | User ID of default technician                |
| `swh_fallback_email`              | `''`                               | Fallback alert email if no assignee          |
| `swh_spam_method`                 | `honeypot`                         | `none`, `honeypot`, `recaptcha`, `turnstile` |
| `swh_recaptcha_site_key`          | `''`                               |                                              |
| `swh_recaptcha_secret_key`        | `''`                               |                                              |
| `swh_turnstile_site_key`          | `''`                               |                                              |
| `swh_turnstile_secret_key`        | `''`                               |                                              |
| `swh_retention_attachments_days`  | `0`                                | 0 = disabled                                 |
| `swh_retention_tickets_days`      | `0`                                | 0 = disabled                                 |
| `swh_delete_on_uninstall`         | `no`                               | `yes` or `no`                                |
| `swh_db_version`                  | *(current version)*                | Tracks upgrade state                         |
| Email template keys (16 total)    | See `swh_get_defaults()`           | `swh_{event}_sub` / `swh_{event}_body`       |
| Frontend message keys (7 total)   | See `swh_get_defaults()`           | `swh_success_new`, `swh_err_spam`, etc.      |

---

## WordPress Hooks

### Actions
| Hook                           | Function                         | Purpose                                    |
|--------------------------------|----------------------------------|--------------------------------------------|
| `admin_init`                   | `swh_run_upgrade_routine()`      | Version-based upgrades & option init       |
| `init`                         | `swh_register_ticket_cpt()`      | Register `helpdesk_ticket` post type       |
| `post_edit_form_tag`           | `swh_add_enctype_to_post_form()` | Adds `enctype="multipart/form-data"`       |
| `wp_head`                      | `swh_load_spam_scripts_in_head()`| Loads reCAPTCHA/Turnstile JS               |
| `admin_menu`                   | `swh_register_settings_page()`   | Adds Settings submenu under Tickets        |
| `add_meta_boxes`               | `swh_add_ticket_meta_boxes()`    | Registers sidebar + conversation meta boxes|
| `save_post_helpdesk_ticket`    | `swh_save_ticket_data()`         | Handles ticket save, emails, attachments   |
| `swh_autoclose_event`          | `swh_process_autoclose()`        | Cron: auto-close resolved tickets          |
| `swh_retention_tickets_event`  | `swh_process_retention_tickets()`| Cron: delete old tickets                   |
| `swh_retention_attachments_event` | `swh_process_retention_attachments()` | Cron: delete old attachments      |

### Shortcode
| Shortcode         | Function                  | Purpose                              |
|-------------------|---------------------------|--------------------------------------|
| `[submit_ticket]` | `swh_ticket_frontend()`   | Frontend form + secure client portal |

---

## Frontend Shortcode Behavior

The `[submit_ticket]` shortcode has two modes determined by URL parameters:

1. **Submission Form** (no URL params): Renders ticket creation form with anti-spam.
2. **Client Portal** (`?swh_ticket=POST_ID&token=TOKEN`): Validates cryptographic token, then displays ticket details and allows replies/re-open/close.

**Token security:** Generated with `wp_generate_password(20, false)`, validated with `hash_equals()` (timing-attack safe).

**Frontend CSS** is scoped under `.swh-helpdesk-wrapper` for page builder compatibility.

---

## Email System

16 email templates (subject + body), all customizable in Settings → Email Templates.

**Template variables** available in all email body/subject fields:
- `{name}` — Client name
- `{email}` — Client email
- `{ticket_id}` — Unique ticket ID (e.g., TKT-0001)
- `{title}` — Ticket title
- `{status}` — Current status
- `{priority}` — Priority level
- `{message}` — Message content
- `{ticket_url}` — Secure client portal URL
- `{admin_url}` — WP admin edit link
- `{autoclose_days}` — Auto-close threshold setting

**Email routing** (via `swh_get_admin_email()`):
1. Assigned technician's email (if set)
2. Default assignee (from settings)
3. Fallback email (from settings)
4. Site admin email

---

## Background Cron Jobs

Three scheduled events, all micro-batched (1-2 items per run) to prevent timeouts:

| Event                             | Schedule       | Offset   | Batch Size |
|-----------------------------------|----------------|----------|------------|
| `swh_autoclose_event`             | Hourly         | +0 min   | 2 tickets  |
| `swh_retention_tickets_event`     | Hourly         | +30 min  | 1 ticket   |
| `swh_retention_attachments_event` | Hourly         | +60 min  | 1 ticket + 1 comment |

---

## Security Conventions

When modifying this plugin, maintain these patterns:

- **Nonces:** All form submissions must use `wp_verify_nonce()`.
- **Capability checks:** Admin operations require `current_user_can('manage_options')`; ticket saves require `current_user_can('edit_post', $post_id)`.
- **Sanitization:** Use `sanitize_text_field()`, `sanitize_email()`, `sanitize_textarea_field()` on all input. Use `wp_kses_post()` for HTML fields (email templates).
- **Escaping output:** Always use `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()` on output.
- **File deletion:** Always validate the file path starts with the uploads directory before deleting (path traversal prevention).
- **Token validation:** Always use `hash_equals()` for token comparison (never `==`).

---

## File Upload Handling

- Uses `wp_handle_upload()` with MIME type restrictions.
- Allowed types: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `doc`, `docx`, `txt`.
- Max size configurable (default 5MB per file).
- Helper functions: `swh_normalize_files_array()`, `swh_handle_multiple_uploads()`, `swh_delete_file_by_url()`.

---

## Development Workflow

### Code Quality Tools

```bash
# Install dev dependencies
composer install

# Run PHP CodeSniffer (WordPress Coding Standards)
vendor/bin/phpcs --standard=simple-wp-helpdesk/phpcs.xml simple-wp-helpdesk/simple-wp-helpdesk.php

# Auto-fix PHPCS issues
vendor/bin/phpcbf --standard=simple-wp-helpdesk/phpcs.xml simple-wp-helpdesk/simple-wp-helpdesk.php

# Run PHPUnit tests (test files not yet present)
vendor/bin/phpunit
```

### Git Branching

- Default development branch for AI-assisted work: `claude/add-claude-documentation-suuR9`
- Production branch: `main`
- GitHub auto-updater checks GitHub releases; tag names must match the plugin `Version:` header.

### Making Changes

1. All plugin code lives in `simple-wp-helpdesk/simple-wp-helpdesk.php`. Do not create new PHP files unless there is a compelling reason (and even then, require them from the main file).
2. Follow existing function naming: prefix with `swh_`.
3. Add new options to both `swh_get_defaults()` and `swh_get_all_option_keys()` to ensure they are included in upgrades, factory resets, and uninstall cleanup.
4. When adding new email templates, add both `_sub` and `_body` variants.
5. When adding new cron events, register them in `swh_activate()` and clear them in `swh_deactivate()`.
6. After modifying settings structure, bump the version string and add an upgrade path in `swh_run_upgrade_routine()` if needed.

---

## GitHub Auto-Updater

Class `SWH_GitHub_Updater` (defined at end of main plugin file):

- Checks GitHub Releases API for new versions.
- Caches result for 12 hours via WordPress transients.
- Key: `swh_gh_release_v3_{VERSION}` (cache-busts on version change).
- Handles nested folder flattening when GitHub zips include a subdirectory.
- Prioritizes pre-built `.zip` release assets over raw source archives.

---

## Admin UI — Settings Tabs

| Tab                    | Description                                                      |
|------------------------|------------------------------------------------------------------|
| General                | Priorities, statuses, defaults, auto-close days, upload size     |
| Assignment & Routing   | Default assignee, fallback email                                 |
| Email Templates        | 16 subject+body templates with placeholder reference             |
| Messages               | 7 user-facing success/error messages                             |
| Anti-Spam              | Method selector + API keys for reCAPTCHA/Turnstile               |
| Tools                  | Data retention, uninstall settings, GDPR purge, factory reset    |

---

## Common Pitfalls

- **Don't use `==` for token comparison** — always use `hash_equals()`.
- **Don't forget `enctype="multipart/form-data"`** on any form handling file uploads.
- **Static cache in `swh_get_defaults()`** — if you need fresh option data in a long-running process, be aware defaults are cached per request.
- **Cron offset times** — if adding a new cron, use a different offset to avoid simultaneous execution with existing jobs.
- **Comment visibility** — internal notes (`_is_internal_note = 1`) must be filtered out of the frontend client view.
- **Attachment arrays** — stored as serialized PHP arrays; may exist as a single URL string (legacy format). The `swh_delete_file_by_url()` function handles both.
- **Email on ticket save** — `swh_save_ticket_data()` detects what changed (status, reply, assignment) and sends appropriate emails. Adding new meta fields must account for the save lifecycle to avoid spurious emails.
