---
title: Home
nav_order: 1
description: Simple WP Helpdesk — a lightweight, full-featured ticketing system built natively for WordPress.
permalink: /
---

# Simple WP Helpdesk

<img src="https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/logo.webp" alt="Simple WP Helpdesk" width="200" style="margin-bottom: 1rem;">

A lightweight, full-featured ticketing system built natively for WordPress. No third-party services, no subscriptions, no custom database tables — all data stays on your server.

**Version:** 4.0.6 &nbsp;|&nbsp; **WordPress:** 6.0+ &nbsp;|&nbsp; **PHP:** 8.0+

[Download Latest Release](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/latest){: .btn .btn-primary .mr-2 }
[View on GitHub](https://github.com/seanmousseau/Simple-WP-Helpdesk){: .btn }

---

## Quick Start

1. Download `simple-wp-helpdesk.zip` from the [latest release](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and install the ZIP.
3. Activate the plugin — a **Tickets** menu appears in the dashboard.
4. Create a WordPress page (e.g. "Support") and add the `[submit_ticket]` shortcode.
5. Go to **Tickets → Settings → Assignment & Routing** and set **Helpdesk Page** to that page.

Clients can now submit tickets and receive a secure portal link by email.

See the [Installation Guide](installation) for full details and the [Configuration Guide](configuration) for each settings tab.

---

## Key Features

| Feature | Details |
|---------|---------|
| **No custom DB tables** | Tickets are a Custom Post Type; replies are WP Comments; settings are `wp_options` |
| **Frontend forms** | `[submit_ticket]` shortcode handles submission, portal, and ticket lookup in one page |
| **Secure client portal** | Token-based access — clients view history, reply, upload files, close and reopen |
| **10-tab settings panel** | General · Assignment & Routing · Portal · Email Templates · Messages · Anti-Spam · Integrations · Canned Responses · Templates · Tools |
| **16 email templates** | Fully customizable HTML + plain-text, dynamic placeholders, conditional `{if}` blocks |
| **Inbound email webhook** | Accept replies from Mailgun, SendGrid, Postmark or any provider via `POST /wp-json/swh/v1/inbound-email` with HMAC bearer auth |
| **Anti-spam** | Honeypot, Google reCAPTCHA v2, Cloudflare Turnstile |
| **Rate limiting** | CDN/proxy-aware, persistent, per-action keys, survives cache flushes |
| **File uploads** | XHR progress bar, drag-and-drop, proxy-served, configurable limits |
| **Canned responses** | Save reply templates in Settings; insert from the ticket editor |
| **Ticket templates** | Pre-configured request types that pre-fill the description at submission |
| **Categories taxonomy** | Hierarchical departments with admin column, list filter, and auto-assignment rules |
| **SLA breach alerts** | Configurable warn/breach thresholds, hourly cron, row highlighting, admin digest email |
| **Reporting dashboard** | Status breakdown, weekly trend, avg resolution time, avg first response — Chart.js |
| **Ticket merge** | Move replies from a source ticket into a target ticket via the admin UI |
| **CSAT ratings** | Optional 1–5 star satisfaction prompt after ticket closure |
| **Dark mode** | Full `prefers-color-scheme: dark` token overrides; force-light escape hatch |
| **GDPR tools** | Per-email data purge, retention policies, full uninstall cleanup |
| **Auto-updates** | GitHub-based updater — new releases appear in the WordPress dashboard |
| **Accessibility (WCAG AA)** | Semantic fieldsets, ARIA labels, focus rings in light & dark, contrast-checked palette |
| **i18n ready** | Full `.pot` file, `__()` / `esc_html__()` throughout |

---

## Documentation

### Getting Started

| Guide | Description |
|-------|-------------|
| [Installation](installation) | Install, update, and uninstall |
| [Usage Guide](usage) | For clients and technicians — submitting, replying, working tickets |
| [Configuration](configuration) | All ten settings tabs explained |
| [Shortcode Reference](shortcodes) | `[submit_ticket]`, `[helpdesk_portal]`, attributes |

### Integrations & email

| Guide | Description |
|-------|-------------|
| [Inbound Email Webhook](inbound-webhook) | Accept ticket replies from Mailgun / SendGrid / Postmark / any provider |
| [Email Setup](email-setup) | Outbound deliverability — WP Mail SMTP, SPF / DKIM / DMARC, providers |

### Reference

| Guide | Description |
|-------|-------------|
| [Categories](categories) | Hierarchical departments + auto-assignment rules |
| [Security](security) | Token auth, anti-spam, nonces, input handling |
| [FAQ](faq) | Frequently asked questions |
| [Troubleshooting](troubleshooting) | Common issues |

### Developer

| Guide | Description |
|-------|-------------|
| [Development Guide](development) | Architecture, conventions, and release process |
| [Hooks Reference](hooks) | PHP actions and filters for customization |
| [Deprecation Policy](developer/deprecations) | How and when hooks are deprecated |

---

## Requirements

| | Minimum |
|-|---------|
| WordPress | 6.0+ |
| PHP | 8.0+ |

---

## License

Released under the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).
Developed and maintained by [Sean Mousseau](https://github.com/seanmousseau).
