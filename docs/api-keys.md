---
layout: default
title: API Keys
nav_order: 60
---

# API keys

Simple WP Helpdesk's REST API (`/wp-json/swh/v1/`) supports two ways to authenticate calls from external systems:

| Use case | Recommended auth |
|---|---|
| A specific operator (real WP user) calling the API | **Application Passwords** — built into WordPress 5.6+, scoped to that user, revocable per device. |
| A service / script / CI worker / third-party integration where no human is logged in | **REST API keys** (this page) — scoped bearer tokens managed in the Helpdesk Settings UI. |

The two are complementary. Application Passwords inherit the WordPress user's role; API keys are independent of any user and carry their own scope (`read` or `read+write`).

## When to use an API key

- Your CI pipeline needs to file tickets when a build breaks.
- A monitoring tool needs to post status updates as replies.
- A third-party form on a marketing site forwards leads to the helpdesk.
- An internal automation closes stale tickets nightly.

If a real human is making the call from their own workstation, prefer an Application Password instead — it ties activity to a named user and shows up under the user's profile.

## Generating a key

1. In WordPress admin, go to **Tickets → Settings → Integrations**.
2. Scroll to the **REST API keys** section.
3. Fill in the **Generate a new API key** form:
   - **Label** (required) — a name you'll recognise later, e.g. "CI integration" or "Acme webhook". Max 60 characters.
   - **Scope** — `Read` lets the key list and view tickets. `Read + Write` also allows creating, updating, and posting replies. Pick the narrowest scope that does the job.
   - **Rate limit override** (optional) — leave blank to use the default of **60 requests per minute**. You can set anything between 1 and 10000 if a particular integration needs a different ceiling.
4. Click **Generate API key**.

A modal will appear with the plaintext key.

## The "you will see this once" warning is literal

The plaintext key is shown **exactly once**, in the modal that opens after Generate. After you close the modal, only the bcrypt hash is stored — there is no way to display the plaintext again.

**Before you close the modal:**

- Click **Copy** (or select the text manually and copy it).
- Paste the key into your password manager or your integration's secrets store immediately.
- If you accidentally close the modal without copying, revoke the key and generate a new one. Don't try to fish it out of the database — the value stored is one-way hashed.

The modal's Close button will ask you to confirm if you haven't copied the key yet. The Escape key and clicking the backdrop both follow the same confirmation flow, so you can't lose the key with a stray keystroke.

## Using the key in API calls

Send the key in the `Authorization` header as a Bearer token:

```bash
curl -H "Authorization: Bearer swhk_aBcD1eFgH2iJkL3mNoP4qRsT5uVwX6yZ" \
     https://example.com/wp-json/swh/v1/tickets
```

The format is `swhk_<32-character random string>`. The leading `swhk_` is intentional so the value is greppable in logs and secret scanners.

Every response includes rate-limit headers (added in v4.1.0 Phase 7):

```
X-RateLimit-Limit:     60
X-RateLimit-Remaining: 59
X-RateLimit-Reset:     1717083600
```

When you exceed the limit, you'll get a `429 Too Many Requests` with a `Retry-After` header.

## Revoking a key

Found in the **Existing keys** table on the same page.

1. Click **Revoke** on the row.
2. Confirm in the modal that opens.
3. The row updates immediately to show "Revoked <date>" and the key stops working for any subsequent API call.

Revocation is **permanent** — there is no unrevoke. If you revoke by mistake, generate a new key and update your integration to use it.

Revoked keys stay in the table (greyed-out) for audit purposes. Future versions of the plugin may add a per-key access log; the revoked rows are the anchor for that.

## Security best practices

- **Scope minimally.** Use `Read` unless the integration genuinely needs to write. Most reporting / dashboard integrations only need read access.
- **Rotate periodically.** Treat keys like passwords. A 90-day rotation cycle is a reasonable default; rotate immediately if you suspect leakage.
- **One key per integration.** Don't share a key between two systems. If one is compromised you only have to revoke and re-issue for that one consumer.
- **Store in a secrets manager,** not in plain config files. Most platforms (GitHub Actions, GitLab CI, AWS Systems Manager, HashiCorp Vault) have a secrets API for exactly this.
- **Watch the `X-RateLimit-Remaining` header** in long-running automations and back off before you hit 429.
- **Audit the table periodically** — if there's a row whose label you don't recognise, revoke it.

## Permissions

Only operators with the `manage_options` WordPress capability can generate or revoke API keys. The Integrations section is the same — non-administrators don't see it.

## Related

- **WordPress Application Passwords:** <https://wordpress.org/documentation/article/application-passwords/>
- **Inbound email webhook** (for receiving replies via email service): see [Inbound webhook](./inbound-webhook.html).
- **REST API reference** (Phase 8 of v4.1.0 — coming soon): will live at `/wp-admin/admin.php?page=swh-api-reference` and on this docs site.
