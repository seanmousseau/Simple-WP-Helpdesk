# v3.x Roadmap (living document)

Polish, accessibility, and v4 foundation lane. No breaking changes — all of v3.x stays on PHP 7.4 / WP 5.3 minimums. Breaking work is reserved for v4.0.

Last updated: 2026-05-04

---

## v3.5.0 — Consistency & Feedback ✅ SHIPPED 2026-04-23

Milestone: [#16](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/16) — 10/10 closed
PR: [#387](https://github.com/seanmousseau/Simple-WP-Helpdesk/pull/387)

Design tokens, badges, skeleton loaders, toast notifications, accessibility primitives, expanded E2E coverage. WCAG contrast fixes (2.16:1 → 4.5:1).

---

## v3.6.0 — Dark Mode Expansion & A11y Round 2

Milestone: [#17](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/17) — 0/10 closed
Status: **Active**
Theme: Finish what v3.4 started on dark mode, plus the WCAG AA gaps surfaced during the v3.5 audit. Pure UX/a11y polish — no architectural changes.

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

---

## v3.7.0 — v4 Foundations

Milestone: [#22](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/22) — 0/7 closed
Status: **Planned (after v3.6 ships)**
Theme: Non-breaking groundwork that de-risks v4.0 / v4.1. **Why this exists:** v3.6 is pure cosmetic polish. v4.0 ships breaking changes (PHP/WP bumps, settings schema split, new admin paradigm) on top of a codebase that doesn't yet have the primitives those features assume. v3.7 lands the primitives so v4.0 issues build on a stable foundation, and gives anyone integrating with the plugin a runway to adopt new APIs before v4.0 lands.

### Hooks (the load-bearing one)

- [ ] [#361](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/361) — Lifecycle action hooks (reply, status, assign, close, reopen, SLA, CSAT) — **moved from v4.1**

### Architecture decisions

- [ ] [#390](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/390) — JS architecture spike — pick build/state pattern before v4.0 admin UI work starts
- [ ] [#391](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/391) — Introduce `swh_get_option()` helper as read-through wrapper (de-risks v4.0 schema split)
- [ ] [#393](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/393) — Deprecation helper — wrap `apply_filters_deprecated()` and `do_action_deprecated()`
- [ ] [#394](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/394) — PSR-4 autoload for plugin classes

### Documentation + measurement

- [ ] [#392](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/392) — Component inventory — document existing primitives before v4.0 sprawl
- [ ] [#395](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/395) — Performance baseline — capture timings before v4.0 inbox redesign

### Why these specifically

| Item | What v4.x needs it for | Why now (v3.7) vs later |
|------|----------------------|-----|
| Lifecycle hooks | All of v4.1 + v4.2 builds on them | Hooks are a one-way door once public — ship clean and let them settle a release before REST + webhooks consume them |
| JS architecture | v4.0 inbox, command palette, saved views, drawer | Without a decision, each v4.0 issue reinvents the wheel |
| `swh_get_option()` | v4.0 schema split ([#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356)) | Land the signature now (still backed by monolithic option) so v4.0 only changes the implementation |
| Component inventory | Every v4.0 UI issue | Prevents ad-hoc primitives + indigo-refresh fights |
| Deprecation helper | v4.0 hook deprecations ([#360](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/360)) | Makes the deprecations mechanical |
| PSR-4 autoload | v4.0/v4.1 will add ~10 new classes | Cleaner bootstrap, contributors don't fight `require_once` |
| Perf baseline | v4.0 inbox claims "500+ without lag" | Need numbers to regress against |

### Notes

- All v3.7 work is non-breaking — same PHP/WP minimums.
- v3.7 unblocks (or de-risks) every milestone in the v4.x lane. See `release_v4.x.x_roadmap.md`.

---

## Beyond v3.7

The next release after v3.7 is **v4.0.0**, which raises PHP/WP minimums and is allowed to ship breaking changes. Anything surfacing as a v3.x candidate after v3.7 should be evaluated against:

1. Can it ship without breaking changes? → New v3.8.0 issue.
2. Does it require PHP 8.0 / WP 6.0 / schema migration? → v4.x roadmap.
3. Is it speculative / not yet validated? → `new_features_discussion.md`.

Cheap v3.x candidates already identified in `new_features_discussion.md`: **CSAT trend reporting** (data exists, just needs a chart) and **richer canned responses with variable substitution** (reuses the email template parser).

---

## Related internal docs

- `release_v4.x.x_roadmap.md` — major-lane roadmap; v4.x depends on v3.7 foundations
- `parked_features.md` — features intentionally deprioritized (Zapier, etc.)
- `new_features_discussion.md` — ideas not yet on any roadmap
- `lessons-learned.md` — gotchas, post-mortems, things that bit us

---

## How this doc stays current

- Update the milestone tables when a release ships (mark ✅ SHIPPED + date + PR).
- Issue status reflects GitHub — not duplicated here. This doc is a navigation index, not a source of truth for state.
- When a release closes, archive its section under a "Shipped" header at the bottom and bring the next planning section up top.
