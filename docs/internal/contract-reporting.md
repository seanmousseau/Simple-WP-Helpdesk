# Contract: Reporting

The **Tickets → Reports** dashboard. KPI cards + four chart panels backed by AJAX endpoints with one-hour caching.

> Touch this contract before adding new KPIs, changing cache TTL, or modifying the chart data shape.

---

## Surface

| Entry point | Render |
|---|---|
| Admin submenu page `Reports` (`admin/class-reporting-ui.php`) | Page layout, asset enqueue, Chart.js loader |
| AJAX endpoint per panel (`admin/class-reporting.php`) | JSON data response |

### Panels

| Panel | KPI / Chart | Data source |
|---|---|---|
| **KPI Cards** (4) | Total tickets, Open tickets, Avg resolution time, Avg first response | Aggregate query on `wp_posts` + `wp_postmeta` |
| **Status Breakdown** | Doughnut chart by status | Group-by `_ticket_status` |
| **Weekly Trend** | Bar chart, last 7 days | Group-by ticket created day |
| **Avg Resolution Time** | Number on KPI card; trend over time computed in same endpoint | Diff between `post_date` and the resolved `swh_ticket_status_changed` timestamp meta |
| **Avg First Response** | Number on KPI card | Diff between `post_date` and first `helpdesk_reply` from a staff user |

---

## AJAX endpoints

All POST, all `nonce`-protected via `wp_create_nonce( 'swh_reporting' )`. All require `current_user_can( 'edit_posts' )` (technicians + admins).

| Action | Returns |
|---|---|
| `swh_report_kpi_cards` | `{ total, open, avg_resolution_seconds, avg_first_response_seconds }` |
| `swh_report_status_breakdown` | `{ labels: string[], counts: number[] }` |
| `swh_report_weekly_trend` | `{ labels: string[], counts: number[] }` |

Endpoint paths are private — these are admin-ajax actions, not REST endpoints. Promotion to the public REST API is in scope for v4.1 (see `roadmap.md`).

---

## Caching

| Cache | TTL | Invalidation |
|---|---|---|
| KPI cards aggregate | 1 hour transient (`swh_report_kpi_cards`) | On every `swh_ticket_status_changed`, `swh_ticket_created` |
| Status breakdown | 1 hour transient (`swh_report_status_breakdown`) | Same |
| Weekly trend | 1 hour transient (`swh_report_weekly_trend`) | Same |

Cache invalidation is **event-driven**, not time-only. After any ticket creation or status transition, the relevant transients are deleted so the next dashboard load is fresh.

A fresh install with no tickets shows zeroes / empty charts, not "loading" — explicit empty state.

---

## Frontend behavior

- The page renders the cards with a "loading" placeholder.
- Each AJAX call fires after `DOMContentLoaded`.
- Chart.js renders on response.
- All charts have explicit `aria-label`s on the `<canvas>` for screen readers.
- KPI value updates announce via `aria-live="polite"` (see `accessibility.md`).

---

## Invariants

1. **All endpoints capability-check.** `current_user_can( 'edit_posts' )` minimum. Technicians may see all reports (no per-assignee filtering today).
2. **All endpoints nonce-check.** Single nonce action `swh_reporting`.
3. **Transients invalidate event-driven.** Don't rely on TTL alone — staleness on UI for an hour after a ticket transition is a regression.
4. **Empty-state must render.** A panel with zero data renders a clear "No data yet" message, not a blank box.
5. **No PII in chart labels.** Status names, dates, and counts only — no client names or emails.

---

## Performance budget

| Endpoint | Target (warm cache) | Target (cold cache) |
|---|---|---|
| KPI cards | <50ms | <500ms |
| Status breakdown | <50ms | <300ms |
| Weekly trend | <50ms | <300ms |

Measured in `performance-baseline.md`. Exceeding the cold-cache budget on a 10k-ticket dataset is a regression.

---

## What integrators may rely on

- The panels enumerated above exist and remain in the admin Reports page.
- KPI cards include at least: total, open, avg resolution, avg first response. Additional cards may be added.
- Capability requirement remains `edit_posts` minimum within v4.x.

## What integrators may NOT rely on

- The AJAX action names (those become deprecated when v4.1 promotes them to REST).
- The chart data shape (Chart.js-friendly today; subject to change when REST endpoints land).
- The exact transient keys.

---

## Routing

| Touching … | Read also |
|---|---|
| Add a KPI or chart | This doc + `data-dictionary.md` (any new meta) + `accessibility.md` (charts) |
| Change cache behaviour | This doc + `performance-baseline.md` |
| Promote endpoints to REST | `roadmap.md` (v4.1) + `api-contract.md` |
| Touch the page layout | `design-guide.md` + `component-inventory.md` |
| Add per-assignee filtering | This doc + `security-model.md` (don't leak others' tickets) |
