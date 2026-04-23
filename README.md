<img width="250" src="https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/logo.webp" alt="Simple WP Helpdesk" />

# Simple WP Helpdesk

A lightweight, full-featured ticketing system built natively for WordPress. No third-party services required — all data stays on your server.

**Version:** 3.5.0 &nbsp;|&nbsp; **WordPress:** 5.3+ &nbsp;|&nbsp; **PHP:** 7.4+

## Features

- **Custom Post Type** for tickets — no custom database tables
- **Frontend submission form** and **secure token-based client portal** via `[submit_ticket]` shortcode
- **Optional dedicated portal page** via `[helpdesk_portal]` shortcode
- **8-tab settings panel** (General, Assignment & Routing, Email Templates, Messages, Anti-Spam, Canned Responses, Templates, Tools)
- **Canned responses** — save reply templates in Settings and insert them from within the ticket editor
- **Ticket templates** — pre-configured request types that pre-fill the description field at submission
- **Multi-file upload** with XHR progress bar, drag-and-drop, and configurable size/count limits
- **Technician role** with optional assignment restriction (`swh_restrict_to_assigned`)
- **HTML and plain-text email notifications** — 14 fully customizable templates with dynamic placeholders and conditional blocks (`{if key}...{endif key}`)
- **Email branding** — header band with logo, site name, and footer; `swh_email_logo_url` option
- **Background automation** — auto-close resolved tickets and scheduled data retention
- **Anti-spam** on all forms — honeypot, Google reCAPTCHA v2, and Cloudflare Turnstile
- **CDN/proxy-aware rate limiting** — persistent, per-action keys, survives cache flushes
- **Protected file uploads** served through a proxy endpoint (no direct URL access)
- **Token expiration** with configurable TTL and auto-rotation for portal links
- **"Resend my ticket links"** client lookup form with anti-enumeration messaging
- **My Tickets dashboard** — card list for logged-in users; lookup form for guests
- **Categories taxonomy** — hierarchical departments with list filter and auto-assignment rules
- **SLA breach alerts** — configurable warn/breach thresholds, row highlighting, admin digest email
- **Reporting dashboard** — status breakdown, weekly trend, avg resolution + first response (Chart.js)
- **KPI cards** — total, open, avg resolution time, avg first response time
- **Ticket merge** — consolidate duplicate tickets from the admin UI
- **Full dark mode** — `prefers-color-scheme: dark` token overrides; force-light escape hatch
- **Unified design token system** — shadow, z-index, and easing scales in `swh-shared.css`
- **CSAT satisfaction prompt** — optional 1–5 star rating after ticket closure
- **GDPR tools** — per-email data purge, retention policies, full uninstall cleanup
- **Inbound email webhook** — `POST /wp-json/swh/v1/inbound-email` with Bearer auth
- **Internationalization (i18n)** ready with full `.pot` file
- **GitHub auto-updater** — new releases delivered directly to the WordPress dashboard

## Quick Start

1. Download `simple-wp-helpdesk.zip` from the [latest release](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/latest).
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and install the ZIP.
3. Activate the plugin. A **Tickets** menu appears in the dashboard.
4. Create a page for clients (e.g. "Support") and add `[submit_ticket]` — or use separate pages: `[submit_ticket]` for the form and `[helpdesk_portal]` for the portal.
5. Go to **Tickets > Settings > Assignment & Routing** and set the **Helpdesk Page** to the page clients should land on when clicking their portal link.

## Documentation

Full documentation is available at **[seanmousseau.github.io/Simple-WP-Helpdesk](https://seanmousseau.github.io/Simple-WP-Helpdesk/)**.

Quick links:

- [Installation](https://seanmousseau.github.io/Simple-WP-Helpdesk/installation) — install, update, and uninstall
- [Configuration](https://seanmousseau.github.io/Simple-WP-Helpdesk/configuration) — all settings tabs explained
- [Usage Guide](https://seanmousseau.github.io/Simple-WP-Helpdesk/usage) — guide for clients and technicians
- [Shortcode Reference](https://seanmousseau.github.io/Simple-WP-Helpdesk/shortcodes) — all shortcodes, attributes, and examples
- [Security](https://seanmousseau.github.io/Simple-WP-Helpdesk/security) — token auth, anti-spam, nonces, input handling
- [Development Guide](https://seanmousseau.github.io/Simple-WP-Helpdesk/development) — architecture, conventions, and release process
- [Hooks Reference](https://seanmousseau.github.io/Simple-WP-Helpdesk/hooks) — PHP filters and actions
- [Troubleshooting](https://seanmousseau.github.io/Simple-WP-Helpdesk/troubleshooting) — common issues and FAQ

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

## Contributing

Bug reports, security disclosures, and feature requests are welcome. Please open an issue on [GitHub](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues).

## License

This project is licensed under the GNU General Public License v2.0. See [LICENSE](LICENSE) for details.
