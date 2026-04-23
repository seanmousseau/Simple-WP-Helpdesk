---
title: Home
nav_order: 1
description: Simple WP Helpdesk — a lightweight, full-featured ticketing system built natively for WordPress.
permalink: /
---

# Simple WP Helpdesk

<img src="https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/logo.webp" alt="Simple WP Helpdesk" width="200" style="margin-bottom: 1rem;">

A lightweight, full-featured ticketing system built natively for WordPress. No third-party services, no subscriptions, no custom database tables — all data stays on your server.

**Version:** 3.5.0 &nbsp;|&nbsp; **WordPress:** 5.3+ &nbsp;|&nbsp; **PHP:** 7.4+

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

---

## Key Features

| Feature | Details |
|---------|---------|
| **No custom DB tables** | Tickets are a Custom Post Type; replies are WP Comments; settings are `wp_options` |
| **Frontend forms** | `[submit_ticket]` shortcode handles submission, portal, and ticket lookup in one page |
| **Secure client portal** | Token-based access — clients view history, reply, upload files, close and reopen |
| **8-tab settings panel** | General, Assignment & Routing, Email Templates, Messages, Anti-Spam, Canned Responses, Templates, Tools |
| **14 email templates** | Fully customizable HTML + plain-text, dynamic placeholders, conditional blocks |
| **Anti-spam** | Honeypot, Google reCAPTCHA v2, Cloudflare Turnstile |
| **Rate limiting** | CDN/proxy-aware, persistent, per-action keys, survives cache flushes |
| **File uploads** | XHR progress bar, drag-and-drop, proxy-served, configurable limits |
| **Canned responses** | Save reply templates in Settings; insert from the ticket editor |
| **Ticket templates** | Pre-configured request types that pre-fill the description field at submission |
| **Categories taxonomy** | Hierarchical departments with admin column, list filter, and auto-assignment rules |
| **SLA breach alerts** | Configurable warn/breach thresholds, hourly cron, row highlighting, admin digest email |
| **Reporting dashboard** | Status breakdown, weekly trend, avg resolution time, avg first response — Chart.js |
| **Ticket merge** | Move replies from a source ticket into a target ticket via the admin UI |
| **Dark mode** | Full `prefers-color-scheme: dark` token overrides; force-light escape hatch |
| **GDPR tools** | Per-email data purge, retention policies, full uninstall cleanup |
| **Auto-updates** | GitHub-based updater — new releases appear in the WordPress dashboard |
| **CSAT ratings** | Optional 1–5 star satisfaction prompt after ticket closure |
| **i18n ready** | Full `.pot` file, `__()` / `esc_html__()` throughout |

---

## Documentation

| Guide | Description |
|-------|-------------|
| [Installation](installation) | Install, update, and uninstall |
| [Configuration](configuration) | All eight settings tabs explained |
| [Usage Guide](usage) | Guide for clients and technicians |
| [Shortcode Reference](shortcodes) | All shortcodes, attributes, and examples |
| [Security](security) | Token auth, anti-spam, nonces, input handling |
| [Development Guide](development) | Architecture, conventions, and release process |
| [Hooks Reference](hooks) | PHP filters and actions for customization |
| [Troubleshooting](troubleshooting) | Common issues and FAQ |

---

## Requirements

| | Minimum |
|-|---------|
| WordPress | 5.3 |
| PHP | 7.4 |

---

## License

Released under the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).
Developed and maintained by [Sean Mousseau](https://github.com/seanmousseau).
