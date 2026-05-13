# Issue #392: Component inventory — document existing primitives before v4.0 sprawl

**Labels:** dx, design-system
**Milestone:** v3.7.0 — v4 Foundations

## Body

v3.4–v3.5 added \`.swh-empty-state\`, \`.swh-toast\`, \`.swh-badge\`, \`.swh-bubble\`, \`.swh-panel-group\` ad-hoc. v4.0 needs modal, drawer (quick-reply), virtualized list, command bar, saved-view tabs. Without a manifest, each v4.0 issue will reinvent its own primitives and the indigo refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355)) will fight inconsistent class names.

### Goal

Land \`docs/internal/component-inventory.md\` listing every shared CSS/JS primitive with:
- Class name(s)
- Tokens consumed (e.g. \`--swh-shadow-md\`, \`--swh-color-focus\`)
- A11y notes (role, aria, focus behavior)
- Example markup
- File location (\`swh-shared.css\` vs \`swh-admin.css\`)

### Components to document (existing)

- \`.swh-empty-state\` (+ icon, title, desc, optional CTA)
- \`.swh-toast\` (success/error/info)
- \`.swh-badge\` (+ \`.swh-badge-{slug}\`)
- \`.swh-bubble\` (+ note, user, tech variants)
- \`.swh-panel-group\` (+ label)
- \`.swh-skeleton\` shimmer
- \`.swh-helpdesk-wrapper\` (+ \`data-swh-theme\`)
- \`.swh-skip-link\` (once v3.6 #341 lands)
- \`.swh-danger-zone\`
- \`.swh-ticket-card-list\`

### Components to flag as needed for v4.0 (gaps)

- \`.swh-modal\`
- \`.swh-drawer\` (quick-reply)
- \`.swh-virtual-list\` (inbox)
- \`.swh-command-bar\` (Ctrl/Cmd+K palette)
- \`.swh-tabs\` (saved views)

### Acceptance

- [ ] \`docs/internal/component-inventory.md\` lists all current components
- [ ] Each entry: class names, tokens, a11y notes, example, file
- [ ] v4.0 gap section lists components not yet built but planned
- [ ] Linked from \`docs/internal/release_v4.x.x_roadmap.md\`
