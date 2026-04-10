=== Simple WP Helpdesk ===
Contributors: seanmousseau
Tags: helpdesk, tickets, support, customer service, ticketing
Requires at least: 5.3
Tested up to: 6.7
Stable tag: 2.3.0
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

= 2.3.0 =
* Added: My Tickets Dashboard — portal without token shows open ticket table for logged-in users or lookup form for guests
* Added: Original attachment filenames preserved and displayed as link labels in portal and admin sidebar
* Added: XHR upload progress bar on ticket submission form
* Added: CSAT satisfaction prompt (1–5 stars) shown to client after closing a ticket; rating stored in _ticket_csat meta
* Added: Humanised timestamps in portal conversation history ("3 hours ago", "Yesterday", etc.)
* Added: [submit_ticket] / [helpdesk_portal] shortcode attributes: show_priority, default_priority, default_status, show_lookup
* Added: Playwright/pytest browser test suite (28 end-to-end scenarios)
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

= 2.0.0 =
Major refactor release. Modular file structure, improved email templates with conditional blocks, optional dedicated portal page, and several bug fixes including portal link routing and client reopen flow. Includes all v1.9.0 security features. No breaking changes.

= 1.9.0 =
Security hardening release. Adds token expiration, persistent rate limiting, and comment isolation. Recommended for all users.
