# Manual Visual Regression Suite

A human-in-the-loop visual sweep run **once per release**, immediately before the `dev → main` PR is opened. Driven by the Playwright MCP against the dev WordPress instance. Output: a folder of dated screenshots under `releases/vX.Y.Z/visual/` plus a sign-off block in the `dev → main` PR description.

**This suite is non-negotiable** — see `planning-and-release-process.md` Gate 3.

---

## Why this suite exists

Automated E2E catches functional regressions (clicks, forms, REST responses). It does **not** catch:

- CSS regressions on previously-working layouts.
- Dark-mode token regressions.
- Spacing / contrast / focus-ring drift introduced by unrelated CSS edits.
- Visual breakage in WP admin chrome states that no E2E spec asserts on.
- Email-template rendering issues that look fine in MailHog's plain renderer but ugly in a real inbox.

The manual suite covers exactly those gaps with a fixed list of pages to capture in fixed states.

---

## Prerequisites

| | Value |
|---|---|
| Dev WP instance | `https://dev-direct.seanmousseau.com/` (see global `~/.claude/CLAUDE.md`) |
| Auth | `~/.claude/dev-secrets.env` for HTTP Basic on `/claude/`; WP login via temporary token |
| Browser | Playwright MCP (`mcp__plugin_playwright_playwright__*`) at 1440×900 |
| Output dir | `releases/vX.Y.Z/visual/` |

Before starting:

1. Confirm the release branch is fully deployed to the dev instance (`git log` on dev matches `release/vX.Y.Z` HEAD).
2. Flush WP object cache (`wp cache flush`).
3. Seed at least one ticket per status (`Open`, `In Progress`, `Resolved`, `Closed`) plus one CSAT-rated ticket and one SLA-breached ticket.
4. `mkdir -p releases/vX.Y.Z/visual/{settings,admin,portal,email,darkmode}`

---

## Screenshot checklist

For every screenshot below, save as `vX.Y.Z-<group>-<slug>.png` under the corresponding subfolder.

### Settings (one per tab)

| Slug | Page | Notes |
|---|---|---|
| `01-general` | Settings → General | Defaults, no in-progress edits |
| `02-routing` | Settings → Assignment & Routing | At least one auto-assignment rule visible |
| `03-portal` | Settings → Portal | CSAT enabled, Auto theme |
| `04-emails` | Settings → Email Templates | First template expanded |
| `05-messages` | Settings → Messages | All keys visible |
| `06-antispam-honeypot` | Settings → Anti-Spam (Honeypot selected) | — |
| `06-antispam-recaptcha` | Settings → Anti-Spam (reCAPTCHA v2 selected) | Conditional rows visible |
| `06-antispam-recaptcha-enterprise` | Settings → Anti-Spam (reCAPTCHA Enterprise) | Threshold row visible |
| `06-antispam-turnstile` | Settings → Anti-Spam (Turnstile) | — |
| `07-integrations` | Settings → Integrations | Webhook URL + secret + coming-soon block |
| `08-canned` | Settings → Canned Responses | At least one entry |
| `09-templates` | Settings → Templates | At least one entry |
| `10-tools` | Settings → Tools | Retention values + danger zone visible |

### Admin chrome

| Slug | Page |
|---|---|
| `inbox-light` | Tickets list, light mode, default sort |
| `inbox-unread` | Tickets list with at least one unread (blue dot) row |
| `inbox-sla-warn` | Tickets list with an SLA-warn badge visible |
| `inbox-sla-breach` | Tickets list with an SLA-breach badge visible |
| `editor-open` | Ticket editor, Open ticket, full conversation visible |
| `editor-resolved` | Ticket editor, Resolved ticket, with internal note |
| `editor-closed` | Ticket editor, Closed ticket |
| `merge-ui` | Ticket editor with the Merge panel open |
| `reports` | Reports page, all KPI cards loaded |

### Portal (frontend)

| Slug | Page |
|---|---|
| `submit-form` | `[submit_ticket]` empty form, light mode |
| `submit-form-with-template` | Same with a Request Type selected |
| `lookup-form` | `[helpdesk_portal]` with lookup form visible |
| `portal-active` | Portal view of an Open ticket (token in URL) |
| `portal-resolved` | Portal view of a Resolved ticket — Close block visible |
| `portal-closed` | Portal view of a Closed ticket — Reopen block visible |
| `csat-prompt` | CSAT widget visible (just after close) |
| `csat-thanks` | Post-rating thank-you state |
| `my-tickets` | Logged-in dashboard with at least 2 tickets |

### Email rendering

For each, send via the **Send Test Email** button (Settings → Email Templates) and capture:

| Slug | Template | Capture |
|---|---|---|
| `email-newticket-light` | New ticket (client) | Apple Mail / Gmail web, light |
| `email-newticket-dark` | New ticket (client) | Apple Mail dark |
| `email-reply-light` | Technician replied | Apple Mail light |
| `email-resolved-light` | Ticket resolved | Apple Mail light |
| `email-autoclose-light` | Ticket auto-closed | Apple Mail light |

### Dark mode parity

For each light-mode capture below, also capture the dark-mode equivalent (`-dark` suffix):

- `settings/01-general`
- `settings/03-portal`
- `settings/07-integrations`
- `admin/inbox-light` (capture `inbox-dark`)
- `admin/editor-open` (capture `editor-open-dark`)
- `portal/submit-form` (capture `submit-form-dark`)
- `portal/portal-active` (capture `portal-active-dark`)

For frontend: toggle OS appearance to Dark (or set portal theme to `Dark`).
For admin: switch the active WP user's Profile → Color Scheme to **Midnight**.

---

## Sign-off

After capturing every screenshot:

1. Open the previous release's `releases/v<prev>/visual/` folder side-by-side.
2. Diff each screenshot visually. A diff that wasn't called out in the release plan is a regression — **file an issue against the current milestone before merging**.
3. Paste the following block into the `dev → main` PR description, replacing the placeholders:

```text
## Visual regression sign-off (vX.Y.Z)

- Captured: <date>
- Captured by: <agent or human>
- Folder: releases/vX.Y.Z/visual/
- Diffs reviewed: yes
- Unexpected diffs: <count> (issues: #NNN, #NNN) — or "none"
```

`dev → main` cannot merge until this block is present.

---

## Automating capture (Playwright MCP)

The capture itself is scripted via the Playwright MCP. Pseudo-flow:

```text
1. browser_resize 1440x900
2. browser_navigate <wp-admin URL with one-time login token>
3. For each entry in the checklist:
   a. browser_navigate <page URL>
   b. browser_evaluate — hide cache/admin notices; ensure dark-mode toggles are set
   c. browser_take_screenshot — save under releases/vX.Y.Z/visual/<group>/<slug>.png
4. For dark-mode rows: set portal/admin theme, repeat capture loop.
5. For email rows: navigate to Settings → Email Templates → Send Test Email → MailHog → capture rendered email.
```

The diff step is intentionally human — automated pixel diffing would block on noise. The whole point of this suite is to put eyes on the output.

---

## Related

- `extended-test-plan.md` — release-candidate functional sign-off (different from this visual suite, runs at Gate 2)
- `testing-overview.md` — top-level testing map
- `planning-and-release-process.md` — release gates
