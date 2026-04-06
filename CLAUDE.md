# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Simple WP Helpdesk is a WordPress plugin that implements a complete ticketing/helpdesk system. It uses no custom database tables, relying entirely on WordPress core data structures (posts, comments, post meta, comment meta, options).

- **Plugin Version:** 1.8
- **WordPress Minimum:** 5.3+
- **PHP Minimum:** 7.4+
- **Author:** SM WP Plugins / seanmousseau
- **GitHub Repo:** seanmousseau/Simple-WP-Helpdesk

---

## Repository Structure

```
Simple-WP-Helpdesk/
├── CLAUDE.md                               # This file
├── CHANGELOG.md
├── README.md                               # End-user documentation
├── LICENSE
├── docs/                                   # User and developer documentation
├── releases/                               # Release ZIP archives (vX.Y/)
└── simple-wp-helpdesk/
    ├── simple-wp-helpdesk.php              # Entire plugin — single file
    ├── assets/
    │   ├── swh-frontend.css                # Frontend shortcode styles
    │   ├── swh-frontend.js                 # Frontend file-upload validation
    │   └── swh-admin.js                    # Settings page JavaScript
    └── languages/                          # i18n .pot/.po/.mo files
```

> **All plugin logic lives in one file:** `simple-wp-helpdesk/simple-wp-helpdesk.php`. The asset files in `assets/` contain only CSS/JS — no PHP logic lives there.

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
| `swh_max_upload_count`            | `5`                                | Max files per upload (0 = unlimited)          |
| `swh_default_assignee`            | `''`                               | User ID of default technician                |
| `swh_fallback_email`              | `''`                               | Fallback alert email if no assignee          |
| `swh_ticket_page_id`              | `0`                                | Page ID containing `[submit_ticket]` shortcode (for admin-created ticket portal links) |
| `swh_email_format`                | `html`                             | `html` or `plain`                            |
| `swh_em_user_lookup_sub`          | `Your Open Tickets`                | Lookup email subject                         |
| `swh_em_user_lookup_body`         | *(see defaults)*                   | Lookup email body with `{ticket_links}`      |
| `swh_msg_success_lookup`          | *(vague confirmation)*             | Lookup success message (anti-enumeration)    |
| `swh_spam_method`                 | `honeypot`                         | `none`, `honeypot`, `recaptcha`, `turnstile` |
| `swh_recaptcha_site_key`          | `''`                               |                                              |
| `swh_recaptcha_secret_key`        | `''`                               |                                              |
| `swh_turnstile_site_key`          | `''`                               |                                              |
| `swh_turnstile_secret_key`        | `''`                               |                                              |
| `swh_retention_attachments_days`  | `0`                                | 0 = disabled                                 |
| `swh_retention_tickets_days`      | `0`                                | 0 = disabled                                 |
| `swh_delete_on_uninstall`         | `no`                               | `yes` or `no`                                |
| `swh_db_version`                  | *(current version)*                | Tracks upgrade state                         |
| Email template keys (14 total)    | See `swh_get_defaults()`           | `swh_{event}_sub` / `swh_{event}_body`       |
| Frontend message keys (7 total)   | See `swh_get_defaults()`           | `swh_success_new`, `swh_err_spam`, etc.      |

---

## WordPress Hooks

### Actions
| Hook                           | Function                         | Purpose                                    |
|--------------------------------|----------------------------------|--------------------------------------------|
| `admin_init`                   | `swh_run_upgrade_routine()`      | Version-based upgrades & option init       |
| `admin_init`                   | `swh_handle_settings_save()`     | Processes settings form submissions (redirects before output) |
| `admin_init`                   | `swh_ensure_technician_caps()`   | One-time capability patch for technician role |
| `init`                         | `swh_serve_file()`               | Proxy endpoint for protected file downloads |
| `init`                         | `swh_load_textdomain()`          | Load plugin translations                   |
| `init`                         | `swh_register_ticket_cpt()`      | Register `helpdesk_ticket` post type       |
| `post_edit_form_tag`           | `swh_add_enctype_to_post_form()` | Adds `enctype="multipart/form-data"`       |
| `admin_menu`                   | `swh_register_settings_page()`   | Adds Settings submenu under Tickets        |
| `admin_notices`                | `swh_admin_helpdesk_page_notice()` | Warns when Helpdesk Page is unconfigured |
| `admin_head`                   | `swh_admin_list_styles()`        | Injects ticket list column styles          |
| `add_meta_boxes`               | `swh_add_ticket_meta_boxes()`    | Registers sidebar + conversation meta boxes|
| `save_post_helpdesk_ticket`    | `swh_save_ticket_data()`         | Handles ticket save, emails, attachments   |
| `pre_get_posts`                | `swh_ticket_list_query()`        | Sorting and filter logic for ticket list   |
| `restrict_manage_posts`        | `swh_ticket_filter_dropdowns()`  | Status/Priority filter dropdowns           |
| `swh_autoclose_event`          | `swh_process_autoclose()`        | Cron: auto-close resolved tickets          |
| `swh_retention_tickets_event`  | `swh_process_retention_tickets()`| Cron: delete old tickets                   |
| `swh_retention_attachments_event` | `swh_process_retention_attachments()` | Cron: delete old attachments      |

### Filters
| Hook                                          | Function                        | Purpose                          |
|-----------------------------------------------|---------------------------------|----------------------------------|
| `manage_helpdesk_ticket_posts_columns`        | `swh_ticket_columns()`          | Defines admin list columns       |
| `manage_edit-helpdesk_ticket_sortable_columns` | `swh_ticket_sortable_columns()` | Marks columns as sortable       |

### Shortcode
| Shortcode         | Function                  | Purpose                              |
|-------------------|---------------------------|--------------------------------------|
| `[submit_ticket]` | `swh_ticket_frontend()`   | Frontend form + secure client portal |

---

## Frontend Shortcode Behavior

The `[submit_ticket]` shortcode has two modes determined by URL parameters:

1. **Submission Form** (no URL params): Renders ticket creation form with anti-spam. Includes a collapsible "Resend my ticket links" lookup form below.
2. **Client Portal** (`?swh_ticket=POST_ID&token=TOKEN`): Validates cryptographic token, then displays ticket details and allows replies/re-open/close.

**Token security:** Generated with `wp_generate_password(20, false)`, validated with `hash_equals()` (timing-attack safe).

**Frontend assets** (`swh-frontend.css` and `swh-frontend.js`) are enqueued only when the shortcode renders, not globally. CSS is scoped under `.swh-helpdesk-wrapper` for page builder compatibility. JS config is passed via `wp_localize_script()` (`swhConfig` object) for CSP compliance — no inline scripts.

---

## Email System

All emails are sent through `swh_send_email()`, which is the single point of change for email behavior. It handles template parsing, HTML/plain-text formatting, and attachment links.

12 email templates (subject + body), all customizable in Settings → Email Templates.

**Email format** is controlled by the `swh_email_format` option (`html` or `plain`). HTML emails use `swh_wrap_html_email()` which produces a table-based layout with auto-linked URLs and attachment links. Plain-text emails append attachments as raw URLs.

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

### Git Branching

- Development branch: `dev`
- Production branch: `main`
- GitHub auto-updater checks GitHub releases; tag names must match the plugin `Version:` header.

### Making Changes

1. All plugin code lives in `simple-wp-helpdesk/simple-wp-helpdesk.php`. Do not create new PHP files unless there is a compelling reason (and even then, require them from the main file).
2. Follow existing function naming: prefix with `swh_`.
3. Add new options to `swh_get_defaults()` — `swh_get_all_option_keys()` returns `array_keys( swh_get_defaults() )`, so no separate update is needed.
4. When adding new email templates, add both `_sub` and `_body` variants.
5. When adding new cron events, register them in `swh_activate()` and clear them in `swh_deactivate()`.
6. After modifying settings structure, bump the version string and add an upgrade path in `swh_run_upgrade_routine()` if needed.

---

## Release Process

Follow these steps every time a new version is released:

1. **Bump the version** in `simple-wp-helpdesk/simple-wp-helpdesk.php`:
   - Update the `Version:` field in the plugin header comment.
   - Update `define( 'SWH_VERSION', 'X.Y' )`.

2. **Update `CHANGELOG.md`** with a new versioned entry covering all changes.

3. **Update any affected documentation** in `docs/` and `README.md`.

4. **Build the release ZIP** and commit it under `releases/vX.Y/`:
   ```bash
   mkdir -p releases/vX.Y
   zip -r releases/vX.Y/simple-wp-helpdesk.zip simple-wp-helpdesk/
   ```
   The archive must contain `simple-wp-helpdesk/simple-wp-helpdesk.php` at the root level.

5. **Close any GitHub issues** that are addressed by the release.

6. **Commit and push** all changes to the `dev` branch, then **open a PR** to `main`.

7. **Create a GitHub Release**:
   - Tag name must **exactly match** the `Version:` header (e.g. `1.6`) — this is what `SWH_GitHub_Updater` compares against. A `v` prefix is fine (`v1.6`) as the updater strips it.
   - **Attach `simple-wp-helpdesk.zip` as a release asset.** This is critical — without an attached asset the updater falls back to the raw source archive, which includes the entire repo and is unreliable.
   - The updater checks `assets[0]->browser_download_url` before falling back to `zipball_url`.

> **Why the ZIP must be named `simple-wp-helpdesk.zip`:** WordPress uses the ZIP filename as the plugin slug during installation. A versioned filename (e.g. `simple-wp-helpdesk-v1.5.zip`) would cause WordPress to treat it as a different plugin, breaking the upgrade path.

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
| Assignment & Routing   | Default assignee, fallback email, helpdesk portal page           |
| Email Templates        | Email format toggle, 12 subject+body templates with placeholder reference |
| Messages               | 7 user-facing success/error messages                             |
| Anti-Spam              | Method selector + API keys for reCAPTCHA/Turnstile               |
| Tools                  | Data retention, uninstall settings, GDPR purge, factory reset    |

## Admin Ticket Creation

Admins can create tickets directly from the WP admin Add New screen. The sidebar meta box shows editable **Client Name** and **Client Email** fields. On first save:
- `_ticket_uid`, `_ticket_token`, and `_ticket_url` are auto-generated.
- `_ticket_url` is derived from the **Helpdesk Page** setting (`swh_ticket_page_id`).
- Optionally sends the standard new-ticket confirmation email to the client (checkbox in the sidebar).
- No email is sent if client email is empty or the checkbox is unchecked.

## Technician Role

The `technician` role is created on activation with capabilities: `read`, `edit_posts`, `edit_others_posts`, `edit_published_posts`, `publish_posts`, `delete_posts`, `upload_files`. These map to the CPT's `capability_type => 'post'`.

- `swh_ensure_technician_caps()` runs once on `admin_init` (flagged by `swh_tech_caps_v2` option) to patch existing installs that were created before the full capability set was defined.
- When adding new capabilities, use a similar standalone one-time migration with its own flag — do **not** rely solely on the version-gated upgrade routine, as it may have already executed.

## Common Pitfalls

- **Don't use `==` for token comparison** — always use `hash_equals()`.
- **Don't forget `enctype="multipart/form-data"`** on any form handling file uploads.
- **Static cache in `swh_get_defaults()`** — if you need fresh option data in a long-running process, be aware defaults are cached per request.
- **Cron offset times** — if adding a new cron, use a different offset to avoid simultaneous execution with existing jobs.
- **Comment visibility** — internal notes (`_is_internal_note = 1`) must be filtered out of the frontend client view.
- **Attachment arrays** — stored as serialized PHP arrays; may exist as a single URL string (legacy format). The `swh_delete_file_by_url()` function handles both.
- **Email on ticket save** — `swh_save_ticket_data()` detects what changed (status, reply, assignment) and sends appropriate emails. Adding new meta fields must account for the save lifecycle to avoid spurious emails. Use `swh_send_email()` for all outgoing mail — never call `wp_mail()` directly.
- **Two settings forms** — The main form and the Tools/Retention form use different nonces (`swh_save_settings_action` vs `swh_save_tools_action`). The Tools form exclusively owns `swh_retention_*` and `swh_delete_on_uninstall`; never add those to the main form save handler.
- **Settings save must happen on `admin_init`** — form processing with `wp_safe_redirect()` lives in `swh_handle_settings_save()`, hooked to `admin_init`. Never put redirects inside the page render callback — WordPress has already sent output by then.
- **Admin-created tickets have no portal URL until `swh_ticket_page_id` is configured** — `swh_get_secure_ticket_link()` returns `false` if `_ticket_url` is empty. Check the return value before using it.
- **`swh_get_defaults()` is the single source of truth** — all option defaults live here. Add new options here and they will automatically be registered by the upgrade routine, included in factory reset, and cleaned up on uninstall.
- **`pre_get_posts` and `meta_key`** — setting `meta_key` on a query implicitly filters out posts that lack that meta. Only use it for explicit user-initiated sorts, not as a default.
- **File uploads go to `uploads/swh-helpdesk/`** — protected by `.htaccess`. All attachment URLs must be displayed via `swh_get_file_proxy_url()` and served through `swh_serve_file()`. Never link directly to upload URLs.
- **i18n: all new UI strings must be wrapped** — use `__()`, `esc_html__()`, `esc_attr__()` with text domain `'simple-wp-helpdesk'`. Do NOT wrap admin-editable defaults (email templates, messages). JS strings go through `wp_localize_script()` in the `i18n` key of `swhConfig`.
- **Anti-spam helper** — use `swh_check_antispam( $check_captcha )` for spam verification. Pass `true` for full CAPTCHA check, `false` for honeypot only.
- **Rate-limiting uses `swh_get_client_ip()`** — never use `$_SERVER['REMOTE_ADDR']` directly. The helper handles Cloudflare and proxy headers.
