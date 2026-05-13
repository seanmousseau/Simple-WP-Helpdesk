# Design Guide

**Audience:** plugin contributors and designers.

This doc covers the higher-level UX principles and when-to-use guidance. It defers component-level detail to existing authoritative docs:

- **Primitives, CSS classes, design tokens** → `component-inventory.md`.
- **JS module structure and build pipeline** → `js-architecture.md`.
- **Performance budgets and measured baselines** → `performance-baseline.md`.

If you came here looking for a class name or a token, you are in the wrong doc — read `component-inventory.md` instead.

## UX principles

### Frontend portal

- **Auto-detect dark mode.** The `.swh-helpdesk-wrapper` root respects `prefers-color-scheme`. Sites that force a particular theme use the `data-swh-theme="light"` escape hatch on the wrapper.
- **Accessible by default.** WCAG 2.2 AA is the floor. Specifically: focus-visible rings on every interactive element, a skip-to-content link, ARIA-live announcements for async feedback, focus management for the CSAT modal.
- **Empty states are first-class.** Anywhere the user could see nothing, they see a labelled empty state (icon + title + description, optional CTA).
- **Toast feedback for transient async outcomes.** Anything that resolves in under a few seconds and does not warrant a page change emits a toast.
- **Form errors stay visible.** Validation messages are not removed by re-render; they remain until the user fixes the field.

### Admin

- **Opt-in dark mode.** Activated per-user via the WordPress admin colour scheme. `swh_admin_color_is_dark()` (`includes/helpers.php:839-850`) returns true for `midnight`, `modern`, `ectoplasm`. The selector `body.swh-helpdesk-admin.swh-admin-theme-dark` activates the token overrides in `swh-shared.css` and the component overrides in `swh-admin.css`.
- **Admin lists are scannable.** Unread badges, priority colour chips, SLA warn/breach indicators surface at a glance without requiring the operator to open each row.
- **Two settings forms.** Main vs Tools. Tools holds destructive options and uses its own nonce. Do not move fields across the boundary without considering invariant #9 in `design-document.md`.

### Emails

- **All CSS inlined.** No `<link>`, no `<style>` survives common webmail clients. Apple Mail / iOS Mail honour an inline `@media (prefers-color-scheme: dark)` block; that is the only dark-mode path supported.
- **Logo at 32×32 in the header band.** Falls back to `get_site_icon_url(48)` if `swh_email_logo_url` is empty.
- **`<meta name="color-scheme" content="light dark">`** opts emails into client dark mode.

## Reusable patterns

### Empty states

Use the `.swh-empty-state` component whenever the user sees "nothing here". Required structure: `.swh-empty-state-icon` (inline SVG, `aria-hidden="true"`), `.swh-empty-state-title`, `.swh-empty-state-desc`. CTA link optional.

For PHP-rendered empty states, prefer the helper `swh_render_empty_state( $title, $desc, $svg_path, $heading_level = 'h2' )` (`includes/helpers.php:811-828`). Pass the heading level appropriate to the surrounding hierarchy — admin lists usually want `h2`, sub-panels `h3`.

### Toasts

Use `.swh-toast` via `window.swhToast( msg, type )` for transient feedback (`success`, `error`, `info`). The JS is enqueued via `swh_enqueue_toast_script()` (`includes/helpers.php:864-891`), built by `@wordpress/scripts` to `assets/dist/toast.js` with a generated `toast.asset.php` manifest driving deps and cache-busting.

### Panel groups

The ticket editor sidebar uses `.swh-panel-group` + `.swh-panel-group-label` to organise Status / Priority / Assignee. Do **not** rename the input `id` attributes (`#swh-status`, `#swh-priority`, `#swh-assigned-to`) or the form field `name` attributes — the save handler depends on them.

### Confirmation prompts

Destructive admin actions use a JS-side confirm prompt before submission (e.g. delete ticket, run uninstall sweep). Never rely on browser native confirm for the primary flow — it is too easy to bypass with keyboard-only navigation. Use the toast + inline confirmation pattern documented in `component-inventory.md`.

## Component inventory

`component-inventory.md` is the source of truth for:

- Primitive list (buttons, badges, cards, panels, empty states, toasts, etc.).
- CSS class names and modifiers.
- Token vocabulary (`--swh-color-*`, `--swh-space-*`, etc.).
- Token appendix mapping light / dark values.

When adding a new primitive or token, add it to `component-inventory.md` in the same PR. Cross-link from here only if a UX principle changes.

## Token system

See `component-inventory.md` "Tokens" appendix. The short version: design tokens are CSS custom properties scoped under `.swh-helpdesk-wrapper` (frontend) and `.swh-helpdesk-admin` (admin). Dark mode mutates the values; component CSS reads only token names, never raw colours.

## JS architecture

See `js-architecture.md`. The short version: vanilla JS, `@wordpress/scripts` build, one IIFE per module, `window.swh*` namespace for the public surface (currently only `window.swhToast`).

## Dark mode reference

| Surface | Activation | Selector | Defined in |
|---|---|---|---|
| Frontend portal | `prefers-color-scheme: dark` (auto) | `@media (prefers-color-scheme: dark)` in `swh-shared.css` | `swh-shared.css` |
| Frontend portal escape | `swh_portal_theme=light` setting | `.swh-helpdesk-wrapper[data-swh-theme="light"]` | `swh-shared.css` |
| Admin pages | per-user WP admin colour (`midnight`, `modern`, `ectoplasm`) | `body.swh-helpdesk-admin.swh-admin-theme-dark` | `swh-shared.css` (tokens) and `swh-admin.css` (components) |
| Email | `<meta name="color-scheme">` + inline `@media (prefers-color-scheme: dark)` | inline in wrapper | `swh_wrap_html_email()` at `class-email.php:145` |

## Update protocol

Update this doc when:
- A new high-level UX principle is added (e.g. a new accessibility commitment).
- A new reusable pattern is named (the kind of thing that becomes a recurring shape across screens, not a one-off component).
- A new top-level surface is added (e.g. an iframe widget, a block editor block).
- The dark-mode wiring changes.

For component-level changes, update `component-inventory.md`. For JS-level changes, update `js-architecture.md`. This doc holds the principles, not the inventory.
