# ADR-0001: No custom database tables

- **Status:** Accepted
- **Date:** 2024-09-01 (back-dated; codified after the fact)
- **Deciders:** Sean Mousseau

## Context

A helpdesk plugin needs storage for tickets, replies, internal notes, attachments, settings, and operator-configurable lists (canned responses, ticket templates). The conventional approach in plugins of this scale is to register custom database tables on activation (e.g. `wp_swh_tickets`, `wp_swh_replies`).

That approach has real advantages — explicit schema, indexed queries, no overloaded WP primitives — but costs the plugin everything that comes with custom tables: migration tooling, schema versioning, hosting-provider compatibility risk, backup/restore complexity, third-party export/import pipelines, GDPR tooling (the WP core data-export framework operates over WP primitives only), and the long tail of WP-aware tools (CLI, REST, search/replace, multisite migration) all assuming they can ignore your data.

## Decision

**Simple WP Helpdesk uses only WordPress core data structures for storage.**

- Tickets: `wp_posts` with `post_type = helpdesk_ticket`.
- Replies + internal notes: `wp_comments` with `comment_type = helpdesk_reply`.
- Per-ticket state: `wp_postmeta`.
- Per-reply state: `wp_commentmeta`.
- Settings: `wp_options`.
- Caches: WP transients (`wp_options` rows with TTL).
- Categories: `wp_terms` + `wp_term_taxonomy` + `wp_term_relationships` via a custom hierarchical taxonomy.

There are no custom tables. The activation routine creates no schema. The uninstall routine deletes options + the CPT + the comments — no `DROP TABLE`.

## Consequences

### Costs

- **Less efficient queries** on aggregate operations. Reports rely on transient caching and event-driven invalidation rather than indexed aggregate queries.
- **Some operations are awkward** in core primitives. The auto-assignment rules and canned responses are serialized PHP arrays in options — not ideal for diffing or partial updates, but acceptable at the cardinality involved.
- **Performance ceiling** is lower. We don't expect this plugin to scale to 1M+ tickets on a single site without hitting `wp_posts`/`wp_postmeta` query patterns. Documented in `performance-baseline.md`.

### Benefits

- **No migrations.** Activation is the trivial `register_post_type` + `register_taxonomy` + `add_option` loop.
- **WP-native tooling works.** Backup plugins, search-and-replace tools, WP-CLI, the data-export framework, multisite migrators, staging-to-prod flows — all of them see the plugin's data as ordinary WordPress data.
- **GDPR tooling is mostly free.** We extend the core data-erase / data-export framework with hooks; we don't reimplement it.
- **Operators trust it.** "No custom tables" is a documented selling point that distinguishes SWH from heavier alternatives.
- **Smaller surface area.** Less code, fewer bugs, no migration scripts in `class-installer.php`.

## Alternatives considered

- **Custom tables (`wp_swh_tickets`, etc.)** — Rejected. Costs above outweigh the perf wins for our scale target.
- **Hybrid: CPT for tickets, custom table for replies** — Rejected. Half the maintenance cost of full custom tables and only half the perf benefit. Worst of both.
- **External datastore (SQLite file in `wp-content/uploads/`)** — Rejected. Conflicts with "all data stays on your server in WordPress" identity; hosting compatibility nightmare.

## See also

- [ADR-0002](0002-comments-as-reply-storage.md) — the comments-as-replies decision is downstream of this one.
- `design-document.md` — the load-bearing invariants enforce this choice.
- `data-dictionary.md` — every key the plugin owns, in WP-native primitives.
