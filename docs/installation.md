# Installation

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress   | 5.3+    |
| PHP         | 7.2+    |

---

## Installing the Plugin

Simple WP Helpdesk is not listed on the WordPress.org plugin directory. It is installed manually via ZIP upload through the WordPress dashboard.

1. Download `simple-wp-helpdesk.zip` from the [latest GitHub release](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/latest).
2. In your WordPress dashboard, go to **Plugins → Add New**.
3. Click **Upload Plugin** at the top of the screen.
4. Click **Choose File**, select `simple-wp-helpdesk.zip`, then click **Install Now**.
5. Click **Activate Plugin** once installation is complete.

A **Tickets** menu item will now appear in the left-hand dashboard sidebar.

---

## Setting Up the Frontend

Create a WordPress page (e.g., "Support") and add the following shortcode to its content:

```
[submit_ticket]
```

This page serves as both the ticket submission form and the secure client portal. Once the plugin is activated, go to **Tickets → Settings → Assignment & Routing** and select this page from the **Helpdesk Page** dropdown.

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
