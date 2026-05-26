# ADR-0002: Use WP comments for ticket replies

- **Status:** Accepted
- **Date:** 2024-09-01 (back-dated)
- **Deciders:** Sean Mousseau

## Context

[ADR-0001](0001-no-custom-db-tables.md) commits us to WordPress core primitives for storage. Tickets are the natural fit for the `helpdesk_ticket` CPT. Replies and internal notes are not so obvious — three primitives could plausibly hold them:

1. **Comments** (`wp_comments`) with a custom `comment_type`.
2. **Child posts** of a custom CPT (one CPT for tickets, another for replies).
3. **Serialized arrays** in `wp_postmeta` on the parent ticket.

## Decision

**Replies and internal notes are stored as `wp_comments` rows with `comment_type = helpdesk_reply`.**

Internal notes additionally carry `_is_internal_note = 1` in `wp_commentmeta`. Both reply types live in the same table, the same query, the same admin moderation UI — distinguished only by the meta flag.

The plugin filters `helpdesk_reply` comments out of theme-level comment listings (`wp_count_comments`, `get_comments` defaults) so they don't pollute post pages.

## Consequences

### Costs

- **Comment hooks fire** for every reply. Other plugins (Akismet, comment moderators, anti-spam tools) see them. Most are benign; a few are filterable from doing damage. Documented in `security-model.md`.
- **The default `get_comments` query** would surface tickets' internal notes — we filter at the renderer level (defence in depth, not just at the query layer).
- **Comment IDs are global** across the site — a reply ID is not unique within a ticket. Minor but worth noting.
- **WP's comment moderation UI** can see `helpdesk_reply` comments; we accept this because it gives operators a familiar reading view. Trash/spam actions there work as expected.

### Benefits

- **Threading, authorship, metadata are free.** Every comment has author, email, date, content, parent — everything a reply needs.
- **`comment_meta`** gives us the same per-row state we'd get with a custom table.
- **GDPR data-export and data-erasure** work natively — comments are first-class in the WP framework.
- **Reply count, latest-reply, unread tracking** can leverage WP's existing comment infrastructure (with custom queries scoped to `comment_type`).
- **Akismet integration** is a one-liner for the (rare) case where a public reply form is exposed without anti-spam.
- **Audit trail** — comments are immutable in WP's UX; edits leave an audit. Reply history is naturally append-only.

### Alternatives considered

- **Child posts of a `helpdesk_reply` CPT.** Heavier — every reply gets a row in `wp_posts` + an autosave + a revision unless you turn revisions off. WP's comment table is leaner and the threading model is already what we want.
- **Serialized arrays in `wp_postmeta`.** Rejected hard. No indexing, no individual addressability, no comment hooks, breaks at scale (operators with hundreds of replies on a single ticket would blow up the meta row).

## Consequences for invariants

This decision is the source of two load-bearing invariants:

1. **`comment_type = 'helpdesk_reply'` segregates ticket activity** from regular post comments. Other plugins / themes must not assume "comments on this post" means "blog comments".
2. **Internal notes carry `_is_internal_note = 1` and must be filtered from frontend renders.** Not just from queries — the renderer enforces this even if the query accidentally pulls them in.

Both are stated in `design-document.md` and `contract-ticket-lifecycle.md`.

## See also

- [ADR-0001](0001-no-custom-db-tables.md) — the no-custom-tables decision this depends on.
- `contract-ticket-lifecycle.md` — comment-type isolation invariant.
- `contract-portal.md` — internal-note filtering invariant.
