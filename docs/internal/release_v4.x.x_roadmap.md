# v4.x Roadmap (living document)

Major lane. **Breaking changes allowed.** Raises minimums (PHP 8.0, WP 6.0), introduces a new admin paradigm, and lays the integration foundation for everything downstream.

Last updated: 2026-05-25

---

## Lane theme

> Workflow-first admin → eventing → webhooks → native integrations.

Each release builds on the last. The order is load-bearing: **v4.1 needs the v3.7 hooks + v4.0 settings migration, v4.2 needs the v4.1 REST surface, v4.3 needs v4.2 signed webhooks.** Don't reorder.

> ⚠️ **Foundation in v3.7.** Several primitives that v4.x depends on land in v3.7.0 ("v4 Foundations") rather than v4.0 — lifecycle action hooks, `swh_get_option()` helper, deprecation helper, JS architecture, PSR-4 autoload, component inventory, perf baseline. See `release_v3.x.x_roadmap.md` for the full list. v4.x assumes those primitives are in place.

---

## v4.0.0 — Workflow-First Admin

Milestone: [#18](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/18) — 0/12 closed
Theme: Inbox-style triage, command palette, portal v2, modernization. Breaking changes allowed.

See [`docs/internal/component-inventory.md`](component-inventory.md) for the existing component manifest and v4.0 gaps.

### Foundation

- [x] [#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356) — Settings schema migration (`swh_options` → topic sub-options) — **enables v4.1 API key storage**
- [x] [#357](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/357) — Bump PHP minimum to 8.0
- [x] [#358](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/358) — Bump WordPress minimum to 6.0
- [x] [#360](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/360) — Deprecate pre-3.0 filter hooks

### Admin UX rewrite

- [x] [#349](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/349) — Inbox-style Tickets admin page
- [x] [#350](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/350) — Quick-reply drawer from inbox preview
- [x] [#351](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/351) — Command palette (Ctrl/Cmd+K)
- [x] [#352](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/352) — Saved views (per-user filter persistence)
- [x] [#353](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/353) — Sticky bulk-action bar on selection

### Frontend + reporting

- [x] [#354](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/354) — Portal v2 — conversation-first layout
- [x] [#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355) — Indigo design refresh (opt-in) + typography upgrade — shipped as **Violet theme** opt-in
- [x] [#359](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/359) — Reports redesign — drill-down + period-over-period

### Migration cost

- v3.5 → v4.0 upgrade routine handles `swh_options` split-out.
- Hosts on PHP 7.4 / WP 5.3–5.9 stay on the v3.6 LTS branch.

---

## v4.0.x patch series — bug-fix lane between v4.0.0 and v4.1.0

Locked sequence (agreed 2026-05-25). Each patch ships independently; later items don't depend on earlier ones except where noted.

### v4.0.1 ✅ shipped 2026-05-25

Single-issue patch — closes [#433](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/433): default email subject templates now wrap `[{ticket_id}]` so client replies actually reach the inbound webhook. RC-verified end-to-end via `webhook_roundtrip.py` against the live dev environment.

### v4.0.2 — 5 issues

Milestone: [#24](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/24). Bug-fix batch. Surfaced during v4.0.0 deep-dive sweep + RC testing.

### v4.0.3 — Settings page revamp

Milestone: [#27](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/27). Correctness patch on the storage-group ↔ UI-tab gap left over from v4.0.0 (#356). Closes the "features advertised with no way to use them" issue surfaced 2026-05-25.

- [#470](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/470) — Umbrella: align admin UI tabs with the 7 storage groups
- [#471](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/471) — New **Portal** tab: CSAT operator controls (previously hardcoded) + portal/admin theme settings
- [#472](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/472) — New **Integrations** tab (sparse): inbound webhook secret + coming-soon placeholders for v4.1/v4.2/v4.3
- [#473](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/473) — Docs sync (config-reference, data-dictionary, roadmap)
- [#474](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/474) — Playwright sections 74 (Portal/CSAT) + 75 (Integrations)

**Decision deferred:** reCAPTCHA stays on the Anti-Spam tab. Moving it to Integrations would fragment a cohesive panel (JS-driven row hiding tied to `spam_method`). Revisit in v4.1 when API-key management lands and a real "third-party credentials" pattern exists.

**Notifications tab also deferred** — `Migration.php` explicitly leaves that group unseeded; nothing to surface yet.

### Renumbered patches

- v4.0.4 (was v4.0.3) — Milestone [#25](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/25). Email-templates wording tweaks (#439, #440).
- v4.0.5 (was v4.0.4) — Milestone [#26](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/26). Docs + dev-experience cleanup. Includes:

- [#441](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/441) – [#447](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/447) — extended-test runbook docs + dev-experience refactors surfaced while building the RC suite
- [#453](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/453) — clear 8 Dependabot alerts (npm devDeps; zero end-user impact)
- [#458](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/458) — finish the deferred E2E selector sweep (~10 legacy-PHP tests)

### After v4.0.4 — comprehensive documentation review

Full pass before v4.1 work starts. Scope (agreed 2026-05-25):

1. `docs/internal/*.md` — architecture, invariants, conventions, test/security/API contracts, data dictionary, config reference, design guide, component inventory, JS arch, perf baseline, release roadmaps
2. `docs/*.md` operator docs + **the GitHub Pages site itself** (`docs/index.md`, Jekyll config, plugin/feature pages) — full rewrite-or-prune pass; Pages site is currently a "dog's breakfast" per operator
3. `CLAUDE.md` (root) — sanity-check after the rest is fixed

Why this timing: v4.0.x patches will surface 2–3 doc-rot items per release that are cheaper to fold in once at the end. v4.1's major additions (event surface, public REST) will rewrite chunks of `api-contract.md` and `data-dictionary.md` anyway — starting v4.1 from a clean doc baseline is the goal.

### After docs review — finish v4.x test refactor

Close [#458](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/458) if not already absorbed into v4.0.4. This is the last of the PR 4.x sweep (selector conversions for legacy PHP admin/settings tests). Lower priority than docs, but blocks the "every E2E test uses semantic selectors" exit criterion in `test-coverage-matrix.md`.

---

## v4.1.0 — Event Surface & REST API

Milestone: [#19](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/19) — 0/9 closed (was 10; lifecycle hooks moved to v3.7)
Theme: Public REST API v1, scoped API keys, OpenAPI spec, per-key rate limiting. Sits on top of v3.7 lifecycle hooks ([#361](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/361)) and v4.0 settings split ([#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356)).
**Depends on:** v3.7 lifecycle action hooks ([#361](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/361)) + v4.0 settings schema migration ([#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356)).

- ~~[#361](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/361) — Lifecycle action hooks~~ → **moved to v3.7** (de-risks v4.1 by giving hooks a release cycle to settle)
- [ ] [#362](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/362) — REST API v1: tickets CRUD (`swh/v1/tickets`)
- [ ] [#363](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/363) — REST API v1: replies (`swh/v1/tickets/{id}/replies`)
- [ ] [#364](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/364) — REST API v1: read-only endpoints (technicians, categories, settings)
- [ ] [#365](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/365) — API key management UI (scoped, per-key rate limit, revocable)
- [ ] [#366](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/366) — Per-endpoint capability + permission_callback wiring
- [ ] [#367](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/367) — Per-key rate limiting (60/min default)
- [ ] [#368](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/368) — OpenAPI 3.1 spec + Redoc viewer (hidden admin page)
- [ ] [#369](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/369) — Opt-in API access audit log (last 10 accesses per ticket)
- [ ] [#370](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/370) — Playwright: 7 new sections for REST API

---

## v4.2.0 — Outbound Webhooks & Signing

Milestone: [#20](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/20) — 0/8 closed
Theme: HMAC-SHA256 signed outbound webhooks consuming the v3.7 lifecycle action hooks via v4.1's REST surface.
**Depends on:** v3.7 lifecycle action hooks ([#361](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/361)) + v4.1 REST API ([#362](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/362)).

- [ ] [#371](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/371) — Subscription UI (per-event checkboxes, secret, target URL)
- [ ] [#372](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/372) — HMAC-SHA256 payload signing (`X-SWH-Signature`, timestamp, delivery ID)
- [ ] [#373](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/373) — Delivery queue with exponential backoff (WP-Cron, 5 attempts)
- [ ] [#374](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/374) — SSRF gate: block private IPs, https-only (except localhost), 10s timeout, 1MB cap
- [ ] [#375](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/375) — Delivery log UI (last 100 per subscription, redeliver button)
- [ ] [#376](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/376) — Per-subscription test event + ping endpoint validation
- [ ] [#377](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/377) — Receiver verification docs (PHP, Node, Python examples)
- [ ] [#378](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/378) — Playwright: 4 new sections (UI, signing, retry, SSRF)

---

## v4.3.0 — Core Integrations

Milestone: [#21](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/21) — 0/7 closed (Zapier issue [#382](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/382) **closed/parked** — see `parked_features.md`)
Theme: Native integrations sitting on the v4.2 webhook layer + reply-by-email v2 + SMTP abstraction.
**Depends on:** v4.2 signed webhooks ([#372](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/372)).

- [ ] [#379](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/379) — Slack native (incoming webhook, pre-formatted blocks)
- [ ] [#380](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/380) — Microsoft Teams (incoming webhook, Adaptive Cards)
- [ ] [#381](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/381) — Discord (embed format)
- ~~[#382](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/382) — Zapier app~~ → **PARKED** (`parked_features.md`)
- [ ] [#383](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/383) — Reply-by-email v2: DKIM + In-Reply-To threading + multipart attachments
- [ ] [#384](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/384) — SMTP abstraction (compatible with WP Mail SMTP / FluentSMTP)
- [ ] [#385](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/385) — Integrations marketing page in admin (cards, setup deep-links)
- [ ] [#386](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/386) — Playwright: 5 new sections (Slack, Teams, Discord, inbound v2, integrations page)

---

## Open decisions (need user input)

1. **Indigo design refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355))** — opt-in or default? Land in v4.0 alongside the inbox redesign or hold for v4.1+? Risks UI churn fatigue if shipped together with the inbox rewrite.
2. **v3.6 LTS branch** — explicit policy or informal? Decide before v4.0 ships so users on PHP 7.4 know what they're getting.
3. **OpenAPI spec hosting ([#368](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/368))** — admin-hidden page (current plan) or also public docs site?

---

## How this doc stays current

- Update milestone tables when issues open/close (just the count + state).
- When a release ships, archive its full section under "Shipped" at the bottom and tighten the next-up section.
- New v4.x ideas → propose in `new_features_discussion.md` first, promote to a milestone issue once shaped.
