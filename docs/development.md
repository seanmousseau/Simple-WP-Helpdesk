# Development Guide

---

## Repository Structure

```text
Simple-WP-Helpdesk/
├── CHANGELOG.md
├── CLAUDE.md                            # AI assistant guidance
├── README.md
├── LICENSE
├── docs/                                # This documentation
└── simple-wp-helpdesk/
    ├── simple-wp-helpdesk.php           # Bootstrap: constants, requires, lifecycle hooks
    ├── includes/
    │   ├── helpers.php                  # Defaults, statuses, anti-spam, rate limiting
    │   ├── class-installer.php          # Activation, deactivation, uninstall, upgrade, CPT
    │   ├── class-email.php              # Template parsing, email sending, HTML wrapping
    │   ├── class-ticket.php             # File proxy, uploads, deletion, comment filters
    │   └── class-cron.php              # Auto-close, retention (tickets + attachments)
    ├── admin/
    │   ├── class-settings.php           # Settings page render + save handler
    │   ├── class-ticket-editor.php      # Meta boxes, save_post, conversation UI
    │   └── class-ticket-list.php        # Columns, sorting, filters, admin styles
    ├── frontend/
    │   ├── class-shortcode.php          # [submit_ticket] + [helpdesk_portal] shortcodes
    │   └── class-portal.php             # Client portal view
    ├── vendor/plugin-update-checker/    # GitHub auto-updater library
    ├── assets/                          # CSS, JS
    └── languages/                       # .pot/.po/.mo
```

The bootstrap file (`simple-wp-helpdesk.php`) is a loader (~160 lines, including the GitHub auto-updater initialisation and plugin icon injection). Admin files are only loaded inside `is_admin()`. Constants: `SWH_PLUGIN_DIR`, `SWH_PLUGIN_URL`, `SWH_PLUGIN_FILE`.

---

## Getting Started

```bash
git clone https://github.com/seanmousseau/Simple-WP-Helpdesk.git
cd Simple-WP-Helpdesk
```

No build step is required. Drop the `simple-wp-helpdesk/` folder into your WordPress `wp-content/plugins/` directory and activate it from the WordPress dashboard.

---

## Architecture Overview

### No Custom Database Tables

The plugin uses only WordPress core data structures:

| Data | Storage | Key Meta |
|------|---------|----------|
| Tickets | `helpdesk_ticket` Custom Post Type | `_ticket_uid`, `_ticket_token`, `_ticket_status`, `_ticket_priority`, `_ticket_email`, `_ticket_attachments`, `_swh_attachment_orignames`, `_ticket_csat`, etc. |
| Replies & Notes | WP Comments on the CPT | `_is_internal_note`, `_is_user_reply`, `_swh_reply_orignames` |
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

1. Add to `swh_get_defaults()` in `includes/helpers.php`:
   ```php
   'swh_my_new_option' => 'default_value',
   ```
2. Add the field to the appropriate settings tab in `admin/class-settings.php`.
3. Add it to the correct save block in `swh_handle_settings_save()` — the main form handler (guarded by `swh_settings_nonce`) or the Tools form handler (guarded by `swh_tools_nonce`).

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

## Testing

The full test suite must pass before any PR is opened or release is cut.

```bash
make test-docker   # full PHP gate inside Docker (preferred — no host PHP needed)
make test          # full PHP gate on host (requires PHP 8.1+, semgrep)
make e2e           # Playwright E2E suite (set WP_MODE=docker or configure SSH vars)
make e2e-docker    # self-contained E2E: up → setup → Playwright → teardown in one command
make coverage      # PHPUnit + pcov → coverage.xml (Clover)
```

Individual tools:

| Command | Purpose |
|---------|---------|
| `make lint` | PHP syntax check |
| `make phpcs` | WordPress Coding Standards (zero errors) |
| `make phpstan` | Static analysis level 9 |
| `make phpunit` | Unit tests |
| `make semgrep` | SAST security scan |

**MailHog email assertions:** when `MAILHOG_URL` is set and `WP_MODE=docker`, `expect_email()` calls in the E2E suite assert delivery via the MailHog API automatically. In SSH mode they fall back to the manual `EMAIL_CHECKS` summary printed at the end of the run.

---

## Release Process

1. **Bump the version** in `simple-wp-helpdesk.php`:
   - `Version:` in the plugin header comment
   - `define( 'SWH_VERSION', 'X.Y.Z' )`

2. **Update `CHANGELOG.md`**, `simple-wp-helpdesk/readme.txt` (stable tag + changelog section), and any relevant `docs/` pages.

3. **Run the full gate** — `make test-docker && make e2e-docker` must both exit 0.

4. **Close any GitHub issues** addressed by the release.

5. **Open a PR** from `release/vX.Y.Z` to `main`.

6. **Merge to `main`**, then push the version tag:

   ```bash
   git tag vX.Y.Z && git push origin vX.Y.Z
   ```

   `release.yml` fires automatically on the tag push — it builds `simple-wp-helpdesk.zip` and creates the GitHub Release with the ZIP attached. No manual ZIP step required.
