---
title: Troubleshooting
nav_order: 9
---

# Troubleshooting

---

## Clients are not receiving email notifications

1. **Check WordPress mail delivery.** Many hosts block `wp_mail()` by default. Install a plugin like WP Mail SMTP and configure it with a transactional mail service (Mailgun, SendGrid, Postmark, SES).
2. **Check the Fallback Alert Email.** Go to **Tickets → Settings → Assignment & Routing** and ensure a **Fallback Alert Email** is set in case no assignee is configured.
3. **Check spam folders.** Emails from `wp_mail()` with default configuration often land in spam.
4. **Use the Send Test Email button.** In **Settings → Email Templates**, click **Send Test Email** to verify delivery is working at all.

---

## Portal links in emails lead to a blank page or wrong page

1. Go to **Tickets → Settings → Assignment & Routing**.
2. Make sure **Helpdesk Page** is set to the page containing `[submit_ticket]` or `[helpdesk_portal]`.
3. If the setting is correct but links still go to the wrong page, check whether the page has been moved or its permalink structure changed. Re-saving the setting regenerates future links.

> **Note:** Portal links are generated at the time the ticket is created. Changing **Helpdesk Page** after the fact only affects new tickets. Existing ticket links stored in `_ticket_url` post meta continue to point to the original page.

---

## The ticket submission form is not appearing

1. Confirm the page contains `[submit_ticket]` and that the shortcode is not wrapped in HTML that prevents rendering.
2. Check for JavaScript errors in the browser console — a conflicting plugin may be breaking the page.
3. If using a page builder (Elementor, Divi, etc.), paste the shortcode inside a **Shortcode** block, not a text/HTML widget.

---

## Clients see "Invalid or expired token" when clicking their portal link

Portal tokens expire after 90 days by default. When a token expires:
- The client is shown an error page with a direct link to the ticket lookup form.
- Entering their email on the lookup form resends fresh portal links.

Tokens also rotate automatically on each lookup. If a client bookmarks a link, it may become stale after their next visit.

---

## File uploads are failing

1. Check **Settings → General → Max Upload Size** — the PHP `upload_max_filesize` and `post_max_size` ini values must be at least as large.
2. Confirm the WordPress uploads directory is writable: `wp-content/uploads/swh-helpdesk/`.
3. Check the browser console for JavaScript errors during upload.
4. The default allowed types are: jpg, jpeg, jpe, png, gif, pdf, doc, docx, txt. Use the [`swh_allowed_file_types`](hooks#swh_allowed_file_types) filter to extend this list.

---

## Auto-close is not running

1. WordPress cron requires traffic to trigger. On low-traffic sites, use a real cron job:

   ```bash
   */5 * * * * curl -s "https://yoursite.com/wp-cron.php?doing_wp_cron" > /dev/null
   ```

2. Verify **Auto-Close Days** in **Settings → General** is set to a value greater than `0`.
3. Confirm the configured **Resolved Status** matches a status in your **Ticket Statuses** list exactly (case-sensitive).

---

## SLA breaches are not being flagged

1. Confirm **SLA Warn Hours** and/or **SLA Breach Hours** in **Settings → General** are set to values greater than `0`.
2. The SLA cron (`swh_sla_check_event`) runs hourly. The first check runs up to one hour after plugin activation.
3. Open tickets must be in a status listed in `swh_sla_open_statuses` to be evaluated. Use the [`swh_sla_open_statuses`](hooks#swh_sla_open_statuses) filter to inspect or modify this list.

---

## The anti-spam widget is not showing on the frontend form

1. For **reCAPTCHA v2** and **Turnstile**, confirm both the Site Key and Secret Key are saved in **Settings → Anti-Spam**.
2. Check for Content Security Policy (CSP) headers on your site that may be blocking scripts from `google.com` or `challenges.cloudflare.com`.
3. The widget only renders on the submission form, lookup form, and portal reply form — not on other pages.

---

## The inbound email webhook returns 401

1. Confirm the `Authorization: Bearer <token>` header matches the value stored in **Settings → Assignment & Routing → Inbound Secret**.
2. Apache may strip the `Authorization` header. Add to `.htaccess`:

   ```apache
   SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
   ```

   Or in `wp-config.php`:

   ```php
   $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
   ```

---

## Rate limiting is blocking legitimate users

The default cooldown is 30 seconds per action per IP. On sites behind a load balancer or CDN that sends a shared IP, all users may share one rate limit bucket.

Use the [`swh_rate_limit_ttl`](hooks#swh_rate_limit_ttl) filter to reduce the TTL, or inspect `wp_options` rows with the prefix `swh_rl_` to identify which IPs are being throttled.

---

## The Reporting dashboard shows no data

Report data is cached for one hour. On a fresh install with no tickets, the charts will be empty. Once tickets exist and the cache expires, data will appear.

If data is present but charts are blank, check the browser console for JavaScript errors — Chart.js may have failed to load.

---

## Frequently Asked Questions

**Does this plugin create custom database tables?**
No. Simple WP Helpdesk uses WordPress core data structures exclusively — custom post types, comments, post meta, comment meta, and options.

**Can I use it with a multisite network?**
The plugin is designed for single-site use. It has not been tested in a network/multisite configuration.

**Can clients view and reply to tickets without a WordPress account?**
Yes. The client portal is entirely token-based. No WordPress account is required to view, reply to, close, or reopen a ticket.

**Does it work with page builders?**
Yes. The shortcodes are scoped under `.swh-helpdesk-wrapper` for compatibility with Elementor, Divi, Beaver Builder, and others. Use a Shortcode block.

**Can I add more file types for uploads?**
Yes. Use the [`swh_allowed_file_types`](hooks#swh_allowed_file_types) filter to add extensions. MIME validation is handled by WordPress's `wp_handle_upload()`.

**Can technicians be restricted to only their assigned tickets?**
Yes. Enable **Restrict Technicians** in **Settings → General**. Technicians will only see tickets assigned to them. Admins always see all tickets.

**How do I report a security vulnerability?**
Use [GitHub Security Advisories](https://github.com/seanmousseau/Simple-WP-Helpdesk/security/advisories/new) to report privately. Do not open a public issue for security concerns.
