---
title: Development Guide
nav_order: 7
has_children: true
---

# Development Guide

---

## Repository Structure

```text
Simple-WP-Helpdesk/
├── CHANGELOG.md
├── CLAUDE.md                            # AI assistant guidance
├── README.md
├── LICENSE
├── Makefile                             # Local PHP gate (lint/phpcs/phpstan/phpunit/semgrep); E2E via make e2e-docker
├── composer.json
├── docker-compose.test.yml              # Docker test stack (WP + MySQL + MailHog)
├── docker/
│   ├── setup-test-wp.sh                 # Configures the Docker WP instance for E2E tests
│   └── mailhog-smtp.php                 # MU-plugin: routes wp_mail() through MailHog
├── docs/                                # This documentation (GitHub Pages source)
├── testing/
│   ├── scripts/
│   │   ├── test_helpdesk_pw.py          # Playwright E2E test suite (58 sections)
│   │   └── conftest.py                  # pytest fixtures and helpers
│   ├── pytest.ini
│   ├── requirements.txt
│   └── .env.example
└── simple-wp-helpdesk/
    ├── simple-wp-helpdesk.php           # Bootstrap: constants, requires, lifecycle hooks
    ├── includes/
    │   ├── helpers.php                  # Defaults, statuses, anti-spam, rate limiting
    │   ├── class-installer.php          # Activation, deactivation, uninstall, upgrade, CPT
    │   ├── class-email.php              # Template parsing, email sending, HTML wrapping
    │   ├── class-ticket.php             # File proxy, uploads, deletion, comment filters
    │   └── class-cron.php               # Auto-close, SLA check, retention (tickets + files)
    ├── admin/
    │   ├── class-settings.php           # Settings page render + save handler (8 tabs)
    │   ├── class-ticket-editor.php      # Meta boxes, save_post, conversation UI
    │   ├── class-ticket-list.php        # Columns, sorting, filters, bulk actions
    │   ├── class-reporting.php          # Reporting AJAX endpoints (status, resolution, trend, KPI)
    │   └── class-reporting-ui.php       # Reports submenu page render + Chart.js enqueue
    ├── frontend/
    │   ├── class-shortcode.php          # [submit_ticket] + [helpdesk_portal] shortcodes
    │   └── class-portal.php             # Client portal view
    ├── vendor/plugin-update-checker/    # GitHub auto-updater library
    ├── assets/
    │   ├── swh-shared.css               # Design tokens + shared components
    │   ├── swh-admin.css                # Admin-only styles
    │   ├── swh-frontend.css             # Frontend styles
    │   ├── swh-admin.js                 # Admin JS (toast, canned responses, etc.)
    │   └── swh-frontend.js              # Frontend JS (form, portal interactions)
    └── languages/
        └── simple-wp-helpdesk.pot
```

The bootstrap file (`simple-wp-helpdesk.php`) is a thin loader — admin files are only loaded inside `is_admin()`. Constants: `SWH_PLUGIN_DIR`, `SWH_PLUGIN_URL`, `SWH_PLUGIN_FILE`.

---

## Getting Started

```bash
git clone https://github.com/seanmousseau/Simple-WP-Helpdesk.git
cd Simple-WP-Helpdesk
composer install
```

No build step is required for the plugin itself. Drop the `simple-wp-helpdesk/` folder into your WordPress `wp-content/plugins/` directory and activate it from the WordPress dashboard.

---

## Architecture Overview

### No Custom Database Tables

The plugin uses only WordPress core data structures:

| Data | Storage | Key Meta |
|------|---------|----------|
| Tickets | `helpdesk_ticket` Custom Post Type | `_ticket_uid`, `_ticket_token`, `_ticket_status`, `_ticket_priority`, `_ticket_email`, `_ticket_attachments`, `_ticket_csat`, etc. |
| Replies & Notes | WP Comments (`comment_type = 'helpdesk_reply'`) | `_is_internal_note`, `_is_user_reply`, `_swh_reply_orignames` |
| Settings | `wp_options` | All keys prefixed with `swh_` |
| Canned responses | `wp_options` | `swh_canned_responses` (JSON array) |
| Ticket templates | `wp_options` | `swh_ticket_templates` (JSON array) |

### Function & Class Naming

All public functions use the `swh_` prefix; all classes use `SWH_`:

```php
swh_activate()
swh_get_defaults()
swh_ticket_frontend()
swh_save_ticket_data()
```

### Single Source of Truth for Defaults

**`swh_get_defaults()`** (`includes/helpers.php`) is the definitive list of every plugin option and its default value. It uses a `static $defaults` cache so it is built only once per request.

When adding a new option:
1. Add it to `swh_get_defaults()` with its default value — it is automatically registered by the upgrade routine, included in factory reset, and cleaned up on uninstall.
2. Add the field to the appropriate settings tab in `admin/class-settings.php`.
3. Add it to the correct save block in `swh_handle_settings_save()` (main form or Tools form — see below).

---

## Adding Features

### New Option

```php
// 1. includes/helpers.php — swh_get_defaults()
'swh_my_new_option' => 'default_value',
```

Then add the field to `admin/class-settings.php` and the corresponding save block.

### New Email Template

Add both variants to `swh_get_defaults()`:
```php
'swh_my_event_sub'  => 'Subject: {ticket_id}',
'swh_my_event_body' => 'Hello {name}, ...',
```

### New Cron Job

```php
// Register in swh_activate():
wp_schedule_event( time() + OFFSET_SECONDS, 'hourly', 'swh_my_event' );

// Clear in swh_deactivate():
wp_clear_scheduled_hook( 'swh_my_event' );

// Hook the handler:
add_action( 'swh_my_event', 'swh_process_my_event' );
```

Use a different offset from existing jobs (currently +0 min, +30 min, +60 min) to avoid simultaneous execution.

---

## Settings Forms — Two Nonces

There are **two separate forms** on the settings page, each with its own nonce:

| Form | Nonce Action | Nonce Field | Owns |
|------|-------------|-------------|------|
| Main settings | `swh_save_settings_action` | `swh_settings_nonce` | Everything except Tools tab |
| Tools form | `swh_save_tools_action` | `swh_tools_nonce` | `swh_retention_*`, `swh_delete_on_uninstall` |

Never move `swh_delete_on_uninstall` or retention settings to the main form handler — they will reset silently.

---

## Design Tokens

CSS custom properties are defined in `swh-shared.css`, loaded as a dependency of both `swh-admin.css` and `swh-frontend.css`. All tokens use the `--swh-` prefix.

### Token Scales (v3.5.0)

**Shadow**

| Token | Value |
|-------|-------|
| `--swh-shadow-sm` | `0 1px 2px rgba(0,0,0,0.08)` |
| `--swh-shadow-md` | `0 2px 6px rgba(0,0,0,0.12)` |
| `--swh-shadow-lg` | `0 4px 12px rgba(0,0,0,0.16)` |

**Z-index**

| Token | Value | Used by |
|-------|-------|---------|
| `--swh-z-base` | `1` | General stacking |
| `--swh-z-dropdown` | `100` | Dropdowns, popovers |
| `--swh-z-modal` | `200` | Modal dialogs |
| `--swh-z-toast` | `300` | Toast notifications |

**Easing**

| Token | Value |
|-------|-------|
| `--swh-ease-out` | `cubic-bezier(0,0,0.2,1)` |
| `--swh-ease-in-out` | `cubic-bezier(0.4,0,0.2,1)` |

**Dark mode:** token overrides live in a `@media (prefers-color-scheme: dark)` block in `swh-shared.css`, scoped to `.swh-helpdesk-wrapper`. This applies to the **frontend only** — do not add dark mode tokens to `swh-admin.css` (WordPress admin handles its own colour schemes).

---

## Badge System

All status badges use a unified component defined in `swh-shared.css`.

**Base class:** `.swh-badge` — inline-block pill with padding, border-radius, and a hover transition.
**Modifier classes:** `.swh-badge-{slug}` where `slug = sanitize_title($status)`.

| Class | Used for |
|-------|---------|
| `.swh-badge-open` | Open tickets |
| `.swh-badge-in-progress` | In Progress tickets |
| `.swh-badge-resolved` | Resolved tickets |
| `.swh-badge-closed` | Closed tickets |
| `.swh-badge-sla-warn` | SLA warning state |
| `.swh-badge-sla-breach` | SLA breach state |

**PHP pattern:**

```php
$status_slug = sanitize_title( $status );
echo '<span class="swh-badge swh-badge-' . esc_attr( $status_slug ) . '">'
     . esc_html( $status ) . '</span>';
```

---

## Inbound Email Webhook

The webhook endpoint is registered at `POST /wp-json/swh/v1/inbound-email`.

**Authentication:** `Authorization: Bearer <swh_inbound_secret>` header.

**Payload fields:**

| Field | Description |
|-------|-------------|
| `from` | Sender email address |
| `subject` | Email subject (must contain `[TKT-XXXX]`) |
| `body` | Message body (lines beginning with `>` are stripped as quoted reply) |

The handler extracts the ticket ID from `[TKT-XXXX]` in the subject, verifies the sender matches `_ticket_email` via `hash_equals()`, strips quoted reply lines, and inserts a new reply comment.

**Docker note:** Apache strips `Authorization` headers before PHP. In Docker-based test environments, bypass HTTP entirely and call `swh_handle_inbound_email()` directly via `wp eval`.

---

## Testing

The full test suite must pass before any PR is opened or release is cut.

```bash
make test-docker   # full PHP gate inside Docker — preferred (no host PHP needed)
make e2e-docker    # self-contained E2E: up → setup → Playwright → teardown
```

Individual tools:

| Command | Purpose |
|---------|---------|
| `make lint` | PHP syntax check |
| `make phpcs` | WordPress Coding Standards (zero errors) |
| `make phpstan` | Static analysis level 9 |
| `make phpunit` | Unit tests |
| `make semgrep` | SAST security scan |
| `make coverage` | PHPUnit + pcov → `coverage.xml` (Clover) |

**MailHog email assertions:** when `MAILHOG_URL` is set and `WP_MODE=docker`, `expect_email()` calls in the E2E suite assert email delivery via the MailHog API automatically.

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

   `release.yml` fires automatically on the tag push — it builds `simple-wp-helpdesk.zip` and creates the GitHub Release with the ZIP attached.
