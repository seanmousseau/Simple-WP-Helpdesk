---
title: FAQ
nav_order: 12
---

# Frequently Asked Questions

Common questions, answered.

If your question isn't here, check [Troubleshooting](troubleshooting) for issue-specific help, or open a [GitHub issue](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues).

---

## General

### Does this plugin create custom database tables?

No. Simple WP Helpdesk uses WordPress core data structures exclusively — custom post types, comments, post meta, comment meta, options, and transients. This is a deliberate design decision documented in [ADR-0001](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/docs/internal/adr/0001-no-custom-db-tables.md).

Practical implications: your existing WP backup plugin backs up SWH data. Search-and-replace tools work. Multisite migrators work. WP-CLI works. The data export framework works.

### Does it work with multisite?

The plugin is designed for **single-site** use. It hasn't been formally tested or supported on multisite networks. It may work, but no guarantees.

### Can clients view and reply to tickets without a WordPress account?

Yes. The client portal uses token-based authentication — clients click a link in their notification email and have full access to view, reply, close, and reopen. No WordPress account or login required.

Logged-in WordPress users do get an additional **My Tickets** dashboard listing all their tickets.

### Does it work with page builders?

Yes. The `[submit_ticket]` and `[helpdesk_portal]` shortcodes work with Elementor, Divi, Beaver Builder, Bricks, and others. Place them inside a **Shortcode** block / widget — not a raw HTML / text widget, since the shortcode parser needs to fire.

### Is it accessible?

The plugin targets **WCAG 2.2 Level AA** in both light and dark themes, on both the admin and frontend. Specifically: semantic fieldsets, ARIA labels, keyboard navigation, focus rings, 44×44 px touch targets on key controls, and screen-reader support.

Known limitations: we don't formally test against NVDA / JAWS on every release (VoiceOver only). High-contrast modes beyond the built-in dark theme aren't specifically tested.

### Is it translatable?

Yes. Every user-facing string is wrapped in WordPress translation functions with the `simple-wp-helpdesk` text domain. A `.pot` file is shipped under `simple-wp-helpdesk/languages/`. RTL layouts use logical CSS properties.

---

## Submission & client experience

### Can clients upload files?

Yes. Default allowed types: JPG, PNG, GIF, PDF, DOC, DOCX, TXT. Default size limit: 5 MB per file, 5 files per submission. Both limits are configurable in **Settings → General**.

To allow more file types, use the `swh_allowed_file_types` filter — see [Hooks Reference](hooks). The MIME check is enforced by WordPress core's `wp_handle_upload()`, so adding extensions without matching MIMEs won't help.

### Are file downloads protected?

Yes. Files are stored in `wp-content/uploads/swh-helpdesk/`, protected by a `.htaccess` deny-all. Direct URLs return 403. All downloads go through a token-gated proxy — clients need their portal token (or staff need `edit_post` capability) to download.

### What happens to portal links over time?

Portal tokens expire after **90 days** by default (configurable). After expiry:

- The client sees an error and is automatically redirected to the **lookup form**.
- They enter their email → receive a fresh portal link.

Tokens also **rotate** on every successful lookup. Old links become invalid after the client uses the lookup form to get a new one.

### Can clients reply by email?

Yes, with the [Inbound Email Webhook](inbound-webhook). Configure your mail provider (Mailgun, SendGrid, Postmark) to POST inbound mail to the SWH endpoint, and replies thread back to the original ticket automatically. The subject must include `[TKT-XXXX]` (default templates do).

---

## Admin / operator

### Can technicians be restricted to only their assigned tickets?

Yes. Enable **Restrict Technicians** in **Settings → General**. Users with the `technician` role then only see tickets where `_ticket_assigned_to` matches their user ID. Administrators always see all tickets.

### Can I automatically assign tickets based on category?

Yes. Set up **Auto-Assignment Rules** in **Settings → Assignment & Routing**. Map a category to a technician — every new ticket in that category gets that technician. First-match wins; tickets that don't match any rule fall to the default assignee.

See the [Categories](categories) guide.

### Can I customize email templates?

Yes. **Settings → Email Templates** exposes all 14 templates (subject + body) with placeholder support (`{name}`, `{ticket_id}`, `{message}`, etc.) and conditional `{if key}...{endif key}` blocks. See [Configuration → Email Templates](configuration#email-templates).

Critically: **keep `[TKT-{ticket_id}]` in subject lines.** It's required for inbound email replies to thread back to the right ticket.

### How do I report on resolution time / SLA?

The Reports dashboard (**Tickets → Reports**) shows:

- Total tickets, open tickets
- Average resolution time
- Average first-response time
- Status breakdown (doughnut chart)
- Weekly trend (bar chart)

SLA warn / breach thresholds are configurable in **Settings → General**. Breached tickets are highlighted in the ticket list with a colored badge, and a daily digest email summarizes breaches.

### Can I merge duplicate tickets?

Yes. Open the source ticket → scroll to the **Merge Ticket** section → enter the target ticket ID → click **Merge**. All replies move to the target; the source ticket is closed with a breadcrumb comment on both sides.

### How do I do GDPR data purges?

**Settings → Tools → GDPR Email Purge** — enter an email address, confirm, all tickets and attachments associated with that address are deleted.

For broader retention: **Settings → Tools** also exposes **Ticket Retention Days** and **Attachment Retention Days** for automated cleanup.

---

## Configuration

### How many email templates are there?

14: 8 client-facing (new ticket received, technician replied, status changed, resolved, reopened, auto-closed, closed-by-client, combined reply+status) and 6 admin-facing (new ticket, client replied, reopened, closed, assigned, auto-closed). See [Configuration → Email Templates](configuration#email-templates).

### Can I use my own dark mode?

The plugin's dark mode is controlled separately for frontend (portal) and admin:

- **Frontend portal theme**: `Auto` (follows OS preference), `Light`, or `Dark`. **Settings → Portal → Theme**.
- **Admin theme**: `Light`, `Dark`, or `Auto` (follows WP user's Profile → Color Scheme). **Settings → Portal → Theme**.

Auto-mode in the admin treats WP's Midnight, Modern, and Ectoplasm color schemes as dark.

### Does the plugin work with Gutenberg / block editor?

The shortcodes `[submit_ticket]` and `[helpdesk_portal]` work in any block-editor Shortcode block. Dedicated Gutenberg blocks for these are on the roadmap.

The ticket editor in the admin is currently the classic post editor, not Gutenberg. This is intentional — Gutenberg's autosave and revision behaviors don't fit the helpdesk-reply pattern well.

### Can I integrate with Slack / Teams / Discord / Zapier?

Native integrations are on the roadmap for v4.3. In the meantime:

- The plugin fires action hooks for every lifecycle event (`swh_ticket_created`, `swh_ticket_replied`, `swh_ticket_status_changed`, etc.) — wire a small custom plugin to those hooks and POST to whatever endpoint you need.
- The [inbound email webhook](inbound-webhook) accepts replies from any provider.
- A public REST API for tickets is planned for v4.1.

See the [project roadmap](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/docs/internal/roadmap.md).

---

## Pricing & licensing

### Is it free?

Yes. The plugin is licensed under [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html) and has no premium add-ons, no SaaS dependency, no licensing server.

### Can I use it on commercial sites?

Yes. GPL allows commercial use. There's no separate commercial license.

### How is development funded?

Sponsorships via [GitHub Sponsors](https://github.com/sponsors/seanmousseau) and personal time. If the plugin has saved you money on a paid alternative, consider sponsoring.

---

## Reporting bugs & contributing

### How do I report a bug?

Open an issue on [GitHub](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/new/choose) using the **Bug report** template.

For **security vulnerabilities**, do not open a public issue. Use [GitHub Security Advisories](https://github.com/seanmousseau/Simple-WP-Helpdesk/security/advisories/new). See [SECURITY.md](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/SECURITY.md) for the disclosure process.

### How do I request a feature?

Open an issue using the **Feature request** template. Before submitting, skim the [roadmap](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/docs/internal/roadmap.md) — your idea may already be planned or parked.

### How do I contribute code?

See [CONTRIBUTING.md](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/CONTRIBUTING.md). The short version: fork → branch from `dev` → PR back into `release/<next-version>` → run the local test gate before pushing.

---

## See also

- [Troubleshooting](troubleshooting) — issue-specific debugging
- [Configuration](configuration) — every setting explained
- [Inbound Email Webhook](inbound-webhook) — accept replies via email
- [Email Setup](email-setup) — outbound email deliverability
