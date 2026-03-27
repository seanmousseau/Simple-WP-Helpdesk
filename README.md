# Simple WP Helpdesk

A comprehensive, lightweight ticketing system built natively for WordPress. No third-party services required — all client data stays on your own server.

**Version:** 1.5 &nbsp;|&nbsp; **Requires WordPress:** 5.3+ &nbsp;|&nbsp; **Requires PHP:** 7.2+

---

## Features

- **Secure Client Portal** — Clients view and reply to tickets via unique cryptographic email links, no WordPress account needed.
- **Customizable Workflows** — Define your own ticket statuses and priority levels.
- **Email Templates** — 16 fully customizable email templates with dynamic placeholders.
- **File Attachments** — Two-way multi-file uploads for clients and technicians, with configurable size limits and instant JavaScript validation.
- **Internal Notes** — Technicians can leave private notes clients never see.
- **Admin Ticket Creation** — Create tickets on behalf of clients directly from the WordPress dashboard, with optional confirmation email.
- **Automation** — Background cron jobs auto-close resolved tickets after configurable inactivity.
- **Data Retention & GDPR** — Auto-purge old attachments and tickets; GDPR-compliant per-email data deletion.
- **Anti-Spam** — Built-in Honeypot, Google reCAPTCHA v2, and Cloudflare Turnstile support.
- **Page Builder Friendly** — Scoped CSS keeps the frontend form correctly styled inside Elementor and similar builders.
- **Automatic Updates** — Built-in GitHub auto-updater delivers new releases directly to your WordPress dashboard.

---

## Quick Start

1. Download `simple-wp-helpdesk.zip` from the [latest release](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and install the ZIP.
3. Activate the plugin. A **Tickets** menu item appears in the dashboard.
4. Create a page (e.g., "Support") and add the shortcode `[submit_ticket]` to it.
5. Go to **Tickets → Settings → Assignment & Routing** and set the **Helpdesk Page** to the page you just created.

That's it — your helpdesk is live.

---

## Documentation

| Guide | Description |
|-------|-------------|
| [Installation](docs/installation.md) | Manual ZIP install, automatic updates, and uninstall |
| [Configuration](docs/configuration.md) | All settings tabs explained |
| [Usage](docs/usage.md) | Guide for clients and technicians |
| [Security](docs/security.md) | Token auth, anti-spam, nonces, input handling |
| [Development](docs/development.md) | Architecture, coding conventions, and release process |

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

---

## Known Issues

- GitHub Auto-Update is broken in versions 1.3 and earlier. Upgrade to 1.4+ to restore update functionality.

---

## Contributing

Bug reports, security disclosures, and feature requests are welcome — please open an issue on [GitHub](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues).

---

## License

See [LICENSE](LICENSE) for details.
