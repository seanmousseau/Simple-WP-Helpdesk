---
title: Configuration
nav_order: 4
---

# Configuration

Navigate to **Tickets → Settings** in your WordPress dashboard. Settings are organized across ten tabs:

1. [General](#general) — priorities, statuses, uploads, SLA, appearance toggles
2. [Assignment & Routing](#assignment--routing) — default assignee, routing rules, fallback email, helpdesk page
3. [Portal](#portal) — CSAT widget, frontend & admin theme controls
4. [Email Templates](#email-templates) — 14 customizable email events with placeholders
5. [Messages](#messages) — frontend success and error strings
6. [Anti-Spam](#anti-spam) — honeypot, reCAPTCHA v2 / Enterprise, Turnstile
7. [Integrations](#integrations) — inbound email webhook (see also [Inbound Email Webhook](inbound-webhook))
8. [Canned Responses](#canned-responses) — reusable reply templates
9. [Templates](#templates) — pre-filled request types for the submission form
10. [Tools](#tools) — retention, GDPR purge, factory reset

---

## General

| Setting | Description | Default |
|---------|-------------|---------|
| **Custom Priorities** | Comma-separated list of priority levels | `Low, Medium, High` |
| **Default Priority** | Priority assigned to new tickets | `Medium` |
| **Custom Statuses** | Comma-separated list of workflow statuses | `Open, In Progress, Resolved, Closed` |
| **Default Status** | Status assigned to new tickets | `Open` |
| **Resolved Status** | Triggers the auto-close countdown | `Resolved` |
| **Closed Status** | Disables further client replies | `Closed` |
| **Re-Opened Status** | Assigned when a client re-opens a closed ticket | `Open` |
| **Auto-Close Days** | Days after "Resolved" before automatic closure (0 = disabled) | `3` |
| **Max Upload Size** | Maximum file size per upload in MB | `5` |
| **Max Files Per Upload** | Maximum number of files per submission (0 = unlimited) | `5` |
| **SLA Warn Hours** | Hours before a ticket is flagged as an SLA warning | `0` (disabled) |
| **SLA Breach Hours** | Hours before a ticket is flagged as an SLA breach | `0` (disabled) |
| **Restrict Technicians** | When enabled, technicians only see tickets assigned to them | Off |

The General tab also exposes optional **Violet Theme** for admin chrome accents.

---

## Assignment & Routing

| Setting | Description |
|---------|-------------|
| **Default Assignee** | Technician automatically assigned to every new ticket |
| **Fallback Alert Email** | Receives new-ticket and client-reply notifications when no assignee is set |
| **Helpdesk Page** | The page clients land on when clicking their portal link. Use the `[helpdesk_portal]` page if you have a dedicated portal, or the `[submit_ticket]` page for a combined layout. **All portal links in emails point here.** |

### Auto-assignment rules

Route tickets to specific technicians based on category. Add one or more rules mapping a **Category** to an **Assignee**. The first matching rule wins; if no rule matches, the default assignee is used.

### Email routing priority (admin notifications)

1. The ticket's assigned technician
2. The default assignee (if set)
3. The fallback alert email (if set)
4. The site admin email

### SLA Breach Alerts

When `SLA Warn Hours` or `SLA Breach Hours` is non-zero, an hourly cron job scans open tickets and:

- Adds a row-level highlight on the admin ticket list.
- Emits a polite ARIA-live announcement on the badge.
- Sends a daily digest email to the fallback alert address listing breached tickets.

---

## Portal

Frontend portal appearance and the post-close customer-satisfaction widget.

### Customer Satisfaction (CSAT)

| Setting | Description | Default |
|---------|-------------|---------|
| **Show CSAT widget** | Renders a 1–5 star rating after the client closes a ticket | On |
| **Prompt text** | Heading shown above the star buttons | "How would you rate the support you received?" |
| **Thank-you message** | Shown after a rating is submitted | "Thanks for your feedback!" |
| **Skip link label** | Text for the dismiss link | "Skip" |

When **Show CSAT widget** is off, no rating widget renders and the AJAX endpoint is not exposed.

### Theme

| Setting | Options | Notes |
|---|---|---|
| **Frontend portal theme** | `Auto` / `Light` / `Dark` | `Auto` follows the visitor's `prefers-color-scheme`. `Light` / `Dark` pin the portal. |
| **Admin theme** | `Light` / `Dark` / `Auto` | `Auto` follows the user's WP admin color scheme (Midnight / Modern / Ectoplasm are treated as dark). |

See [Dark Mode](#dark-mode) below for details on how this propagates.

---

## Email Templates

Customize the subject line and body of every email the plugin sends. Click **Reset to default** below any template to restore the original text. Use the **Send Test Email** button at the top of the tab to preview a sample message in your inbox.

### Available placeholders

| Placeholder | Value |
|-------------|-------|
| `{name}` | Client's name |
| `{email}` | Client's email address |
| `{ticket_id}` | Unique ticket ID (e.g. `TKT-0001`) |
| `{title}` | Ticket title / summary |
| `{status}` | Current ticket status |
| `{priority}` | Current priority level |
| `{message}` | Message body |
| `{ticket_url}` | Secure client portal link |
| `{admin_url}` | WordPress admin edit link (technicians only) |
| `{autoclose_days}` | Configured auto-close threshold |
| `{site_name}` | Site name from WordPress |

### Conditional blocks

Wrap any template section in `{if key}...{endif key}` to include it only when that placeholder has a non-empty value:

```text
{if message}
Message: {message}
{endif message}
```

Unreplaced placeholders are automatically removed from the final output.

### Subject lines and `[TKT-XXXX]`

The reply-by-email webhook extracts the ticket ID by matching `[TKT-XXXX]` in the inbound subject. **Keep the `[TKT-{ticket_id}]` token in every outbound subject template** or replies cannot be threaded back to the ticket. See [Inbound Email Webhook](inbound-webhook).

### Email events (14 templates)

**Sent to client:**

- New ticket received (confirmation)
- Technician replied
- Status changed (no reply)
- Technician replied + status changed (combined)
- Ticket resolved
- Ticket re-opened
- Ticket auto-closed
- Ticket closed by client (confirmation)

**Sent to technician/admin:**

- New ticket submitted
- Client replied
- Client re-opened ticket
- Client closed ticket
- Ticket assigned to technician
- Ticket auto-closed (admin copy)

### Branding

The header band displays your site logo (set via **Logo URL**) and site name. If no logo URL is set, the site icon is used as a fallback at 32×32 px. All CSS is inlined for email client compatibility.

---

## Messages

Customize the frontend text shown to clients after form actions:

| Message Key | When Shown |
|-------------|-----------|
| Success — New Ticket | After a ticket is successfully submitted |
| Success — Reply Sent | After a client reply is sent |
| Success — Ticket Closed | After a client closes a resolved ticket |
| Success — Ticket Re-Opened | After a client re-opens a closed ticket |
| Error — Spam Check Failed | Anti-spam validation failed |
| Error — Invalid Token | Ticket portal URL is invalid or expired |
| Error — General | Catch-all for unexpected errors |

---

## Anti-Spam

| Method | Description |
|--------|-------------|
| **None** | No anti-spam protection |
| **Honeypot** | Invisible hidden field; catches basic bots with no user friction |
| **Google reCAPTCHA v2** | "I'm not a robot" checkbox; requires Site Key + Secret Key |
| **Google reCAPTCHA Enterprise** | Score-based; requires Site Key, Project ID, API Key, and a score threshold (0.0 = likely bot, 1.0 = likely human) |
| **Cloudflare Turnstile** | Privacy-friendly CAPTCHA alternative; requires Site Key + Secret Key |

Protection applies to: the ticket submission form, the ticket lookup form, and the portal reply/close/reopen forms.

---

## Integrations

Third-party integrations and inbound channels.

### Reply-by-Email Webhook

| Field | Description |
|---|---|
| **Webhook URL** | `https://yoursite.com/wp-json/swh/v1/inbound-email` (read-only, shown for copy) |
| **Webhook Secret** | Required. Blank disables the endpoint. Your mail provider must send `Authorization: Bearer <secret>` on every POST. Compared in constant time. |

Full setup instructions, payload format, response codes, and troubleshooting: **[Inbound Email Webhook](inbound-webhook)**.

### Coming soon

The Integrations tab currently lists these planned features as placeholders:

- **REST API keys** — scoped key management for the public `/swh/v1` API (v4.1)
- **Outbound webhooks** — HMAC-signed event subscriptions with delivery log + retry (v4.2)
- **Native integrations** — Slack, Microsoft Teams, Discord, Zapier (v4.3)

---

## Canned Responses

Pre-written reply templates that speed up ticket responses.

- Click **+ Add Response** to create a new template.
- Enter a **Title** (visible only in the selector) and a **Body** (inserted into the reply field).
- Save via the main **Save Changes** button.

To use a canned response in a ticket, open the ticket editor, click the **Canned Response** picker in the conversation section, and select a template. The body is inserted into the active reply field.

---

## Templates

Pre-configured submission types that pre-fill the ticket description field when a client selects them on the frontend form.

- Click **+ Add Template** to create a new request type.
- Enter a **Label** (shown as the option name in the dropdown) and a **Description** (the pre-filled text).
- Save via the main **Save Changes** button.

When templates are configured, the submission form displays a **Request Type** dropdown. Selecting an option populates the description field with the corresponding template body, which the client can then edit before submitting.

---

## Tools

### Automated Data Retention

| Setting | Description | Default |
|---------|-------------|---------|
| **Attachment Retention Days** | Delete physical files older than N days (0 = disabled) | `0` |
| **Ticket Retention Days** | Delete entire tickets inactive for N days (0 = disabled) | `0` |

Retention jobs run hourly in micro-batches to avoid server timeouts.

### Uninstallation

Check **Delete all plugin data on uninstall** to permanently remove all tickets, attachments, and settings when the plugin is deleted from WordPress.

### Danger Zone

| Action | Effect |
|--------|--------|
| **Purge All Tickets** | Permanently deletes every ticket and its attachments |
| **GDPR Email Purge** | Deletes all tickets, replies, and files associated with a specific email address |
| **Factory Reset** | Resets all settings to defaults; does not delete tickets |

---

## Toast Notifications

After saving settings, a toast notification slides in from the bottom-right corner confirming the save. It auto-dismisses after ~4 seconds and can be closed immediately with the **×** button.

The `?swh_notice=saved` redirect query parameter triggers the toast on page load. The parameter is removed from the URL via `history.replaceState()` so a page refresh does not re-trigger the notification.

---

## Dark Mode

Simple WP Helpdesk renders cleanly in dark mode in three places.

### Frontend (client-facing)

The submission form, client portal, My Tickets dashboard, and lookup form follow the **Portal → Frontend portal theme** setting:

- `Auto` (default) — follow `prefers-color-scheme`.
- `Light` — pin to light regardless of OS preference.
- `Dark` — pin to dark.

### Admin (staff-facing)

The **Portal → Admin theme** setting controls staff pages:

- `Light` / `Dark` — explicit.
- `Auto` — follow the user's WordPress **Profile → Color Scheme**. **Midnight**, **Modern**, and **Ectoplasm** are treated as dark; all others render as light.

### Email

Emails opt in to client dark mode via `<meta name="color-scheme" content="light dark">` and a `@media (prefers-color-scheme: dark)` block emitted by `swh_wrap_html_email()`. Apple Mail (macOS / iOS) honour the directive; webmail clients that strip `<style>` tags fall back to the inlined light theme — readable in both modes.

---

## Accessibility

The plugin targets WCAG 2.2 AA across both light and dark modes.

- **Skip-to-content link** on Settings and Reports pages.
- **`:focus-visible` focus rings** — high-contrast outlines for keyboard users, no outline on mouse clicks.
- **44×44 px touch targets** on CSAT stars, merge toggle, and status filter chips.
- **ARIA live regions** announce SLA badge transitions and KPI updates.
- **Semantic fieldsets + legends** group every settings tab.
- **Heading hierarchy** audited — no skipped levels or duplicate `<h1>`s across portal and admin views.
- **Internal-note contrast** raised from AA to AAA (7:1) on the amber background.
