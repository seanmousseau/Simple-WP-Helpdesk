=== Simple WP Helpdesk ===
Contributors: seanmousseau
Tags: helpdesk, tickets, support, customer service, ticketing
Requires at least: 5.3
Tested up to: 6.7
Stable tag: 3.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight ticketing system built natively for WordPress. No third-party services — all data stays on your server.

== Description ==

Simple WP Helpdesk is a full-featured helpdesk and ticketing system that runs entirely on WordPress core data structures. No custom database tables, no external services, no subscriptions.

**Key Features:**

* Custom Post Type for tickets — no custom database tables
* Frontend submission form and secure token-based client portal via `[submit_ticket]` shortcode
* Optional `[helpdesk_portal]` shortcode for standalone portal pages
* Tabbed settings panel with 7 tabs (General, Assignment & Routing, Email Templates, Messages, Anti-Spam, Canned Responses, Tools)
* Bulk "Set Status" action on the ticket list — update multiple tickets at once
* Multi-file upload with client-side validation and configurable size/count limits
* Technician role with optional assignment restriction
* HTML and plain-text email notifications — 14 fully customizable templates with dynamic placeholders and conditional blocks
* Background automation — auto-close resolved tickets and scheduled data retention
* Anti-spam on all forms — honeypot, Google reCAPTCHA v2, and Cloudflare Turnstile
* CDN/proxy-aware rate limiting (persistent, survives cache flushes)
* Protected file uploads served through a proxy endpoint
* Token expiration with configurable TTL and auto-rotation for portal links
* "Resend my ticket links" client lookup form
* Internationalization (i18n) ready with full text-domain support
* GDPR tools — per-email data purge, data retention policies, and full uninstall cleanup
* GitHub auto-updater — new releases delivered directly to the WordPress dashboard

== Installation ==

1. Download `simple-wp-helpdesk.zip` from the [latest release](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/latest).
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and install the ZIP.
3. Activate the plugin. A **Tickets** menu appears in the dashboard.
4. Create a page (e.g., "Support") and add the shortcode `[submit_ticket]`.
5. Go to **Tickets > Settings > Assignment & Routing** and set the **Helpdesk Page** to that page.

== Frequently Asked Questions ==

= Does this plugin create custom database tables? =

No. Simple WP Helpdesk uses WordPress core data structures exclusively — custom post types, comments, post meta, comment meta, and options.

= Can clients view and reply to their tickets? =

Yes. Each ticket has a secure, token-based portal link. Clients can view conversation history, reply, upload attachments, and close or reopen tickets.

= Does it work with page builders? =

Yes. The `[submit_ticket]` shortcode is scoped under `.swh-helpdesk-wrapper` for compatibility with Elementor, Divi, Beaver Builder, and others.

= What anti-spam options are available? =

Honeypot (default, no config needed), Google reCAPTCHA v2, and Cloudflare Turnstile. All three protect the submission form, lookup form, and portal reply form.

= Can technicians be restricted to only their assigned tickets? =

Yes. Enable "Restrict Technicians" in Settings > Assignment & Routing. Technicians will only see tickets assigned to them. Admins always see all tickets.

== Screenshots ==

1. Frontend ticket submission form
2. Client portal with conversation history
3. Admin ticket editor with sidebar meta box
4. Settings page — General tab
5. Settings page — Email Templates tab

== Changelog ==

= 3.3.0 =
* Added: RTL stylesheet (`swh-rtl.css`) with directional overrides; loaded conditionally via `is_rtl()` (#125)
* Added: WCAG 2.2 AA heading hierarchy — screen-reader h2 on submission form; h2→h4 skip fixed in portal close CTA (#170)
* Added: Responsive breakpoints for admin ticket list, meta boxes, settings tabs, and frontend form (#251)
* Added: Empty-state messages for report charts when no ticket data is available (#254)
* Added: Styled file input — removed last inline style; CSS class-driven (#255)
* Added: Real upload progress bar via `XMLHttpRequest.upload.onprogress` (#256)
* Added: Unsaved-changes `beforeunload` warning on the settings page (#257)
* Changed: Ticket editor conversation bubbles use semantic CSS classes instead of inline styles; notes visually distinct from public replies (#253)
* Changed: Additional design tokens added to `swh-shared.css` — surface, focus, success-accent, text-secondary, bg-highlight, track (#250)
* Changed: Remaining hard-coded hex values in `swh-admin.css` and `swh-frontend.css` replaced with design tokens
* Fixed: Ticket editor merge form AJAX network errors now display an inline error message (#252)
* Changed: `SWH_VERSION` bumped to `3.3.0`

= 3.2.0 =
* Added: `make test-docker` — full gate (lint/PHPCS/PHPStan/PHPUnit/Semgrep) inside the phptest container; no host PHP or semgrep required (#292)
* Added: `make e2e-docker` — self-contained E2E; spins up Docker stack, runs Playwright suite with MailHog, tears down in one command (#294)
* Added: `make coverage` — PHPUnit coverage report (Clover) via pcov (#301)
* Added: MailHog automated email assertions in E2E suite (#288); `mailhog/mailhog:v1.0.1` service added to `docker-compose.test.yml` (#295)
* Added: `docker/mailhog-smtp.php` MU-plugin routes `wp_mail()` through MailHog SMTP when `MAILHOG_SMTP_HOST` is set (#296)
* Added: `coverage.yml` CI workflow — PHP 8.2 + pcov, uploads Clover report to Codecov (#301)
* Added: `release.yml` CI workflow — builds ZIP and creates GitHub Release on `v*.*.*` tag push; replaces manual ZIP step (#302)
* Added: Pre-push hook auto-detects Docker; prefers `make test-docker`, falls back to `make test` (#293)
* Fixed: Expired portal token page now shows an error alert with a direct link to the ticket lookup form instead of a blank error (#258)
* Changed: Status badges use modern pill styling (`border-radius:9999px`) replacing the legacy Bootstrap-era alert colours (#259)
* Changed: CSS custom properties extracted to `swh-shared.css` — single source for colour, spacing, radius, and font tokens (#260)
* Changed: CSAT star widget upgraded with `role="radiogroup"` / `role="radio"` ARIA semantics and keyboard navigation (#262)
* Changed: Sortable admin column headers receive `aria-sort="none"` via inline script (#263)
* Changed: Admin menu unread badge has `aria-live="polite"` and descriptive `aria-label` (#264)
* Changed: All font sizes and spacing now reference design tokens — `--swh-font-*`, `--swh-space-*` (#265, #266)
* Changed: Active settings tab position persisted in `sessionStorage` and restored on reload/form submission (#267)
* Changed: Progress bar indeterminate animation wrapped in `prefers-reduced-motion: no-preference` (#268)
* Changed: CSAT star rating uses `clamp(22px, 5vw, 28px)` for responsive sizing instead of fixed `28px` (#269)
* Changed: Honeypot fields use `clip-path:inset(50%)` off-screen technique instead of `left:-9999px` (#270)
* Changed: Merge Ticket section collapsed by default with a `max-height`/`opacity` expand transition (#271)
* Changed: Ticket lookup form toggle animates with `max-height`/`opacity` slide instead of instant show/hide (#272)
* Changed: File attachment inputs wrapped in a drag-and-drop zone accepting `dragover`/`drop` events (#273)
* Changed: After file selection/drop, UI displays per-file type icon + filename + human-readable size (#274)
* Changed: CSAT rating prompt auto-dismisses after 60 seconds (#275)
* Changed: `SWH_VERSION` bumped to `3.2.0`

= 3.1.0 =
* Fixed: Conversation timestamps now use `wp_date()` with UTC source (`comment_date_gmt`), respecting the site timezone and date/time format options (#121)
* Fixed: Portal ticket title promoted to `<h1>` for correct heading hierarchy (#247)
* Fixed: Conversation log max-height now uses `60vh` viewport-relative CSS class instead of fixed `400px` inline style (#248)
* Fixed: Ticket UID block inline styles extracted to `.swh-ticket-uid` CSS class (#249)
* Added: Dedicated "Send Reply" and "Save Note" buttons in the ticket editor conversation meta box (#97)
* Added: Unread reply count badge (`.awaiting-mod`) on the admin menu item; clears when the ticket is opened (#101)
* Added: `swh-has-unread` CSS class on ticket list rows with new client replies; cleared on admin view (#102)
* Added: "Send Test Email" button in Settings → Email Templates with inline AJAX feedback (#103)
* Added: `languages/simple-wp-helpdesk.pot` translation file for i18n support (#123)
* Added: `Makefile` local gate — `make test` (lint/phpcs/phpstan/phpunit/semgrep), `make e2e` (Playwright), `make test-all` (#276)
* Added: Pre-push git hook runs `make test` before every push (#277)
* Added: Docker Compose test stack (`docker-compose.test.yml`) with WordPress, MySQL, WP-CLI, and phptest services (#278, #279)
* Added: GitHub Actions PHP matrix (7.4/8.1/8.2/8.3) with lint, PHPCS, PHPStan, PHPUnit, and Composer audit (#280, #282)
* Added: GitHub Actions E2E matrix (WP 5.3 + latest) running Playwright in Docker (#281, #287)
* Added: PR template with pre-PR gate, E2E coverage, and release checklists (#283)
* Added: `testing/.env.example` documenting all test environment variables (#284)
* Changed: `swh_send_email()` now returns `bool` (the `wp_mail()` return value) instead of `void`
* Changed: `SWH_VERSION` bumped to `3.1.0`

= 3.0.0 =
* Added: Categories / departments taxonomy (`helpdesk_category`) with admin column and ticket-list filter (#127)
* Added: Ticket templates — pre-filled request types selectable at submission, stored as `_ticket_template` meta (#132)
* Added: CC / Watcher support — comma-separated CC addresses on tickets; outgoing email sends Cc headers (#129)
* Added: First response time tracking — `_ticket_first_response_at` meta set on first staff reply, shown in ticket editor (#136)
* Added: Ticket merge — move all replies from a source ticket into a target ticket via admin UI (#133)
* Added: SLA breach alerts — configurable warn/breach hour thresholds, hourly cron, row CSS classes, admin digest email (#128)
* Added: Auto-assignment rules — JS rule builder maps categories to assignees; applied on ticket submission (#126)
* Added: Reply-by-email inbound webhook at `/wp-json/swh/v1/inbound-email` with Bearer token auth and quoted-reply stripping (#131)
* Added: Reporting dashboard — status breakdown (doughnut), weekly trend (bar), avg resolution time, avg first response time with Chart.js (#135, #137)
* Added: PHPUnit origname test for `swh_handle_multiple_uploads()` (#241)
* Added: PHPUnit CSAT handler building-block tests (#242)
* Added: PHPUnit `ReportingTest` covering `swh_report_status_breakdown()` and `swh_report_avg_resolution_time()` row exclusion
* Added: PHPUnit `InboundEmailTest` covering ticket-ID parser, sender validator, and quoted-reply stripper

= 2.5.0 =
* Added: PHPUnit + WP-Mock unit test infrastructure (HelpersTest, EmailTest, TicketTest)
* Added: reCAPTCHA Enterprise support — new settings for Project ID, API Key, Score Threshold, and Enterprise Assessment API
* Added: CSAT rating now rejected when ticket status is not the configured closed status
* Added: User-visible error when wp_insert_post() fails on ticket submission
* Added: Skipped upload count included in submission response when files exceed size limit
* Added: PCRE failure logging and template preservation in swh_parse_template()
* Added: JS string localisation for canned response UI via wp_localize_script (swhAdmin.i18n)
* Added: aria-label on all canned response title and body inputs (PHP-rendered and JS-built rows)
* Added: Typed wrapper helpers for PHPStan L9 (swh_get_string_meta, swh_get_int_meta, swh_get_string_option, swh_get_int_option, swh_get_string_comment_meta)
* Added: i18n wrapping for swh_plugin_description_html() strings
* Changed: PHPStan raised to Level 9 with full mixed-type narrowing
* Changed: actions/checkout bumped v4→v6 in CI workflows
* Fixed: Double wp_unslash() on canned response save removed
* Fixed: Attachment origname now stored with sanitize_text_field (preserves spaces) instead of sanitize_file_name
* Fixed: get_posts() return values guarded with is_array() in class-portal.php
* Fixed: My Tickets "View" cell now shows fallback text when link is unavailable
* Fixed: Lookup email skipped (with log entry) when no usable ticket links can be generated
* Fixed: [helpdesk_portal] shortcode docblock updated to reflect My Tickets / lookup form no-token behaviour

= 2.4.2 =
* Fixed: Correct icon now shown in expanded admin sidebar menu (favicon-32.png restored as SWH_MENU_ICON)
* Changed: Plugin icons now bundled in assets/ instead of loaded from external CDN

= 2.4.1 =
* Fixed: Original filenames with spaces now preserved correctly in attachment meta (sanitize_file_name replaced with sanitize_text_field)
* Fixed: Backslashes in canned response titles/bodies no longer stripped on save (removed redundant wp_unslash in settings handler)
* Fixed: default_status shortcode attribute now correctly applied (array_keys wrapper removed from status validation)

= 2.4.0 =
* Changed: Plugin author updated to Sean Mousseau with link to GitHub repository
* Changed: Plugin details modal now shows full feature list instead of the bare plugin header description
* Fixed: Extra blank line between changelog versions removed from readme.txt

= 2.3.0 =
* Added: My Tickets Dashboard — portal without token shows open ticket table for logged-in users or lookup form for guests
* Added: Original attachment filenames preserved and displayed as link labels in portal and admin sidebar
* Added: XHR upload progress bar on ticket submission form
* Added: CSAT satisfaction prompt (1–5 stars) shown to client after closing a ticket; rating stored in _ticket_csat meta
* Added: Humanised timestamps in portal conversation history ("3 hours ago", "Yesterday", etc.)
* Added: [submit_ticket] / [helpdesk_portal] shortcode attributes: show_priority, default_priority, default_status, show_lookup
* Added: Playwright/pytest browser test suite (34 end-to-end scenarios)
* Changed: Resolved → Close CTA redesigned as a prominent two-part block (primary CTA + de-emphasised reply link)
* Changed: PHPStan analysis level raised from 6 to 8 (zero errors; added non-object and foreach guards)
* Fixed: Shortcode detection in page dropdown now uses has_shortcode() for reliable attribute-aware matching
* Fixed: Canned response Insert button non-functional in ticket editor (swh-admin.js not enqueued on post.php)
* Fixed: Canned responses not cleaned up on factory reset or plugin uninstall
* Fixed: Bulk status change now syncs _resolved_timestamp meta
* Fixed: wp_unslash() applied to canned response POST inputs before sanitization
* Fixed: Duplicate icon constant define block removed

= 2.2.0 =
* Added: Bulk "Set Status" action on ticket list for all configured statuses with confirmation notice
* Added: Shortcode annotations in Helpdesk Page dropdown (shows [submit_ticket] / [helpdesk_portal] where found)
* Added: Canned Responses — manage reply templates in Settings; insert picker in ticket editor
* Added: Plugin branding icons in admin menu, Settings header, and WordPress update UI
* Changed: PHPStan analysis level raised from 5 to 6 (zero errors)
* Fixed: CDN icon constants (SWH_ICON_1X, SWH_ICON_2X, SWH_MENU_ICON) were used but never defined at runtime
* Fixed: CDP test transient priming and curl availability guard

= 2.1.0 =
* Added: Ten extensibility hooks for customizing statuses, priorities, email headers, templates, file types, submission data, auto-close threshold, and rate limit TTL
* Added: ARIA tab interface on settings page with full keyboard navigation (Arrow, Home, End keys)
* Added: Explicit label associations on all admin and frontend form inputs
* Added: ARIA live regions — role=log on conversation, role=status on success messages, role=alert on error messages
* Added: aria-hidden on honeypot wrappers, aria-expanded on lookup toggle
* Added: Dedicated swh-admin.css asset (extracted from inline PHP)
* Added: Inline docblocks on all hook registrations and PHPDoc on all functions
* Changed: var → const/let in JavaScript files; multi-line CSS rule format; normalised PHP brace style and indentation; HTML void element audit
* Fixed: Focus styles restored (2px focus rings on form controls and buttons)
* Fixed: PHPStan level-5 type errors resolved; PHPCS zero warnings

= 2.0.0 =
* Changed: Refactored single-file architecture into modular file structure with includes/, admin/, and frontend/ directories
* Changed: Replaced custom GitHub updater with plugin-update-checker library
* Added: Conditional blocks in email templates (`{if key}...{endif key}`)
* Added: Optional `[helpdesk_portal]` shortcode for standalone portal pages
* Added: WordPress-compliant readme.txt
* Fixed: Attachment links in emails now show clean filenames instead of raw query parameters
* Fixed: Settings save now redirects back to the correct settings page and tab
* Fixed: Client reopen form silently did nothing when submitted without text or attachments
* Fixed: Closing a ticket blocked immediate reopen due to shared rate limit key; each action now has its own key
* Fixed: Changing Settings → Helpdesk Page had no effect on generated portal links; setting is now read directly
* Fixed: New-ticket emails linked to the submission page instead of the configured portal page

= 1.9.0 =
* Added: Configurable portal token expiration (default 90 days) with auto-rotation on lookup
* Added: Persistent rate limiting via wp_options (survives cache flushes)
* Added: Cron job locking to prevent duplicate processing
* Added: Comment isolation from WordPress feeds, widgets, and comment counts
* Added: Meta cache priming for admin list and cron loops
* Added: Optional technician restriction to assigned tickets only
* Added: Inline DOM notifications replace browser alert() dialogs
* Fixed: Missing is_wp_error() checks on wp_insert_post and wp_insert_comment
* Fixed: Header injection in file proxy Content-Disposition
* Fixed: intval() changed to absint() for ticket ID sanitization
* Fixed: Extra closing div tag in error output
* Changed: Thorough uninstall cleanup (cron hooks, upload dir, rate-limit options, technician role)

= 1.8 =
* Added: CDN/proxy-aware rate limiting
* Added: Protected file uploads with proxy endpoint
* Added: Anti-spam on portal forms
* Added: Client ticket lookup with email resend
* Added: Internationalization (i18n) support

== Upgrade Notice ==

= 3.1.0 =
Locale-aware timestamps, unread reply indicators, and the new test-email tool. Upgrade recommended.

= 2.5.0 =
Security patch: empty portal tokens are now rejected before hash comparison (previously `hash_equals('','')` could bypass access control). Also adds reCAPTCHA Enterprise support, PHPStan L9, PHPUnit tests, and several bug fixes. Upgrade recommended.

= 2.4.0 =
Cosmetic release: author attribution corrected, plugin details modal now shows the full feature description.

= 2.0.0 =
Major refactor release. Modular file structure, improved email templates with conditional blocks, optional dedicated portal page, and several bug fixes including portal link routing and client reopen flow. Includes all v1.9.0 security features. No breaking changes.

= 1.9.0 =
Security hardening release. Adds token expiration, persistent rate limiting, and comment isolation. Recommended for all users.
