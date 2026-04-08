# CLAUDE.md

## Project Overview

Simple WP Helpdesk — a WordPress helpdesk/ticketing plugin. No custom DB tables; uses CPT (`helpdesk_ticket`), comments, post meta, and `wp_options`.

- **Version:** 2.0.0 | **WP:** 5.3+ | **PHP:** 7.4+ | **Repo:** seanmousseau/Simple-WP-Helpdesk

## Repository Structure

```
simple-wp-helpdesk/
├── simple-wp-helpdesk.php              # Bootstrap: constants, requires, lifecycle hooks
├── includes/
│   ├── helpers.php                     # Defaults, statuses, anti-spam, rate limiting
│   ├── class-installer.php             # Activation, deactivation, uninstall, upgrade, CPT
│   ├── class-email.php                 # Template parsing, email sending, HTML wrapping
│   ├── class-ticket.php                # File proxy, uploads, deletion, comment filters
│   └── class-cron.php                  # Auto-close, retention (tickets + attachments)
├── admin/
│   ├── class-settings.php              # Settings page render + save handler
│   ├── class-ticket-editor.php         # Meta boxes, save_post, conversation UI
│   └── class-ticket-list.php           # Columns, sorting, filters, admin styles
├── frontend/
│   ├── class-shortcode.php             # [submit_ticket] + [helpdesk_portal] shortcodes
│   └── class-portal.php                # Client portal view
├── vendor/plugin-update-checker/       # GitHub auto-updater library
├── assets/ (CSS, JS)
└── languages/ (.pot/.po/.mo)
```

Constants: `SWH_PLUGIN_DIR`, `SWH_PLUGIN_URL`, `SWH_PLUGIN_FILE` — use these instead of `__FILE__` in module files.

## Making Changes

1. Place new code in the appropriate module file. Bootstrap should stay thin.
2. All functions: `swh_` prefix. Classes: `SWH_` prefix.
3. New options → `swh_get_defaults()` in `includes/helpers.php`. They auto-register.
4. New email templates → both `_sub` and `_body` variants. Support `{if key}...{endif key}` conditionals.
5. New cron events → register in `swh_activate()`, clear in `swh_deactivate()`.
6. Schema changes → bump version, add upgrade path in `swh_run_upgrade_routine()`.
7. Admin-only code stays in `admin/` (gated by `is_admin()` in bootstrap).
8. i18n: wrap UI strings with `__()`, `esc_html__()`. Do NOT wrap admin-editable defaults.

## Security Conventions

- **Nonces:** All forms use `wp_verify_nonce()`.
- **Capability checks:** `manage_options` for admin, `edit_post` for ticket saves.
- **Sanitization:** `sanitize_text_field()`, `sanitize_email()`, `wp_kses_post()` for HTML fields.
- **Escaping:** `esc_html()`, `esc_attr()`, `esc_url()` on all output.
- **Tokens:** Always `hash_equals()`, never `==`.
- **Files:** Validate path starts with uploads dir. Serve via `swh_serve_file()` proxy, never direct URLs.
- **Rate limiting:** Use `swh_get_client_ip()`, never `$_SERVER['REMOTE_ADDR']` directly.
- **Anti-spam:** Use `swh_check_antispam( $check_captcha )`.

## Common Pitfalls

- **Two settings forms** — main form and Tools form use different nonces. Tools exclusively owns `swh_retention_*` and `swh_delete_on_uninstall`.
- **Settings save on `admin_init`** — `swh_handle_settings_save()` redirects; never put redirects in the render callback.
- **Email on ticket save** — `swh_save_ticket_data()` detects changes and sends emails. Use `swh_send_email()` for all mail, never `wp_mail()` directly.
- **Static cache in `swh_get_defaults()`** — cached per request via `static $defaults`.
- **Comment isolation** — helpdesk replies use `comment_type = 'helpdesk_reply'`. Internal notes have `_is_internal_note = 1` and must be filtered from frontend.
- **Cron offsets** — new crons need different offsets to avoid simultaneous execution.
- **`pre_get_posts` and `meta_key`** — setting it implicitly filters out posts lacking that meta.
- **Technician restriction** — only filters list query (`pre_get_posts`) and direct access (`load-post.php`). Custom `WP_Query` is NOT filtered.
- **Token expiration** — pre-v1.9.0 tickets without `_ticket_token_created` are grandfathered (no expiration).
- **Portal URL** — `swh_get_secure_ticket_link()` returns `false` if `swh_ticket_page_id` unconfigured.

## Release Process

1. Determine version using [SemVer](https://semver.org/).
2. Bump `Version:` header and `SWH_VERSION` in `simple-wp-helpdesk.php`.
3. Update `CHANGELOG.md` ([Keep a Changelog](https://keepachangelog.com/) format).
4. Update `simple-wp-helpdesk/readme.txt` stable tag.
5. Build ZIP: `zip -r releases/vX.Y.Z/simple-wp-helpdesk.zip simple-wp-helpdesk/`
6. PR to `main`, create GitHub Release with ZIP attached.

> ZIP must be named `simple-wp-helpdesk.zip` — WordPress uses it as the plugin slug.

## GitHub Auto-Updater

Uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (bundled in `vendor/`). Initialized in bootstrap with `PucFactory::buildUpdateChecker()`. Branch: `main`. Supports release assets and API token auth.

# Compact instructions

When using compact, focus on: code changes made, errors encountered, current task progress, and file paths being modified. Drop verbose tool output and exploration results.
