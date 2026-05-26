# Issue #390: JS architecture spike — pick build/state pattern before v4.0 admin UI work starts

**Labels:** enhancement, dx
**Milestone:** v3.7.0 — v4 Foundations

## Body

v4.0 ships an inbox-style admin ([#349](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/349)), command palette ([#351](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/351)), saved views ([#352](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/352)), and a quick-reply drawer ([#350](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/350)). All four need:

- Client-side state shared across components
- Virtualized list (500+ rows)
- Fuzzy-match index for command palette
- Modular component composition (drawer can open from any view)

Today the plugin uses a single vanilla `swh-admin.js` file. Without a decision now, each v4.0 issue will reinvent the wheel and the resulting JS will be unmaintainable.

### Goal

Land **one** chosen pattern in v3.7 so v4.0 issues all reference it.

### Two paths

**Option A — Stay vanilla**
- Add a tiny event-bus + module pattern to `swh-admin.js`
- Document in `docs/internal/js-architecture.md`
- Refactor existing toast + skeleton + settings-dirty code to use it
- Pros: no build step, no tooling
- Cons: ceiling lower for inbox virtualization

**Option B — Add a build system**
- `@wordpress/scripts` (natural with v4.0's WP 6.0 minimum) or esbuild
- Source in `assets/src/`, build to `assets/dist/`
- Component primitives: \`Drawer\`, \`Modal\`, \`VirtualList\`, \`CommandBar\`
- Pros: ceiling for v4.0 features, idiomatic WP
- Cons: tooling, contributor onramp

### Acceptance

- [ ] Decision recorded in `docs/internal/js-architecture.md` with rationale
- [ ] Chosen primitive landed in repo with one consumer (refactor toast or skeleton to use it)
- [ ] CONTRIBUTING.md updated
- [ ] Bundle-size budget documented (e.g. ≤30KB gzip for admin entrypoint)
- [ ] No regression in any v3.6 Playwright section

### Dependencies

Blocks: v4.0 issues [#349](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/349), [#350](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/350), [#351](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/351), [#352](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/352), [#353](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/353), [#354](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/354).
