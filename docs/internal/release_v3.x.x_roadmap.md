# v3.x Roadmap (living document)

Polish, accessibility, and consistency lane. No breaking changes — all of v3.x stays on PHP 7.4 / WP 5.3 minimums. Breaking work is reserved for v4.0.

Last updated: 2026-05-04

---

## v3.5.0 — Consistency & Feedback ✅ SHIPPED 2026-04-23

Milestone: [#16](https://github.com/seanmousseau/seanmousseau/Simple-WP-Helpdesk/milestone/16) — 10/10 closed
PR: [#387](https://github.com/seanmousseau/Simple-WP-Helpdesk/pull/387)

Design tokens, badges, skeleton loaders, toast notifications, accessibility primitives, expanded E2E coverage. WCAG contrast fixes (2.16:1 → 4.5:1).

---

## v3.6.0 — Dark Mode Expansion & A11y Round 2

Milestone: [#17](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/17) — 0/10 closed
Status: **Next up**
Theme: Finish what v3.4 started on dark mode, plus the WCAG AA gaps surfaced during the v3.5 audit.

### Dark mode
- [ ] [#339](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/339) — Admin dark mode (respect WP `admin_color` schemes — midnight/modern)
- [ ] [#340](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/340) — Email HTML `color-scheme: light dark` metadata + media query

### Accessibility (WCAG 2.2 AA)
- [ ] [#341](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/341) — Skip-to-content link on Reports + Settings
- [ ] [#342](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/342) — CSAT widget focus management after submit
- [ ] [#343](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/343) — Heading hierarchy audit across portal + admin
- [ ] [#344](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/344) — `aria-live` on SLA badge + KPI card updates
- [ ] [#345](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/345) — `:focus-visible` for mouse vs keyboard focus rings
- [ ] [#346](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/346) — Portal token-expiry focus moves to lookup form
- [ ] [#347](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/347) — Touch-target audit (≥44×44 WCAG AA)
- [ ] [#348](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/348) — Contrast audit of internal-note tokens

### Notes
- All v3.6 work is non-breaking — same PHP/WP minimums.
- Dark mode admin pairs with the badge hex purge from v3.5 (already done) — inline hex values won't theme correctly.
- Acceptance criteria for every issue includes a Playwright section.

---

## Beyond v3.6

No additional v3.x minor releases planned. The next release after v3.6 is **v4.0.0**, which raises PHP/WP minimums and is allowed to ship breaking changes. Anything that surfaces as a v3.x candidate after v3.6 should be evaluated against:

1. Can it ship without breaking changes? → New v3.7.0 issue.
2. Does it require PHP 8.0 / WP 6.0 / schema migration? → v4.x roadmap.
3. Is it speculative / not yet validated? → `new_features_discussion.md`.

---

## How this doc stays current

- Update the milestone link table when a release ships (mark ✅ SHIPPED + date + PR).
- Issue status reflects GitHub — not duplicated here. This doc is a navigation index, not a source of truth for state.
- When v3.6 closes, archive its section under a "Shipped" header at the bottom and bring the next planning section up top.
