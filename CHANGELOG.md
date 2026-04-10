# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
starting from the next release after 1.8.

---

## [Unreleased]

---

## [2.3.0] — 2026-04-10

### Added
- **My Tickets Dashboard (#111):** Portal page without a ticket token now shows a table of open tickets for logged-in WordPress users (with secure links) or the ticket lookup form for guests — replacing the previous "No ticket specified" error box.
- **Original Attachment Filenames (#112):** Uploaded files now display their original filename as the link label (stored as `_swh_attachment_orignames` / `_swh_reply_orignames` meta), instead of the server-mangled name.
- **XHR Upload Progress Indicator (#113):** A progress bar (`swh-progress-bar`) appears during file attachment uploads on the ticket submission form, with the submit button disabled until the upload completes.
- **CSAT Prompt on Ticket Close (#116):** After a client closes a ticket via the portal, a 1–5 star satisfaction widget is shown. Ratings are stored in `_ticket_csat` post meta via an AJAX handler. Clients can skip to dismiss.
- **Humanized Timestamps (#117):** Reply timestamps in the client portal now display as relative strings ("3 hours ago", "Yesterday", etc.) using a `<time datetime>` element; the absolute date is preserved in the `title` tooltip.
- **`[submit_ticket]` Shortcode Attributes (#119):** Both `[submit_ticket]` and `[helpdesk_portal]` shortcodes now accept `show_priority` (yes/no), `default_priority`, `default_status`, and `show_lookup` (yes/no) attributes for per-page customisation.
- **Playwright/pytest Test Suite:** Full browser-based end-to-end suite covering 28 scenarios via pytest-playwright. Scenarios cover: admin auth, plugin verification, ticket submission, admin management, client portal, status transitions, internal notes, access control, bulk actions, settings persistence, canned responses, multi-technician workflow, admin search/filters, file attachments, portal token security, XSS escaping, subscriber access control, and rate limiting. Run with `pytest testing/scripts/test_helpdesk_pw.py`.

### Changed
- **Resolved → Close CTA Layout (#118, #120):** On the portal, the "Close Ticket" prompt for resolved tickets is now a prominent two-part block: a primary CTA card with the Close button and a de-emphasised "Still need help? Reply below ↓" link, replacing the previous single alert box.
- **PHPStan Level 6 → 8 (#143, #144):** Static analysis level raised to 8. Added `is_array()` / `instanceof WP_Comment` guards on all `get_comments()` / `get_pages()` calls; null guards on `preg_replace*` / `ob_get_clean` / `wp_parse_url` return values.

### Fixed
- **Shortcode Detection — `has_shortcode()` (#186):** Page dropdown in Settings now uses WordPress's `has_shortcode()` for reliable detection (handles shortcode attributes), replacing the previous `strpos` loop.
- **Canned Response Insert in Ticket Editor (#182):** `swh-admin.js` was not enqueued on `post.php` / `post-new.php`, causing the canned response Insert button to be non-functional in the ticket editor.
- **Canned Response Cleanup on Reset/Uninstall (#182):** `swh_canned_responses` was not registered in `swh_get_defaults()`, so factory reset and plugin uninstall did not clean up saved canned responses.
- **Bulk Status Change — `_resolved_timestamp` Sync (#182):** Bulk status updates now sync `_resolved_timestamp` meta (set when a ticket enters resolved, cleared on re-open) to match the behaviour of `swh_save_ticket_data()`.
- **Canned Response Input Sanitization (#182):** `wp_unslash()` now applied to canned response title and body POST arrays before `sanitize_text_field()`.
- **Duplicate Icon Constants:** Duplicate `SWH_ICON_1X` / `SWH_ICON_2X` / `SWH_MENU_ICON` define block introduced by a rebase conflict removed from the plugin bootstrap.

---

## [2.2.0] — 2026-04-09

### Added
- **Bulk Status Change (#107):** New bulk action on the ticket list — "Set Status: {Status}" entries for every configured status. Updates `_ticket_status` meta on all selected tickets and shows a confirmation notice.
- **Shortcode Indicator in Page Dropdown (#108):** The Helpdesk Page selector in Settings now annotates each page with the shortcode it contains (e.g. `— [helpdesk_portal]`), making it easy to pick the correct page at a glance.
- **Canned Responses (#110):** New Canned Responses tab in Settings for managing pre-written reply templates (title + body). A picker above the ticket editor reply textarea inserts the selected template body at click.
- **Plugin Branding Assets (#177, #178, #179):** CDN-hosted plugin icons (`icon-128x128.png`, `icon-256x256.png`) wired into the admin menu CPT icon, Settings page header, and the Plugin Update Checker info response for the WordPress update UI.

### Changed
- **PHPStan Level 5 → 6 (#141, #142):** Static analysis level raised to 6. Return type and parameter type declarations verified against szepeviktor/phpstan-wordpress stubs; zero errors at level 6.

### Fixed
- **Missing Icon Constants (#177-bug):** `SWH_ICON_1X`, `SWH_ICON_2X`, and `SWH_MENU_ICON` were used in `class-installer.php` and `class-settings.php` but never defined at runtime. All four CDN constants now defined in the plugin bootstrap and in `phpstan-bootstrap.php`.
- **CDP Test: Icon-in-Transient (#180):** `wp_update_plugins()` is now called before the transient check so the icon data is primed before the assertion.
- **CDP Test: `curl` Availability Guard (#181):** CDN reachability checks now call `shutil.which("curl")` and skip gracefully when `curl` is not in `PATH`.

---

## [2.1.0] — 2026-04-09

### Added
- **Extensibility Hooks (#171):** Ten new `apply_filters()` / `do_action()` hooks for customizing plugin behaviour without modifying core files: `swh_ticket_statuses`, `swh_ticket_priorities`, `swh_rate_limit_ttl`, `swh_allowed_file_types`, `swh_submission_data`, `swh_pre_ticket_create`, `swh_ticket_created`, `swh_email_headers`, `swh_parse_template`, `swh_autoclose_threshold`.
- **ARIA Tab Interface on Settings Page (#161):** Settings navigation upgraded from `<a>` links to fully keyboard-navigable ARIA tab widgets (`role="tab"`, `role="tabpanel"`, `aria-selected`, `aria-controls`, `aria-labelledby`). Supports Arrow, Home, and End key navigation.
- **Accessible Form Labels (#163, #165):** All admin ticket editor fields and frontend submission form inputs now have explicit `<label for>` / `id` associations.
- **ARIA Live Regions (#162, #166):** Conversation log has `role="log"`; success messages use `role="status"` (polite); error messages use `role="alert"` (assertive).
- **Screen-Reader Honeypot (#159):** Honeypot wrapper divs now carry `aria-hidden="true"` so assistive technologies skip invisible fields.
- **`aria-expanded` on Lookup Toggle (#159):** The "Resend my ticket links" toggle link exposes its open/closed state to assistive technologies via `aria-expanded`.
- **`swh-admin.css` Asset (#149):** Admin badge and column styles extracted from inline PHP into a dedicated enqueued stylesheet.
- **Inline Docs on All Hook Registrations (#164, #169):** All `add_action()`, `add_filter()`, `add_shortcode()`, and `register_*_hook()` calls have inline docblock comments.
- **PHPDoc on All Functions (#154, #155, #157):** Complete `@param`, `@return`, and `@since` docblocks added to all functions and file-level docblocks across all 11 PHP files.

### Changed
- **JavaScript Modernisation (#150, #153):** All `var` declarations replaced with `const`/`let` in `swh-admin.js` and `swh-frontend.js`.
- **CSS Formatting (#149):** Both `swh-frontend.css` and `swh-admin.css` use consistent multi-line rule format.
- **PHP Code Style (#151, #152):** ABSPATH guard brace style normalised; mixed indentation corrected across all module files via `phpcbf`.
- **HTML Audit (#156):** Self-closing void elements, attribute quoting, and `echo` chain indentation normalised.

### Fixed
- **Focus Styles (#167, #168):** Removed `outline: none` from `.swh-form-control:focus`; added compliant 2px focus rings on form controls and buttons in both frontend and admin stylesheets.
- **PHPStan Level-5 Type Errors (#phase-17):** Resolved 27 type errors introduced by stricter analysis — `comment_ID` casts, `str_pad` string coercion, redundant truthiness guards, and `is_wp_error` check on `wp_insert_post` return.
- **PHPCS Zero Warnings (#158):** All PHP_CodeSniffer warnings resolved; `phpcs:ignore` directives moved to the correct lines; `@var` type annotations converted to non-docblock style.

---

## [2.0.0] — 2026-04-07

### Changed
- **Modular File Structure (#61):** Refactored single-file architecture into `includes/`, `admin/`, and `frontend/` directories. Bootstrap file is now a thin loader (~55 lines). Admin files only loaded in admin context. No behavioral changes.
- **Replaced GitHub Updater (#64):** Replaced custom `SWH_GitHub_Updater` class (~130 lines) with the [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) library. Gains retry with backoff, release asset detection, and GitHub API token support.

### Added
- **Conditional Email Template Blocks (#65):** Email templates now support `{if key}...{endif key}` syntax for conditionally including sections. Unreplaced placeholders are automatically cleaned up. Default templates updated to use conditionals for optional fields.
- **`[helpdesk_portal]` Shortcode (#59):** New optional shortcode for placing the client portal on a dedicated page, separate from the submission form. The existing `[submit_ticket]` shortcode continues to work identically.
- **WordPress-Compliant `readme.txt` (#75):** Added `readme.txt` following the WordPress plugin readme standard for proper plugin directory display.

### Fixed
- **Attachment Display in Emails (#76):** Attachment links in emails now show clean filenames (e.g., `photo.jpg`) instead of raw query parameters, in both HTML and plain-text formats.
- **Settings Save Redirect (#77):** Settings save now correctly redirects back to the settings page and active tab instead of the ticket list.
- **Client Reopen — Silent Failure:** Submitting the reopen form with an empty textarea and no attachments silently did nothing — no status change, no error. Reopen now always succeeds (consistent with close, which requires no explanation). Audit comment adapts: includes the reason if provided, otherwise logs `TICKET RE-OPENED BY CLIENT`.
- **Client Reopen — Rate Limit Conflict:** All three portal actions (close, reply, reopen) shared one rate limit key per ticket. Closing a ticket consumed the 30-second window, blocking an immediate reopen attempt. Each action now has its own key (`portal_close_`, `portal_reopen_`, `portal_reply_`).
- **Helpdesk Page Setting Not Applied to Links:** `swh_get_secure_ticket_link()` read the stale `_ticket_url` post meta (written at ticket creation) instead of the live `swh_ticket_page_id` setting. Changing the page in Settings had no effect on generated portal links. The function now resolves the URL from the setting directly via `get_permalink()`, falling back to stored meta only when no page is configured.
- **Wrong Portal URL in New-Ticket Emails:** `$data['ticket_url']` was built before `_ticket_token` was written to post meta, so `swh_get_secure_ticket_link()` always returned `false` and the fallback hardcoded `get_permalink()` (the `[submit_ticket]` page). The token is now stored first, so the correct portal page is used in all outbound emails.

### Removed
- `SWH_GitHub_Updater` class (replaced by plugin-update-checker library).

---

## [1.9.0] — 2026-04-06

### Added
- **Portal Token Expiration (#58):** Configurable portal link TTL (default 90 days, 0 = never). Expired links show a clear message directing clients to the lookup form. Tokens auto-rotate on each lookup resend, providing self-service link revocation.
- **Persistent Rate Limiting (#62):** Rate limits now stored in `wp_options` instead of transients, surviving cache flushes and working across multi-server deployments. Global per-IP keying prevents distributed ticket attacks. Expired entries cleaned up via existing cron.
- **Cron Job Locking (#52):** Transient-based locking prevents duplicate processing when cron fires multiple times simultaneously on high-traffic sites.
- **Comment Isolation (#57):** Helpdesk replies now use `comment_type = 'helpdesk_reply'`, preventing leakage into WordPress comment widgets, RSS feeds, and sitewide comment counts. Includes one-time upgrade migration for existing comments.
- **Meta Cache Priming (#53):** Admin ticket list and cron loops now batch-prime post meta via `update_meta_cache()`, eliminating N+1 query patterns.
- **Restrict Technicians to Assigned Tickets (#60):** New "Restrict Technicians" checkbox in Assignment & Routing settings. When enabled, technicians only see tickets assigned to them. Admins always see all tickets. Direct URL access to unassigned tickets is also blocked.
- **Inline File Validation Errors (#54):** Frontend file upload validation now shows inline error messages below the input instead of blocking browser `alert()` dialogs.

### Fixed
- **Missing `is_wp_error()` Checks (#49):** Added return value guards on `wp_insert_post()` and all 7 `wp_insert_comment()` call sites to prevent silent data corruption on insertion failure.
- **Header Injection in File Proxy (#50):** `Content-Disposition` filename now strips `\r`, `\n`, `"`, and `;` characters to prevent HTTP header injection.
- **Inconsistent `intval()` (#51):** Frontend portal ticket ID sanitization changed from `intval()` to `absint()` to match the pattern used everywhere else.
- **Extra Closing `</div>` (#55):** Removed duplicate closing tag in the invalid-token error output that corrupted page DOM structure.

### Security
- **Token Expiration (#58):** Portal links no longer grant permanent access. Configurable TTL with auto-rotation on lookup.
- **Header Injection (#50):** Defense-in-depth filename sanitization in file proxy endpoint.
- **Persistent Rate Limiting (#62):** Rate limits survive cache flushes and work across load-balanced environments.

### Changed
- **Thorough Uninstall (#63):** "Delete data on uninstall" now also clears cron hooks, removes upload protection files/directory, deletes rate-limit options and transients, removes migration flags, and reassigns technician users to the default role before removing the role.

---

## [1.8] — 2026-04-06

### Added
- **Internationalization (i18n) (#43):** All ~85 hardcoded UI strings wrapped with WordPress i18n functions. Text domain `simple-wp-helpdesk` registered with `load_plugin_textdomain()`. JS alert strings localized via `wp_localize_script()`. `languages/` directory created for `.pot`/`.po`/`.mo` files.
- **Client Ticket Lookup (#44):** "Resend my ticket links" form on the frontend allows clients to enter their email and receive links to all open tickets. 60-second rate limit per IP. Deliberately vague success message prevents email enumeration. New email template (`swh_em_user_lookup_sub`/`_body`) with `{ticket_links}` placeholder.
- **Anti-Spam on Portal Forms (#41):** Reply form now supports honeypot, reCAPTCHA, and Turnstile. Reopen form supports honeypot. New `swh_check_antispam()` helper consolidates all spam verification logic. Existing ticket form refactored to use the helper.

### Security
- **CDN/Proxy-Aware Rate Limiting (#40):** New `swh_get_client_ip()` helper checks `HTTP_CF_CONNECTING_IP` (Cloudflare), `HTTP_X_FORWARDED_FOR` (first IP), then `REMOTE_ADDR`. Both rate-limit locations updated.
- **Protected File Uploads (#42):** Attachments now upload to `uploads/swh-helpdesk/` with `.htaccess` deny and `index.php` guard. New `swh_serve_file()` proxy endpoint validates portal token or admin capability before streaming files. All attachment display and email links use proxy URLs. Existing attachments in `uploads/YYYY/MM/` continue to work.

---

## [1.7] — 2026-04-05

### Added
- **HTML Email Support (#26):** All outgoing emails now use a clean, table-based HTML layout with clickable links. New `Email Format` setting in the Email Templates tab allows toggling between HTML and Plain Text. Defaults to HTML.
- **Admin Ticket List Columns (#29):** The All Tickets admin screen now displays Ticket #, Status (color-coded badge), Priority, Assigned To, Client, and Date columns. Status and Priority filter dropdowns and sortable columns included.
- **Admin Notice for Unconfigured Helpdesk Page (#38):** A dismissible warning appears on ticket screens when the Helpdesk Page setting is not configured, preventing silent misconfiguration of portal URLs.

### Fixed
- **Sidebar Attachment Labels (#31):** The "All Attachments" list in the admin sidebar meta box now displays actual filenames instead of generic "File 1, File 2" labels.
- **`requires_php` Mismatch (#32):** The GitHub Updater plugin info popup now correctly reports `Requires PHP: 7.4` (was hardcoded to `7.2`).
- **Frontend Priority Validation (#33):** Ticket priority submitted via the frontend form is now validated against the configured priority list, preventing arbitrary values from being stored.
- **Missing `$_FILES` Check (#34):** Frontend ticket submission no longer triggers a PHP notice when no file attachment is included.
- **Legacy Attachment Retention (#39):** The attachment retention cron now handles legacy single-URL string format and checks `_ticket_attachment_url` / `_ticket_attachment_id` meta keys, preventing orphaned files.

### Changed
- **Email Helper Refactor (#36):** All 14 `wp_mail()` call sites consolidated into a single `swh_send_email()` helper function, reducing ~100 lines of duplicate code and providing a single point of change for email behavior.
- **Frontend CSS Scoped to Shortcode (#30):** `swh-frontend.css` is now enqueued only when the `[submit_ticket]` shortcode is rendered, eliminating an unnecessary HTTP request on all other pages.
- **Frontend JS Extracted (#35):** Client-side file validation JavaScript extracted from inline `<script>` to `assets/swh-frontend.js` and loaded via `wp_enqueue_script()` with `wp_localize_script()` for CSP compliance.

### Closed
- **#25:** Closed as duplicate of #29.
- **#37:** `SWH_GitHub_Updater` confirmed instantiated at line 1873. Closed as not applicable.

---

## [1.6] — 2026-04-05

### Added
- **Reassignment Email:** When a ticket is assigned or reassigned to a technician, that technician now receives an email notification. Template configurable under Settings → Email Templates.
- **Max Files Per Upload:** New `Max Files Per Upload` setting (default: 5) in the General tab. Enforced both server-side and via client-side JS validation. Set to 0 for unlimited.
- **Rate Limiting on New Ticket Submission:** The 30-second transient-based rate limit (previously only applied to portal actions) now also applies to initial ticket submissions, keyed by IP.

### Fixed
- **Plugin Header Version Mismatch:** `Version:` header comment now correctly reads `1.6`, matching `SWH_VERSION` (was showing `1.4` since v1.5).

### Changed
- **`wp_mail()` Failure Logging:** All email sends now check the return value and log failures via `error_log()` — consistent with the upload error logging added in v1.5.
- **Real Attachment Filenames:** Attachments in both the admin conversation meta box and the frontend client portal now display the actual filename (via `basename()`) instead of generic "File 1", "File 2" labels.
- **Enqueued Frontend CSS:** Frontend stylesheet extracted from inline `<style>` block to `assets/swh-frontend.css` and loaded via `wp_enqueue_style()` for browser caching and CSP compatibility.
- **Enqueued Admin JS:** Settings page JavaScript extracted from inline `<script>` block to `assets/swh-admin.js` and loaded via `wp_enqueue_script()`.
- **Enqueued Anti-Spam Scripts:** reCAPTCHA and Turnstile CDN scripts now registered via `wp_enqueue_script()` and `wp_add_inline_script()` instead of raw `echo '<script>'` output.
- **CPT Labels:** Added missing `register_post_type()` labels: `all_items`, `view_item`, `search_items`, `not_found`, `not_found_in_trash`, `menu_name`.
- **`add_role()` Idempotency:** `swh_activate()` now wraps `add_role()` with a `get_role()` existence check.
- **`posts_per_page` Parameter:** Replaced legacy `numberposts` parameter with `posts_per_page` in all `get_posts()` calls (7 occurrences).
- **PHP Minimum Bumped to 7.4:** Plugin header updated from `Requires PHP: 7.2` to `Requires PHP: 7.4`, aligning with WordPress core's minimum.

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

### Changed
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

## [1.4] — 2026-03-13

### Changed
- **Smart Folder-Flattening:** The GitHub Updater now automatically detects plugin files nested inside a sub-directory within the release archive and extracts them correctly.
- **Release Asset Priority:** The updater now prioritizes pre-built `.zip` release assets over raw GitHub source archives for cleaner, safer updates.
- **Cache Busting:** Implemented cache-busting on the update checker transient so new releases are detected immediately without waiting for the 12-hour timeout.

---

## [1.3] — 2026-03-13

### Security
- Fixed several security vulnerabilities identified by automated scanning tools.

---

## [1.2] — 2026-03-13

### Added
- **GitHub Auto-Updater:** The plugin now checks its linked GitHub repository for new releases and delivers them directly to the WordPress dashboard, functioning identically to a wordpress.org-hosted plugin.

### Changed
- **Background Cron Overhaul:** Auto-Close, Ticket Purge, and Attachment Purge tasks are now split into separate staggered hourly events with strict SQL-level micro-batching (1–2 items per run). Eliminates cURL error 28 timeouts on resource-restricted hosts.
- **Centralized Defaults:** Default configuration values consolidated into a statically cached object, reducing memory usage on every page load and guaranteeing fallback text when settings have not been explicitly saved.

### Security
- Enhanced frontend token validation with `hash_equals()` to protect the client portal against timing attacks.
- Added strict path-traversal prevention to data retention file unlinking functions.
- Added explicit `current_user_can` checks to backend save routines to prevent privilege escalation.

---

## [1.1] — 2026-03-12

### Changed
- **Page Builder Compatibility:** Frontend shortcode now uses a fully scoped CSS architecture under `.swh-helpdesk-wrapper`, keeping form elements correctly aligned inside Elementor columns.
- Modernized frontend UI with improved padding, focus states, badge designs, and alert box styling.
- Replaced raw inline HTML tags around form inputs with structured `<div>` groups to prevent theme line-height layout conflicts.

---

## [1.0] — 2026-03-12

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

---

[Unreleased]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.1.0...HEAD
[2.3.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/v1.9.0...v2.0.0
[1.9.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.8...1.9.0
[1.8]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.7...1.8
[1.7]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.6...1.7
[1.6]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.5...1.6
[1.5]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.4...1.5
[1.4]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.3...1.4
[1.3]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.2...1.3
[1.2]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.1...1.2
[1.1]: https://github.com/seanmousseau/Simple-WP-Helpdesk/compare/1.0...1.1
[1.0]: https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/tag/1.0
