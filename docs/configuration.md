---
title: Configuration
nav_order: 3
---

# Configuration

Navigate to **Tickets → Settings** in your WordPress dashboard. Settings are organized across eight tabs.

---

## Tab 1: General

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
| **Portal Theme** | `Auto` follows `prefers-color-scheme`; `Force light mode` pins the portal to light | `Auto` |

---

## Tab 2: Assignment & Routing

| Setting | Description |
|---------|-------------|
| **Default Assignee** | Technician automatically assigned to every new ticket |
| **Fallback Alert Email** | Receives new-ticket and client-reply notifications when no assignee is set |
| **Helpdesk Page** | The page clients land on when clicking their portal link. Use the `[helpdesk_portal]` page if you have a dedicated portal, or the `[submit_ticket]` page for a combined layout. All portal links in emails point here. |

**Auto-assignment rules** let you route tickets to specific technicians based on category. Add one or more rules mapping a **Category** to an **Assignee**. The first matching rule wins; if no rule matches, the default assignee is used.

**Email routing priority** (when sending admin notifications):
1. The ticket's assigned technician
2. The default assignee (if set)
3. The fallback alert email (if set)
4. The site admin email

---

## Tab 3: Email Templates

Customize the subject line and body of every email the plugin sends. Click **Reset to default** below any template to restore the original text.

**Available placeholders:**

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

**Conditional blocks:**

Wrap any template section in `{if key}...{endif key}` to include it only when that placeholder has a non-empty value:

```text
{if message}
Message: {message}
{endif message}
```

Unreplaced placeholders are automatically removed from the final output.

**Email events (14 templates, 8 client-facing + 6 admin-facing):**

*Sent to client:*
- New ticket received (confirmation)
- Technician replied
- Status changed (no reply)
- Technician replied + status changed (combined)
- Ticket resolved
- Ticket re-opened
- Ticket auto-closed
- Ticket closed by client (confirmation)

*Sent to technician/admin:*
- New ticket submitted
- Client replied
- Client re-opened ticket
- Client closed ticket
- Ticket assigned to technician
- Ticket auto-closed (admin copy)

**Email branding:** The header band displays your site logo (configured via **Logo URL** in Tab 3) and site name. If no logo URL is set, the site icon is used as a fallback (at 32×32 px). All CSS is inlined for email client compatibility.

**Send Test Email:** Click **Send Test Email** to send a sample message to the currently logged-in admin. The result is shown inline without a page reload.

---

## Tab 4: Messages

Customize the front-end text shown to clients after form actions:

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

## Tab 5: Anti-Spam

| Method | Description |
|--------|-------------|
| **None** | No anti-spam protection |
| **Honeypot** | Invisible hidden field; catches basic bots with no user friction |
| **Google reCAPTCHA v2** | "I'm not a robot" checkbox; requires Site Key + Secret Key |
| **Cloudflare Turnstile** | Privacy-friendly CAPTCHA alternative; requires Site Key + Secret Key |

For reCAPTCHA and Turnstile, paste your public **Site Key** and private **Secret Key** into the respective fields. The widget renders automatically on the frontend form.

Protection applies to: the ticket submission form, the ticket lookup form, and the portal reply/close/reopen forms.

---

## Tab 6: Canned Responses

Pre-written reply templates that speed up ticket responses.

- Click **+ Add Response** to create a new template.
- Enter a **Title** (visible only in the selector) and a **Body** (inserted into the reply field).
- Save via the main **Save Changes** button.

To use a canned response in a ticket, open the ticket editor, click the **Canned Response** picker in the conversation section, and select a template. The body is inserted into the active reply field.

---

## Tab 7: Templates

Pre-configured submission types that pre-fill the ticket description field when a client selects them on the frontend form.

- Click **+ Add Template** to create a new request type.
- Enter a **Label** (shown as the option name in the dropdown) and a **Description** (the pre-filled text).
- Save via the main **Save Changes** button.

When templates are configured, the submission form displays a **Request Type** dropdown. Selecting an option populates the description field with the corresponding template body, which the client can then edit before submitting.

---

## Tab 8: Tools

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

The `swhToast(message, type, duration)` function is available globally on any page that loads `swh-admin.js`:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `message` | string | — | Text to display |
| `type` | `'success'` \| `'error'` \| `'info'` | `'success'` | Visual variant |
| `duration` | number | `4000` | Auto-dismiss delay in ms |

The `?swh_notice=saved` redirect query parameter triggers the toast on page load. The parameter is removed from the URL via `history.replaceState()` so a page refresh does not re-trigger the notification.
