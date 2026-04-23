---
title: Installation
nav_order: 2
---

# Installation

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress   | 5.3+    |
| PHP         | 7.4+    |

---

## Installing the Plugin

Simple WP Helpdesk is not listed on the WordPress.org plugin directory. Install it manually via ZIP upload through the WordPress dashboard.

1. Download `simple-wp-helpdesk.zip` from the [latest GitHub release](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/latest).
2. In your WordPress dashboard, go to **Plugins → Add New**.
3. Click **Upload Plugin** at the top of the screen.
4. Click **Choose File**, select `simple-wp-helpdesk.zip`, then click **Install Now**.
5. Click **Activate Plugin** once installation is complete.

A **Tickets** menu item will now appear in the left-hand dashboard sidebar.

---

## Setting Up the Frontend

### Combined layout (simplest)

Create a single WordPress page (e.g. "Support") and add:

```text
[submit_ticket]
```

This page handles both the ticket submission form and the client portal. Go to **Tickets → Settings → Assignment & Routing** and set **Helpdesk Page** to this page.

### Separate form and portal pages

Create two pages:

| Page | Shortcode | Purpose |
|------|-----------|---------|
| e.g. "Submit a Ticket" | `[submit_ticket]` | Submission form and ticket lookup |
| e.g. "My Tickets" | `[helpdesk_portal]` | Client portal only (no submission form) |

Set **Helpdesk Page** to the portal page (`[helpdesk_portal]`). All secure portal links in emails will point there.

See the [Shortcode Reference](shortcodes) for all available attributes on both shortcodes.

---

## Automatic Updates

The plugin includes a built-in GitHub auto-updater. When a new release is published, WordPress will display the standard "Update Available" notice in **Plugins** and allow one-click updating — no manual ZIP download required.

---

## Uninstalling

To remove the plugin and optionally all its data:

1. Go to **Tickets → Settings → Tools**.
2. Check **Delete all plugin data on uninstall** if you want tickets, attachments, and settings permanently removed.
3. Go to **Plugins**, deactivate, then delete Simple WP Helpdesk.

If the delete-on-uninstall option is not checked, WordPress tables and options are left intact so reinstalling restores all data.
