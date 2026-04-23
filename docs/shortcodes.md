---
title: Shortcode Reference
nav_order: 5
---

# Shortcode Reference

Simple WP Helpdesk provides two shortcodes. Both are handled by the same underlying function and share the same attribute set.

---

## `[submit_ticket]`

Renders the full helpdesk interface on a single page: ticket submission form, ticket lookup, and the client portal (when a valid token is present in the URL).

This is the recommended shortcode for most sites. Use it on a single "Support" page.

```text
[submit_ticket]
```

---

## `[helpdesk_portal]`

Renders only the client portal and ticket lookup — without the submission form. Use this on a dedicated "My Tickets" or "Support Hub" page when you want to keep the submission form on a separate page.

```text
[helpdesk_portal]
```

---

## Shared Attributes

Both shortcodes accept the following attributes:

| Attribute | Values | Default | Description |
|-----------|--------|---------|-------------|
| `show_priority` | `yes` / `no` | `yes` | Show or hide the Priority field on the submission form |
| `default_priority` | Any configured priority label | Site default | Pre-select a specific priority on the submission form |
| `default_status` | Any configured status label | Site default | Override the status assigned to new tickets from this page |
| `show_lookup` | `yes` / `no` | `yes` | Show or hide the "Resend my ticket links" lookup form toggle |

### Examples

Hide the priority field and default to "High":

```text
[submit_ticket show_priority="no" default_priority="High"]
```

Show a submission form that always creates tickets as "In Progress":

```text
[submit_ticket default_status="In Progress"]
```

Portal page without the lookup form:

```text
[helpdesk_portal show_lookup="no"]
```

---

## How Portal URLs Work

When a client clicks the secure link in their email, the URL contains two query parameters:

```text
https://yoursite.com/support/?swh_ticket=123&token=AbCdEfGhIjKlMnOpQrSt
```

The shortcode detects these parameters and renders the client portal instead of the submission form. The **Helpdesk Page** setting (**Tickets → Settings → Assignment & Routing**) controls which page these links point to.

If neither parameter is present:
- Logged-in WordPress users see the **My Tickets** dashboard — a card list of all tickets whose `_ticket_email` meta matches the logged-in user's email address.
- Guests see the lookup form rendered by `swh_render_lookup_form()` (unless `show_lookup="no"`).

---

## Compatibility

Both shortcodes are wrapped in `.swh-helpdesk-wrapper` for scoped CSS and are compatible with Elementor, Divi, Beaver Builder, and other page builders. Place the shortcode in a standard HTML/Shortcode block.
