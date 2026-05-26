# Roadmap — north star

The **living roadmap** for Simple WP Helpdesk. This is the top-level view: where the product is going, on what time horizon, and what we are deliberately not doing.

Per-version execution detail lives in `release_v4.x.x_roadmap.md` (active) and `archive/release_v3.x.x_roadmap.md` (shipped). Indefinitely deferred ideas live in `parked_features.md`. Free-floating brainstorm content (unapproved) lives in `new_features_discussion.md`.

> Nothing in this document is committed until it lands in a milestone with user approval. Roadmap entries are **directions**, not promises.

---

## Identity

Before adding to this roadmap, every idea is checked against the plugin's identity:

- **Native WordPress.** No third-party SaaS, no custom DB tables, no required external services.
- **Lightweight.** A site running SWH should not feel slower than one without it.
- **Operator-controllable.** Every behavior is a setting, a hook, or a filter.
- **Secure by default.** Tokens are constant-time-compared. Inputs are sanitized. Outputs are escaped. CSRF is enforced. Capabilities are checked.
- **Accessible.** WCAG 2.2 AA in both themes, both surfaces.
- **i18n-complete.** Every string translatable.

Ideas that violate identity don't make the roadmap — they go to `parked_features.md` with a rationale, or get reshaped until they fit.

---

## Horizons

| Horizon | Meaning |
|---|---|
| **Now** | Currently shipping work — the active milestone. |
| **Next** | The next milestone after the current one. Themes are settled; specific issues are mostly in. |
| **Later** | Direction we're heading, theme-only. No issue-level commitment yet. |
| **Watching** | Things we're aware of but haven't decided whether to pursue. |

---

## Now

**v4.0.x — Foundation polish & accessibility.**

Ship-quality polish on top of the v4.0 foundation: tab UX, accessibility (focus rings, fieldset semantics), settings grouping, theme dedup, dark-mode parity. Cross-cutting bug fixes from real-world usage.

See `release_v4.x.x_roadmap.md` for the current shipped + open issue list.

---

## Next

**v4.1.x — REST API surface.**

Promote the internal helper functions and ad-hoc admin-ajax endpoints into a coherent **public `/swh/v1/` REST API**, scoped by API keys managed in **Settings → Integrations**. Includes:

- Scoped API keys (read-only, read-write, admin).
- Stable, versioned endpoints for tickets, replies, settings, reports.
- Authentication via `Authorization: Bearer <key>` (same pattern as inbound webhook).
- Rate limiting on key.
- Audit log of API access.

**Theme:** "Open the helpdesk to integrations." Lays the foundation for native Slack / Teams / Discord / Zapier in v4.3.

---

## Later

In rough order of strategic priority. No commitment until promoted to a milestone.

### v4.2 — Outbound webhooks

HMAC-signed event subscriptions with retry + delivery log. Pairs with the v4.1 API; this is the "push" complement to the v4.1 "pull" model. Customers can subscribe to `ticket.created`, `ticket.replied`, `ticket.status_changed`, etc.

### v4.3 — Native integrations

First-class Slack, Microsoft Teams, Discord, Zapier integrations built on the v4.1 + v4.2 primitives. Configured in **Settings → Integrations**. Replaces the "coming soon" placeholders shown there today.

### Workflows & automation

Operator-defined rules ("when a ticket sits in `Resolved` for 7 days, auto-close and notify"; "when a ticket from a VIP email arrives, set priority to High and assign to Sean"). Distinct from the current single-purpose Auto-Close and Auto-Assignment features — generalized rule engine.

### Multi-mailbox support

Support multiple inbound mailboxes per site, each with its own `_ticket_email` matching rule and routing default. Pairs with `sender_mismatch` improvements on the inbound webhook (handle CC'd replies, group replies, distribution lists).

### Knowledge base / canned-response evolution

Promote canned responses from a flat list into a categorized library with search. Eventually: AI-assisted suggestion ("this question looks like Q15 from your KB — insert the answer?").

### Advanced reporting

CSAT trends, technician scorecards, response-time histograms, SLA compliance reports. Export to CSV / PDF.

---

## Watching

We aren't committing to these, but they're on our radar:

- **Block editor (Gutenberg) blocks for `[submit_ticket]` and `[helpdesk_portal]`.** Many sites are now block-only; shortcode-only is increasingly second-class.
- **WP CLI subcommands** (`wp swh ticket list`, `wp swh purge-old-attachments`, etc.) for ops automation.
- **Custom statuses with workflow states** (allowed transitions, not just a free list of labels).
- **WP_DEBUG-aware verbose logging** that doesn't leak PII.
- **Multisite support.** Currently single-site only; multisite is a non-trivial expansion of the security model.

---

## Explicitly not doing

For the record — these have been considered and rejected, with rationale:

| Idea | Why not |
|---|---|
| Custom DB tables | Conflicts with "no custom DB tables" identity. Comments + post meta have served us through v4. |
| Bundled SMTP / outbound mail provider | WordPress already has WP Mail SMTP and equivalents. Adding another would be reinventing a mature ecosystem. |
| Cloud-hosted SaaS option | Conflicts with "native WordPress, data stays on your server" identity. |
| Replacing WP Comments as the reply storage | Same as custom tables. Comments are battle-tested, hooked-everywhere, GDPR-tooled. |
| Required Composer install at runtime | The plugin ships `vendor/` committed. Site admins can't run `composer install`. |

Long-form rationales for any of the above live in `parked_features.md`.

---

## How this doc gets updated

- **Promote** when a Later/Watching item moves into Next: open the milestone, the issues, update `release_v4.x.x_roadmap.md`, and move the entry from Later/Watching into Next here.
- **Demote** when a Next item slips: move it back to Later with a one-line note about what blocked it.
- **Park** when an idea is rejected: cut from this doc, paste into `parked_features.md` with rationale.
- **All edits require user approval** before merging.

When updating, also touch `release_v4.x.x_roadmap.md` if the change affects a near-term milestone, and CLAUDE.md if the change adds or removes a top-level doc.
