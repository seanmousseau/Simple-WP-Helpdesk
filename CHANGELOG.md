# Changelog

All notable changes to Simple WP Helpdesk are documented here.

---

## [1.5] — 2026-03-27

### Added
- **Admin Ticket Creation:** Admins can now enter a client's Name and Email directly from the Add New Ticket screen in the WordPress dashboard.
- **Portal Page Setting:** New `Helpdesk Page` dropdown in the Assignment & Routing tab. Selects the page containing `[submit_ticket]`; used to build the secure portal URL for admin-created tickets.
- **Rate Limiting:** 30-second transient-based cooldown on all frontend actions (close, reopen, reply) to prevent duplicate submissions.
- **Upload Error Logging:** Failed uploads (oversized files, `wp_handle_upload()` errors) now logged via `error_log()` instead of being silently discarded.

### Fixed
- **`swh_delete_on_uninstall` Silent Reset:** The "Delete data on uninstall" checkbox was resetting to `no` whenever any other settings tab was saved. Fixed by giving the Tools form its own nonce (`swh_save_tools_action`) and processing `swh_delete_on_uninstall` exclusively in that handler.
- **Double-Escaped Author Names:** Special characters (apostrophes, accented letters) in technician names were being double-escaped and rendered as HTML entities in the conversation timeline.
- **Retention Cron Active-Ticket Bug:** The attachment retention cron was using `post_date` for its age query, meaning it could delete attachments from recently-updated (but old) tickets. Fixed to use `post_modified`.
- **Multi-Handler Firing:** Sequential `if` blocks for the frontend portal (close / reopen / reply) could theoretically fire multiple handlers in one request. Converted to `if/elseif/elseif`.
- **GitHub Auto-Updater:** Fully resolved the "No valid plugins were found" fatal error that persisted through v1.4.

### Improved
- **Single Source of Truth:** All option defaults now live exclusively in `swh_get_defaults()`. Hardcoded `add_option` calls removed from the upgrade routine.
- **PRG Pattern:** All settings saves now perform a Post/Redirect/Get redirect, preventing double-submission on page refresh. Active tab is preserved via `?swh_tab=` query parameter.
- **Input Validation:** Status, priority, and assignee values are validated against their allowed lists in `swh_save_ticket_data()` before being persisted.
- **Assignee Role Check:** Assigned user ID is verified to belong to the `administrator` or `technician` role before saving.
- **Integer Sanitization:** `swh_autoclose_days`, `swh_max_upload_size`, retention days, and `swh_ticket_page_id` are now saved with `absint()`.
- **JS Reset Button:** Reset-to-default buttons now use a `data-field-name` attribute lookup instead of fragile `previousElementSibling` DOM traversal.
- **`swh_field()` Function:** Moved from a nested closure inside `swh_render_settings_page()` to a top-level named function, eliminating a potential fatal error risk.

### Removed
- **`@set_time_limit(0)`:** Removed from all three cron functions — the micro-batch design makes it unnecessary.
- **Duplicate `wp_head` Script Injection:** Removed redundant `wp_head` action for reCAPTCHA/Turnstile scripts; the shortcode now handles its own script loading with explicit render mode.

---

## [1.4] — 2025

### Improved
- **Smart Folder-Flattening:** The GitHub Updater now automatically detects plugin files nested inside a sub-directory within the release archive and extracts them correctly.
- **Release Asset Priority:** The updater now prioritizes pre-built `.zip` release assets over raw GitHub source archives for cleaner, safer updates.
- **Cache Busting:** Implemented cache-busting on the update checker transient so new releases are detected immediately without waiting for the 12-hour timeout.

---

## [1.3] — 2025

### Security
- Fixed several security vulnerabilities identified by automated scanning tools.

---

## [1.2] — 2025

### Added
- **GitHub Auto-Updater:** The plugin now checks its linked GitHub repository for new releases and delivers them directly to the WordPress dashboard, functioning identically to a wordpress.org-hosted plugin.

### Optimized
- **Background Cron Overhaul:** Auto-Close, Ticket Purge, and Attachment Purge tasks are now split into separate staggered hourly events with strict SQL-level micro-batching (1–2 items per run). Eliminates cURL error 28 timeouts on resource-restricted hosts.
- **Centralized Defaults:** Default configuration values consolidated into a statically cached object, reducing memory usage on every page load and guaranteeing fallback text when settings have not been explicitly saved.

### Security
- Enhanced frontend token validation with `hash_equals()` to protect the client portal against timing attacks.
- Added strict path-traversal prevention to data retention file unlinking functions.
- Added explicit `current_user_can` checks to backend save routines to prevent privilege escalation.

---

## [1.1] — 2025

### Improved
- **Page Builder Compatibility:** Frontend shortcode now uses a fully scoped CSS architecture under `.swh-helpdesk-wrapper`, keeping form elements correctly aligned inside Elementor columns.

### Updated
- Modernized frontend UI with improved padding, focus states, badge designs, and alert box styling.
- Replaced raw inline HTML tags around form inputs with structured `<div>` groups to prevent theme line-height layout conflicts.

---

## [1.0] — 2025 — Initial Release

### Added
- Custom Post Type (`helpdesk_ticket`) for native WordPress backend integration.
- Front-end submission form and secure token-based client portal via `[submit_ticket]` shortcode.
- Tabbed Admin Settings panel: General, Assignment & Routing, Email Templates, Messages, Anti-Spam, Tools.
- Two-way multi-file upload support with JavaScript size and extension validation.
- Technician UI with public reply and private Internal Note capabilities.
- Background automation: auto-close inactive resolved tickets; auto-purge old tickets and attachments.
- GDPR client data purge tool and full uninstallation cleanup routines.
- Anti-spam integrations: Honeypot, Google reCAPTCHA v2, Cloudflare Turnstile.
- Micro-batched cron jobs to prevent server timeouts.
