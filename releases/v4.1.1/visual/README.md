# v4.1.1 visual regression baseline

Captured 2026-05-28 against `https://dev.seanmousseau.com/testing/wordpress`
after `release/v4.1.1-gate2` was deployed (plugin header reads `4.1.1`).
Driven by Playwright MCP at 1440×900 viewport, full-page screenshots
where applicable.

Coverage (14 surfaces):

| Group | File | Notes |
|---|---|---|
| Settings | `settings/v4.1.1-settings-01-general.png` | Default tab on first load |
| Settings | `settings/v4.1.1-settings-02-routing.png` | Assignment & Routing |
| Settings | `settings/v4.1.1-settings-03-portal.png` | Portal + CSAT controls |
| Settings | `settings/v4.1.1-settings-04-emails.png` | Email Templates (all 14 expanded) |
| Settings | `settings/v4.1.1-settings-05-messages.png` | Operator-facing message strings |
| Settings | `settings/v4.1.1-settings-06-antispam.png` | Honeypot selected (default) |
| Settings | `settings/v4.1.1-settings-07-integrations.png` | Inbound webhook + REST API keys section |
| Settings | `settings/v4.1.1-settings-08-canned.png` | Canned Responses list |
| Settings | `settings/v4.1.1-settings-09-templates.png` | Submission templates list |
| Settings | `settings/v4.1.1-settings-10-tools.png` | Retention + Danger Zone |
| Admin | `admin/v4.1.1-admin-inbox-light.png` | Tickets inbox, default sort |
| Admin | `admin/v4.1.1-admin-reports.png` | Reports KPI cards + charts |
| Admin | `admin/v4.1.1-admin-api-reference.png` | Tools → Helpdesk API Reference (Redoc, viewport) |
| Admin | `admin/v4.1.1-admin-editor-open.png` | Ticket editor on an Open ticket |

## Visual diff vs. v4.1.0

v4.1.1's three UI-affecting fixes (#535 focus restore, #536 dropped
font preload, #537 drawer dropdown labels) produce **no static
screenshot differences** vs. v4.1.0:

- **#535** is keyboard-focus behavior on a transient modal — invisible
  in a static capture of the Integrations tab.
- **#536** removes a `<link rel=preload>` from the document head;
  visually identical, no console warning anymore.
- **#537** swaps the displayed label text in the drawer dropdown only
  when the dropdown is open. The baseline does not capture the drawer
  open state; closed drawer is identical to v4.1.0.

This baseline therefore stands in for the (never-captured) v4.1.0
baseline as well — future RC sweeps compare against v4.1.1.

## Out of scope for this baseline

- `inbox-unread`, `inbox-sla-warn`, `inbox-sla-breach` states — require
  seeded fixtures and were deferred to the v4.1.2 baseline capture.
- Reply-form variants (`editor-resolved`, `editor-closed`, `merge-ui`)
  — same reason.
- Portal frontend (`portal/*`) — frontend pages with seeded ticket
  tokens are a v4.1.2 task.
- Email rendering captures — require a real-inbox check, deferred.
- Dark-mode variants — covered by `darkmode/` placeholder, captured
  with the v4.1.2 expansion.

The empty `darkmode/`, `portal/`, `email/` folders are placeholders so
the directory layout stays consistent with `docs/internal/visual-regression-suite.md`.
