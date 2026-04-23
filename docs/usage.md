---
title: Usage Guide
nav_order: 4
---

# Usage Guide

---

## For Clients / End Users

### Submitting a Ticket

1. Go to the Support page on the website.
2. Fill in your **Name**, **Email**, **Priority**, **Summary** (title), and **Description**.
3. If ticket templates are configured, select a **Request Type** to pre-fill the description.
4. Optionally click **Choose Files** (or drag and drop) to attach screenshots or documents.
5. Click **Submit Ticket**.

You will receive an email confirmation containing a secure tracking link unique to your ticket.

### Viewing and Replying to a Ticket

1. Open the confirmation email and click the secure tracking link.
2. Your private ticket portal shows the current status, priority, and the full conversation history. Reply timestamps display as relative times ("3 hours ago", "Yesterday", etc.) with the exact date shown on hover.
3. To reply, type your message in the reply box, optionally attach files, and click **Send Reply**.

### My Tickets Dashboard

If you visit the helpdesk portal page without a ticket link and are logged in to WordPress, you will see a card-based list of your open tickets with direct links. If you are not logged in, you will see the **Resend my ticket links** lookup form — enter your email address to have your secure links resent.

### Closing a Ticket

When a technician marks your ticket as **Resolved**, a prominent **Close Ticket** block appears at the top of the portal. Click **Close Ticket** to formally close the ticket and notify the team. If you still need help, use the **Still need help? Reply below** link to send another reply instead.

After closing, you may be shown a 1–5 star satisfaction prompt. Rating is optional — click **Skip** to dismiss it.

### Re-Opening a Ticket

If a ticket is **Closed** but your issue is unresolved, the standard reply form is replaced with a **Re-Open Ticket** form. You can optionally explain why you need it re-opened, but submitting without a message is also accepted. The ticket status is updated and the support team is notified.

---

## For Technicians / Administrators

### Viewing Tickets

1. Log into the WordPress dashboard and click **Tickets**.
2. The ticket list shows all tickets with their status, priority, and assignee.
3. Click a ticket title to open the Ticket Editor.

**Filtering and sorting:** Use the filter dropdowns above the list to narrow by status, priority, assignee, or category. Column headers (Status, Priority, Date) are clickable for sorting. A blue dot marks tickets with unread client replies.

**Bulk status change:** Check multiple tickets and use the **Bulk Actions** dropdown to set them all to any configured status in one action.

### Updating Ticket Details

The **Ticket Details** panel (right-hand side) contains:
- Client name and email
- Assignee dropdown
- Priority and Status dropdowns
- Category assignment
- Links to all attached files

After making changes, click **Update** at the top-right to save.

### Public Replies

1. Scroll down to the **Conversation & Reply** section.
2. Type your reply in the **Reply** text box. Attach files if needed.
3. Click **Send Reply** (or the main **Update** button) to save and email the reply to the client.

> **Tip:** If you change the status to Resolved or Closed at the same time as sending a reply, the system sends a single combined email rather than two separate notifications.

### Internal Notes

Type in the **Internal Note** box to leave a note visible only to logged-in technicians. Internal notes appear in the conversation thread with a distinct yellow background. Clients never see them and receive no email notification.

### Canned Responses

If canned responses are configured in **Settings → Canned Responses**, a **Canned Response** picker appears in the ticket editor. Click it, select a template, and its body is inserted into the active reply or note field.

### Creating Tickets on Behalf of Clients

1. Go to **Tickets → Add New**.
2. Enter the ticket **Title** (summary) and **Description** in the standard post editor.
3. In the **Ticket Details** panel, enter the client's **Name** and **Email**.
4. Check **Send confirmation email to client** if you want the client to receive their portal link immediately.
5. Set the desired **Priority**, **Status**, and **Category**, then click **Publish**.

The ticket UID, secure token, and portal URL are auto-generated on the first save.

### Categories / Departments

Assign one or more **Categories** to a ticket using the category meta box in the ticket editor. Categories are hierarchical, appear as a column in the ticket list, and can be used as a filter criterion.

Configure categories at **Tickets → Categories**. Auto-assignment rules (in **Settings → Assignment & Routing**) can automatically assign tickets to specific technicians based on category.

### SLA Monitoring

When SLA thresholds are configured (**Settings → General → SLA Warn/Breach Hours**), the hourly cron check flags tickets that have been open beyond the thresholds:

| State | Visual indicator |
|-------|-----------------|
| **SLA Warning** | Yellow `.swh-badge-sla-warn` badge on the ticket |
| **SLA Breach** | Red `.swh-badge-sla-breach` badge on the ticket |

An admin digest email is sent when tickets breach the SLA threshold.

### Ticket Merge

If a client submits a duplicate ticket, you can merge it into the original:

1. Open the ticket you want to **remove** (the source/duplicate).
2. Scroll to the **Merge Ticket** section in the ticket editor.
3. Enter the **Target Ticket ID** (the ticket to keep all replies on).
4. Click **Merge**. All replies from the source ticket are moved to the target; the source ticket is then closed.

### Reporting Dashboard

Go to **Tickets → Reports** to view the reporting dashboard:

| Report | Description |
|--------|-------------|
| **KPI Cards** | Total tickets, Open tickets, Avg resolution time, Avg first response time |
| **Status Breakdown** | Doughnut chart showing ticket counts by status |
| **Weekly Trend** | Bar chart of ticket volume over the past 7 days |
| **Avg Resolution Time** | Average time from ticket creation to resolution |
| **Avg First Response** | Average time from ticket creation to first staff reply |

Report data is cached for one hour. The KPI cards load asynchronously after page load.

### Inbound Email Webhook

Replies sent to a dedicated address can be forwarded to the plugin's inbound webhook at:

```
POST /wp-json/swh/v1/inbound-email
```

See the [Developer Guide](development#inbound-email-webhook) for setup details.
