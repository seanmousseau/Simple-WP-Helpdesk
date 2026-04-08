=== Simple WP Helpdesk ===
Contributors: seanmousseau
Tags: helpdesk, tickets, support, customer service, ticketing
Requires at least: 5.3
Tested up to: 6.7
Stable tag: 2.0.0
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
* Tabbed settings panel with 6 tabs (General, Assignment & Routing, Email Templates, Messages, Anti-Spam, Tools)
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

= 2.0.0 =
* Changed: Refactored single-file architecture into modular file structure with includes/, admin/, and frontend/ directories
* Changed: Replaced custom GitHub updater with plugin-update-checker library
* Added: Conditional blocks in email templates (`{if key}...{endif key}`)
* Added: Optional `[helpdesk_portal]` shortcode for standalone portal pages
* Added: WordPress-compliant readme.txt
* Fixed: Attachment links in emails now show clean filenames instead of raw query parameters
* Fixed: Settings save now redirects back to the correct settings page and tab

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
Major refactor release. Modular file structure, improved email templates with conditional blocks, and several bug fixes. Includes all v1.9.0 security features. No breaking changes.

= 1.9.0 =
Security hardening release. Adds token expiration, persistent rate limiting, and comment isolation. Recommended for all users.
