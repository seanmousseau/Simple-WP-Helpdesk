---
title: Email Setup
nav_order: 8
---

# Email Setup

Simple WP Helpdesk relies on WordPress to actually deliver email. **WordPress's default `wp_mail()` uses the server's `sendmail`, which is unreliable on most hosts** — emails land in spam, are silently dropped, or fail outright. Getting outbound email right is the single most impactful thing you can do for your helpdesk's user experience.

This page walks through configuring outbound email through a transactional mail service so clients actually receive notifications.

---

## Why default email usually fails

Most shared and managed WordPress hosts either:

- Block `sendmail` outright,
- Send mail from a generic IP that's on every major spam blocklist, or
- Allow mail to leave but with a `From:` address that fails SPF/DKIM/DMARC checks at the recipient's mail provider, landing your emails in spam.

The fix is to route `wp_mail()` through a **transactional email provider** with your domain properly authenticated.

---

## Recommended providers

| Provider | Free tier | Notes |
|---|---|---|
| **Postmark** | None (paid only, ~$15/mo) | Best deliverability and analytics. Recommended if budget allows. |
| **SendGrid** | 100/day free | Solid choice. Most popular. |
| **Mailgun** | None (paid only) | Great if you also want inbound email routing (see [Inbound Webhook](inbound-webhook)). |
| **Brevo (Sendinblue)** | 300/day free | Good free tier. EU-based. |
| **Amazon SES** | Pay-as-you-go ~$0.10/1000 | Cheapest at scale; more setup work. |

If you're already running an email-marketing platform (Mailchimp, ConvertKit), they often have transactional add-ons too.

---

## Step 1 — Pick the connector plugin

You need a WordPress plugin that intercepts `wp_mail()` and routes it through your provider.

**Recommended:** [WP Mail SMTP by WPForms](https://wordpress.org/plugins/wp-mail-smtp/). It's the most popular, has built-in connectors for every major provider, and has a free tier sufficient for most sites.

Alternatives: [Post SMTP](https://wordpress.org/plugins/post-smtp/), [FluentSMTP](https://wordpress.org/plugins/fluent-smtp/).

Install and activate one of these. They all work the same way — pick whichever you prefer.

---

## Step 2 — Configure the connector

The exact steps vary by provider; WP Mail SMTP has setup wizards for each. The general flow:

1. Sign up with the provider, verify your sending domain, and get an **API key** (or SMTP credentials).
2. In WP Mail SMTP, pick your provider from the **Mailer** options.
3. Paste your API key / SMTP credentials.
4. Set the **From Email** to an address on your verified sending domain (e.g. `support@yourcompany.com`, not `noreply@gmail.com`).
5. Set the **From Name** (e.g. *YourCompany Support*).
6. Save.

> The **From Email** must be on the same domain you verified with the provider. Most providers will reject sends from unverified domains, and even when they don't, recipients' spam filters will.

---

## Step 3 — DNS records (SPF, DKIM, DMARC)

These three records on your domain's DNS are what tell other mail servers "this email really did come from us." Without them, your mail goes to spam.

### SPF

Add a `TXT` record to your domain that authorises your provider to send mail on your behalf. The provider gives you the exact value. Example for SendGrid:

```
v=spf1 include:sendgrid.net ~all
```

If you already have an SPF record (e.g. for Google Workspace), **merge** the new `include:` into the existing record — you can only have one SPF record per domain.

### DKIM

Your provider generates a public/private key pair. You add the **public key** as a DNS record (usually a `CNAME` or `TXT`); the provider keeps the private key and signs your outbound mail with it.

WP Mail SMTP's setup wizards walk you through this. Postmark, SendGrid, and Mailgun all give you copy-pasteable DNS entries.

### DMARC

Tells receiving mail servers what to do if SPF or DKIM fails. Start in monitoring mode:

```
v=DMARC1; p=none; rua=mailto:dmarc-reports@yourcompany.com
```

After a week or two of monitoring reports without surprises, you can tighten to `p=quarantine` (suspicious mail goes to spam) and eventually `p=reject` (suspicious mail is dropped).

---

## Step 4 — Send a test email

In SWH:

1. Go to **Tickets → Settings → Email Templates**.
2. Click **Send Test Email**.
3. Check your inbox.

The test email goes to your WordPress admin's account email. If it doesn't arrive:

- Check spam first.
- Check the provider's dashboard for delivery failures.
- Look in `wp-content/debug.log` if you have `WP_DEBUG_LOG` enabled.

If it lands in spam:

- Wait a few minutes for DNS to propagate after you add records.
- Use a tool like [Mail-Tester](https://www.mail-tester.com/) to score your setup. Aim for 9/10 or higher.
- Verify your DNS records using `dig TXT yourcompany.com`, `dig TXT _dmarc.yourcompany.com`, and your provider's DNS validation tool.

---

## Step 5 — Configure SWH email branding

Once delivery is working:

1. Go to **Tickets → Settings → Email Templates**.
2. Set **Logo URL** to a hosted version of your logo. Use HTTPS. Keep the file under 100 KB. PNG or SVG work best.
3. Optionally customize the **Footer Note**.
4. Review the 14 email templates and tweak the subject lines or bodies if needed. **Keep `[TKT-{ticket_id}]` in subjects** — it's required for the inbound email webhook to thread replies back to tickets.

See [Configuration → Email Templates](configuration#email-templates) for the full template reference.

---

## Troubleshooting

### Emails are sent but never arrive

- Check the provider's logs. Most show every send and its delivery status (sent, bounced, dropped, deferred).
- Check spam folder.
- Verify the recipient's mail server isn't blocking your sending IP. Some providers rotate IPs; this is rare.

### Emails arrive but go to spam

- Recheck DNS — SPF, DKIM, DMARC all need to be valid.
- Use a content scanner like [Mail-Tester](https://www.mail-tester.com/) to score the actual rendered email.
- If your email contains lots of links to short URLs, redirector domains, or known-spammy phrases, content filters may flag it. Edit the template language.

### Inbound replies don't thread back to tickets

That's a separate system — see [Inbound Email Webhook](inbound-webhook) for setup.

### The "Send Test Email" button returns an error

Check `wp-content/debug.log`. Usually it's an authentication failure (wrong API key) or a connection timeout (firewall rules).

---

## See also

- [Configuration → Email Templates](configuration#email-templates) — template editing reference
- [Inbound Email Webhook](inbound-webhook) — accept replies via email
- [Troubleshooting](troubleshooting) — broader issue list
