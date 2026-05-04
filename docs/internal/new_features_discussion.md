# New Features — Discussion (living document)

Ideas not currently on any roadmap. Each entry is a starting point for discussion, **not** a commitment. Things move from here → a roadmap milestone once shaped, prioritized, and accepted.

Last updated: 2026-05-04

---

## How to use this doc

1. **Add anything** — half-formed ideas welcome. Worse to lose them than to keep a messy list.
2. **Each entry has the same skeleton** — problem, sketch, signals, open questions, fit. Skeleton = forces the idea into honest shape.
3. **Once an idea is shaped enough** — open a GitHub issue, attach to a milestone, remove from this doc.
4. **Once an idea is rejected** — move to `parked_features.md` with the reasoning.

---

## Active discussion

### 1. AI-assisted reply suggestions
- **Problem:** Technicians retype the same answer often. Canned responses help but require manual creation/maintenance.
- **Sketch:** Per-ticket "Suggest reply" button → calls a configurable LLM endpoint (BYO API key — OpenAI / Anthropic / local Ollama) with redacted ticket context. Returns a draft, never auto-sends. Could optionally suggest categories + priority.
- **Signals:** Industry baseline now (Zendesk AI, Intercom Fin, HubSpot ChatSpot). Self-hosted users want privacy → BYO key + local-LLM support is the differentiator vs SaaS.
- **Open questions:** PII redaction policy. Cost gating (per-user / per-ticket budget). Where prompts live (filterable code vs admin UI). Audit logging.
- **Roadmap fit:** v4.4+ (after REST + webhooks land — sits naturally on top of the API surface).

### 2. Macros / automation rules
- **Problem:** Today only assignment rules and SLA cron run automatically. Common asks: "after 24h with no client reply → close", "if priority=urgent and unassigned → notify lead", "if subject contains 'invoice' → tag billing + assign Cathy."
- **Sketch:** Rules engine with WHEN (event/condition) → THEN (action) blocks. Reuses v4.1 event hooks. Admin UI is the hard part.
- **Signals:** Top-3 ask in any helpdesk forum thread. Assignment rules already prove the pattern works.
- **Open questions:** Condition DSL (visual builder vs JSON). Conflict resolution when multiple rules match. Loop prevention (rule can't trigger another rule that triggers itself).
- **Roadmap fit:** v4.4 or v5.0 — depends on v4.1 event hooks being live.

### 3. Tags (separate from categories)
- **Problem:** Categories are hierarchical, slow to apply, and one-per-ticket in practice. Real-world triage wants quick, freeform, multi-value tagging ("vip", "needs-screenshot", "billing-related").
- **Sketch:** New non-hierarchical taxonomy (`helpdesk_tag`). Inline tag input on the ticket editor. Filter chips on the inbox.
- **Signals:** Categories taxonomy added in v3.0 already feels like the wrong shape for triage workflows.
- **Open questions:** Migration path from category-as-tag patterns. Tag autocomplete UX. Bulk tagging.
- **Roadmap fit:** Pairs with v4.0 inbox redesign. Could land as v4.0 or v4.1.

### 4. Knowledge base / FAQ shortcode
- **Problem:** Same questions get asked repeatedly. No first-line deflection mechanism.
- **Sketch:** New CPT `helpdesk_kb`. `[helpdesk_kb]` shortcode. On the submission form, real-time search ("did you mean: …") before the user submits. Optionally feeds AI suggestions (#1).
- **Signals:** Strong correlation between "good helpdesk" and "good KB" — companies that get this right have 30–50% deflection.
- **Open questions:** Compete with WP's existing KB plugins (BetterDocs, Heroic KB)? Or stay minimal and integrate?
- **Roadmap fit:** v5.x — too big for any current milestone.

### 5. CSAT trend reporting
- **Problem:** v3.0 added CSAT capture (`_ticket_csat`). No reporting on it. Captured data, never shown.
- **Sketch:** New report card on Reports page — average CSAT, trend over time, breakdown by assignee. Sits next to existing reports.
- **Signals:** Cheap win — data already exists, just needs a chart. Likely a v3.7.0 candidate if v3.x continues.
- **Open questions:** Per-assignee privacy concerns (does a tech want their CSAT visible to others?). Sample size minimums.
- **Roadmap fit:** v3.7 (small, non-breaking) **or** roll into v4.0 reports redesign ([#359](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/359)).

### 6. Time tracking on tickets
- **Problem:** Agencies and MSPs running this as a billable-hours tool ask for time tracking constantly.
- **Sketch:** Start/stop timer per ticket. Manual entry. Total time meta. Export to CSV. Optional integration with billing plugins (WooCommerce, FreshBooks).
- **Signals:** Heard 3× from agency users. Real demand exists, but it's a feature segment, not the core use case.
- **Open questions:** Scope creep risk — once you have time tracking, you get asked for invoicing, rate cards, project budgets. Where do we draw the line?
- **Roadmap fit:** v5.x or never. Could also be a separate companion plugin.

### 7. Customer organizations / firms
- **Problem:** Users at the same company are tracked as individuals. No way to see "all tickets from Acme Corp" or set per-company SLAs.
- **Sketch:** New CPT `helpdesk_org`. Tickets link to org via meta. Per-org SLA override. Org-scoped portal view ("see all tickets from your team").
- **Signals:** B2B helpdesk pattern. Adds significant data model complexity.
- **Open questions:** Auto-detect from email domain? How does this interact with WP user accounts? Permissions model for org-scoped portal?
- **Roadmap fit:** v5.x.

### 8. Saved replies with variables (richer canned responses)
- **Problem:** Current canned responses are flat strings. Need `{client_name}`, `{ticket_id}`, `{assigned_tech}` variable substitution like email templates already have.
- **Sketch:** Reuse the email template `{var}` and `{if var}…{endif var}` parser for canned responses.
- **Signals:** Small lift, large daily impact for techs.
- **Open questions:** None significant — pattern is proven in email templates.
- **Roadmap fit:** v3.7 candidate (non-breaking) **or** v4.0 quick-reply drawer work.

### 9. Public status page integration
- **Problem:** When something is broken, every affected user opens a ticket. No mechanism to deflect with "we know, we're working on it."
- **Sketch:** Banner on the submission form when an admin sets a "current incident" message. Optional StatusPage / Instatus webhook receiver.
- **Signals:** Niche but high-impact during incidents.
- **Open questions:** Build vs integrate. Most users probably don't run a separate status page.
- **Roadmap fit:** v5.x or community plugin.

### 10. SSO / SAML for technicians
- **Problem:** Larger orgs running on SSO want techs to log in via Okta / Azure AD / Google Workspace, not WP-local accounts.
- **Signals:** Solved by existing SSO plugins for WordPress generally — probably out of scope for us.
- **Roadmap fit:** Likely **park** — recommend miniOrange / WP SAML SSO instead.

---

## Format for new entries

```markdown
### N. <short title>
- **Problem:** What's the user pain? Be concrete.
- **Sketch:** Rough shape — not a design, just enough to discuss.
- **Signals:** Where's the demand from? How often heard? Anchors in real evidence.
- **Open questions:** What we'd need to figure out before committing.
- **Roadmap fit:** Best-guess milestone, or "park" / "out of scope."
```

Skip any field if there's nothing real to say — don't fill it with filler.

---

## Graveyard (rejected ideas)

When something gets rejected outright (vs parked), drop a one-liner here so we don't relitigate it.

- *(empty)*
