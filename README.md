<img draggable="false" oncontextmenu="return false;" width="250" src="https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/logo.webp" alt="Simple WP Helpdesk" />

# Simple WP Helpdesk

A lightweight, full-featured ticketing system built natively for WordPress. No third-party services required — all data stays on your server.

**Version:** 2.0.0 &nbsp;|&nbsp; **WordPress:** 5.3+ &nbsp;|&nbsp; **PHP:** 7.4+

## Features

- **Custom Post Type** for tickets — no custom database tables
- **Frontend submission form** and **secure token-based client portal** via `[submit_ticket]` shortcode
- **Optional dedicated portal page** via `[helpdesk_portal]` shortcode — separate from the submission form
- **Tabbed settings panel** with 6 tabs (General, Assignment & Routing, Email Templates, Messages, Anti-Spam, Tools)
- **Multi-file upload** with client-side validation and configurable size/count limits
- **Technician role** with optional assignment restriction (`swh_restrict_to_assigned`)
- **HTML and plain-text email notifications** — 14 fully customizable templates with dynamic placeholders and conditional blocks (`{if key}...{endif key}`)
- **Background automation** — auto-close resolved tickets and scheduled data retention
- **Anti-spam** on all forms — honeypot, Google reCAPTCHA v2, and Cloudflare Turnstile
- **CDN/proxy-aware rate limiting** via `swh_get_client_ip()` with per-action keys
- **Protected file uploads** served through a proxy endpoint (no direct URL access)
- **Token expiration** with configurable TTL and auto-rotation for portal links
- **"Resend my ticket links"** client lookup form with anti-enumeration messaging
- **Internationalization (i18n)** ready with full text-domain support
- **GDPR tools** — per-email data purge, data retention policies, and full uninstall cleanup
- **GitHub auto-updater** — new releases delivered directly to the WordPress dashboard

## Quick Start

1. Download `simple-wp-helpdesk.zip` from the [latest release](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/latest).
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and install the ZIP.
3. Activate the plugin. A **Tickets** menu appears in the dashboard.
4. Create a page for clients (e.g., "Support") and add `[submit_ticket]` — or use separate pages: `[submit_ticket]` for the form and `[helpdesk_portal]` for the portal.
5. Go to **Tickets > Settings > Assignment & Routing** and set the **Helpdesk Page** to the page clients should land on when clicking their portal link.

## Documentation

Detailed guides are available in the [`docs/`](docs/) folder:

- [Installation](docs/installation.md) — install, update, and uninstall
- [Configuration](docs/configuration.md) — all settings tabs explained
- [Usage](docs/usage.md) — guide for clients and technicians
- [Security](docs/security.md) — token auth, anti-spam, nonces, input handling
- [Development](docs/development.md) — architecture, conventions, and release process

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

## Contributing

Bug reports, security disclosures, and feature requests are welcome. Please open an issue on [GitHub](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues).

## License

This project is licensed under the GNU General Public License v2.0. See [LICENSE](LICENSE) for details.
