# Component Inventory

Manifest of shared CSS/JS primitives in Simple WP Helpdesk. Use this as
the source of truth before adding new components — prefer extending an
existing primitive over inventing a new one.

If a primitive you need is not listed here, check the **v4.0 component
gaps** section at the bottom: it may already be planned (with a tracking
issue) and worth waiting on rather than re-implementing locally.

Last refreshed: v3.7.0 (2026-05-13)

---

## Existing primitives (v3.7.0)

### `.swh-empty-state`

**Type:** CSS class + PHP helper (`swh_render_empty_state()`)
**File:** `simple-wp-helpdesk/assets/swh-shared.css:106-128`
**PHP helper:** `simple-wp-helpdesk/includes/helpers.php:796-829`
**Since:** v3.5.0 (CSS), v3.6.0 (PHP helper)
**Tokens consumed:** `--swh-space-xl`, `--swh-space-lg`, `--swh-space-sm`, `--swh-space-xs`, `--swh-space-md`, `--swh-color-muted`, `--swh-color-text`, `--swh-color-text-secondary`, `--swh-font-md`, `--swh-font-sm`

**A11y notes:**
- The helper escapes title/desc and accepts a configurable heading level
  (default `h2`) so the empty state slots into surrounding heading
  hierarchy correctly. Pass `h3` when nested inside a sub-section that
  already owns an `h2`.
- The icon `<svg>` is rendered with `aria-hidden="true"` — decorative only.
- `<path>` markup passed in is filtered through `wp_kses()` (only `d`,
  `fill-rule`, `clip-rule` attributes allowed).
- A `:focus-visible` ring is applied to any `a` inside `.swh-empty-state`
  via `body.swh-helpdesk-admin` selector in `swh-shared.css:193-200`.

**Required structure:**
- `.swh-empty-state-icon` — `<svg>` (decorative, `aria-hidden="true"`)
- `.swh-empty-state-title` — heading (h1–h6)
- `.swh-empty-state-desc` — `<p>` description
- Optional CTA `<a>` after the description

**Example markup:**
```html
<div class="swh-empty-state">
  <svg class="swh-empty-state-icon" viewBox="0 0 24 24" aria-hidden="true">
    <path d="M12 2L2 22h20L12 2z"/>
  </svg>
  <h2 class="swh-empty-state-title">No tickets yet</h2>
  <p class="swh-empty-state-desc">When someone submits a ticket it will appear here.</p>
  <a class="button button-primary" href="...">Configure settings</a>
</div>
```

**PHP helper signature:**
```php
swh_render_empty_state( string $title, string $desc, string $icon_svg_path, string $heading_level = 'h2' ): void
```

---

### `.swh-toast`

**Type:** CSS class + JS module (`window.swhToast()`)
**CSS file:** `simple-wp-helpdesk/assets/swh-admin.css:439-498`
**JS source:** `simple-wp-helpdesk/assets/src/toast/index.js`
**JS build:** `simple-wp-helpdesk/assets/dist/toast.js` (+ `toast.asset.php`)
**PHP enqueue helper:** `swh_enqueue_toast_script()` in `includes/helpers.php:853+`
**Since:** v3.5.0 (CSS + inline `swhToast()`); rebuilt with `@wordpress/scripts` in v3.7.0 (legacy inline implementation removed — call `window.swhToast()` which is loaded via the built module).
**Tokens consumed:** `--swh-space-lg`, `--swh-space-sm`, `--swh-space-md`, `--swh-color-surface`, `--swh-color-border`, `--swh-radius-md`, `--swh-radius-sm`, `--swh-shadow-md`, `--swh-z-toast`, `--swh-transition-normal`, `--swh-ease-out`, `--swh-color-success-accent`, `--swh-color-danger`, `--swh-color-primary`, `--swh-color-text`, `--swh-color-muted`, `--swh-color-focus`

**Variants:**
- `.swh-toast--success` — green left border
- `.swh-toast--error` — red left border
- `.swh-toast--info` — primary blue left border
- `.swh-toast--visible` — applied by JS to fade/slide the toast in

**A11y notes:**
- The dismiss button has its own `:focus-visible` outline.
- The toast container is positioned `bottom-right` with `z-index: var(--swh-z-toast)` so it sits above modal/dropdown layers.
- Integrators that wire toasts to ARIA live announcements should look at
  the v3.6.0 `aria-live` region (test_64) instead of relying on toast DOM
  insertion alone — toasts are visual, not announced by default.

**Required structure:**
```html
<div class="swh-toast swh-toast--success swh-toast--visible" role="status">
  <span class="swh-toast__message">Settings saved.</span>
  <button type="button" class="swh-toast__dismiss" aria-label="Dismiss">×</button>
</div>
```

**JS API (v3.7.0+):**
```js
window.swhToast( 'Settings saved.', 'success' ); // 'success' | 'error' | 'info'
```

PHP-side, enqueue the script through `swh_enqueue_toast_script()` — it
reads `toast.asset.php` for dependencies and the hashed version so the
`wp_enqueue_script` call stays in sync with the build automatically.

---

### `.swh-badge`

**Type:** CSS class
**File:** `simple-wp-helpdesk/assets/swh-shared.css:77-104`
**Since:** v3.4.0 (consolidated in #330)
**Tokens consumed:** `--swh-radius-pill`, `--swh-font-sm`, `--swh-transition-fast`, `--swh-ease-out`, `--swh-color-focus`, `--swh-radius-sm`, plus status-/SLA-specific colour pairs (see below)

**Variants (status, keyed on `sanitize_title( $status )`):**
- `.swh-badge-open` — info blue
- `.swh-badge-closed` — error red
- `.swh-badge-in-progress` — warning amber
- `.swh-badge-resolved` — success green

**Variants (SLA):**
- `.swh-badge-sla-warn` — warning amber
- `.swh-badge-sla-breach` — solid danger with white text

Custom status slugs follow the same pattern: define
`.swh-badge-<slug>` consuming a contrast-safe bg/text pair from the
token table.

**A11y notes:**
- `:where(a, button)` selector applies pointer + brightness-hover
  feedback only when the badge is interactive.
- `:focus-visible` ring lights up via `--swh-color-focus`.
- The white-on-red `sla-breach` variant uses `--swh-color-on-solid`
  (white) for contrast against the solid `--swh-color-danger` background.
- A pill is visual-only — pair with `aria-label` if the colour alone
  conveys status to screen-reader users.

**Example markup:**
```html
<span class="swh-badge swh-badge-in-progress">In Progress</span>
<a class="swh-badge swh-badge-open" href="...">Open (12)</a>
```

---

### `.swh-bubble`

**Type:** CSS class
**File:** `simple-wp-helpdesk/assets/swh-admin.css:94-130`
**Since:** v2.x (conversation UI redesign, #253)
**Tokens consumed:** `--swh-space-sm`, `--swh-space-md`, `--swh-space-xs`, `--swh-radius-sm`, `--swh-color-primary`, `--swh-color-warning-bg`, `--swh-color-warning-bd`, `--swh-color-bg-subtle`, `--swh-color-border-subtle`, `--swh-color-info-bg`, `--swh-color-muted`

**Variants:**
- `.swh-bubble-note` — internal note (yellow background, warning border)
- `.swh-bubble-user` — client message (subtle grey)
- `.swh-bubble-tech` — staff reply (info blue)

**Sub-elements:**
- `.swh-bubble-meta` — block-level meta strip (author, etc.)
- `.swh-bubble-timestamp` — muted relative time
- `.swh-bubble-attachments` — attachment chip row

**A11y notes:** Bubbles are visual containers — author/timestamp are
expressed in text in `.swh-bubble-meta`. The internal-note variant
should be paired with explicit text ("Internal note") rather than
relying on colour alone.

**Required structure:**
```html
<div class="swh-bubble swh-bubble-tech">
  <span class="swh-bubble-meta">
    Tech Alice
    <span class="swh-bubble-timestamp">2 hours ago</span>
  </span>
  <p>Sure, I'll look into that today.</p>
  <div class="swh-bubble-attachments">...</div>
</div>
```

---

### `.swh-panel-group`

**Type:** CSS class
**File:** `simple-wp-helpdesk/assets/swh-admin.css:335-372`
**Since:** v3.4.0 (ticket editor side-panel redesign, #322)
**Tokens consumed:** `--swh-space-sm`, `--swh-space-xs`, `--swh-color-border-subtle`, `--swh-font-sm`, `--swh-color-text-secondary`

**Sub-element:**
- `.swh-panel-group-label` — small uppercase tracking-wide label

**A11y notes:**
- The label uses font-weight + letter-spacing for visual hierarchy; it
  is *not* a heading — the surrounding meta box `<h2>` from WP already
  provides the structural heading.
- Do **not** rename the `name` attributes of inputs inside the group
  (`swh-status`, `swh-priority`, `swh-assigned-to` IDs in particular) —
  the `save_post` handler matches on them.

**Required structure:**
```html
<div class="swh-panel-group">
  <span class="swh-panel-group-label">Status</span>
  <select id="swh-status" class="swh-select" name="swh_status">...</select>
</div>
<div class="swh-panel-group">
  <span class="swh-panel-group-label">Priority</span>
  <select id="swh-priority" class="swh-select" name="swh_priority">...</select>
</div>
```

---

### `.swh-skeleton` (shimmer loader)

**Type:** CSS class + `@keyframes swh-shimmer`
**File:** `simple-wp-helpdesk/assets/swh-admin.css:407-437`
**Since:** v3.5.0 (#332)
**Tokens consumed:** `--swh-color-surface`, `--swh-color-bg-subtle`, `--swh-radius-sm`

**Variants (sizing wrappers):**
- `.swh-kpi-skeleton` — 2rem × 60%, centred margin
- `.swh-chart-skeleton` — 200px × 100%

**A11y notes:**
- `pointer-events: none; user-select: none;` so screen readers / kbd
  users don't tab into the placeholder.
- Animation respects `prefers-reduced-motion: reduce` via the global
  override in `swh-shared.css:166-174`.
- Pair the skeleton container with `aria-busy="true"` on the live
  region while the real content is loading.

**Required structure:**
```html
<div class="swh-kpi-card" aria-busy="true">
  <div class="swh-skeleton swh-kpi-skeleton"></div>
</div>
```

---

### `.swh-helpdesk-wrapper`

**Type:** CSS class + `data-swh-theme` attribute
**File:** `simple-wp-helpdesk/assets/swh-frontend.css:3-14` (root rules);
`simple-wp-helpdesk/assets/swh-shared.css:130-163` (dark-mode tokens + light escape hatch);
`simple-wp-helpdesk/assets/swh-shared.css:185-200` (focus-visible scope)
**Since:** Pre-v3.0 (root frontend wrapper); `data-swh-theme` added in v3.6.0 (#321)
**Tokens consumed:** all dark-mode-overridable tokens — see token list at end of this doc

**Behaviour:**
- Default — follows `prefers-color-scheme: dark` and remaps `--swh-color-bg`, `--swh-color-text`, `--swh-color-primary`, etc. to dark equivalents.
- Add `data-swh-theme="light"` on the wrapper to *force* light mode
  regardless of OS preference. Driven by the `swh_portal_theme` setting
  (`'auto'` vs `'light'`), set in `class-portal.php` and
  `class-shortcode.php`.

**A11y notes:**
- The wrapper is also the scope for `:focus-visible` rings on every
  SWH-owned interactive element (button, a, select, textarea, input,
  `[role="tab"]`, `[role="radio"]`, `.swh-badge`). Stomping this scope
  would break the v3.6.0 focus-ring contract.
- Dark-mode tokens hit WCAG 16:1 (text) and 4.6:1 (secondary) — don't
  swap them out for a smaller contrast ratio.

**Required structure:**
```html
<div class="swh-helpdesk-wrapper" data-swh-theme="light">
  <!-- portal / shortcode markup -->
</div>
```

---

### `.swh-skip-link`

**Type:** CSS class
**File:** `simple-wp-helpdesk/assets/swh-shared.css:202-233`
**JS focus handler:** `simple-wp-helpdesk/assets/swh-admin.js:433+` (delegated click → focus target)
**Since:** v3.6.0 (#341)
**Tokens consumed:** `--swh-z-toast`, `--swh-color-primary`, `--swh-color-on-solid`, `--swh-radius-sm`, `--swh-shadow-md`, `--swh-color-focus`

**Behaviour:**
- Visually hidden by default using `clip-path: inset(50%)` + 1×1 box (mirrors WP core `.screen-reader-text` to avoid RTL scroll-width issues).
- Becomes visible at top-left on `:focus` / `:focus-visible`.
- The accompanying JS handler intercepts the click and calls `.focus()` on the target so the browser actually moves the focus ring, not just the scroll position.

**A11y notes:**
- Must be the first focusable element in the document for the skip-to-content contract to work.
- Target element should have `tabindex="-1"` so JS-driven focus succeeds.

**Required structure:**
```html
<a class="swh-skip-link" href="#swh-main">Skip to content</a>
...
<main id="swh-main" tabindex="-1">...</main>
```

---

### `.swh-danger-zone`

**Type:** CSS class
**File:** `simple-wp-helpdesk/assets/swh-admin.css:260-272`
**Since:** v3.4.0 (settings page visual hierarchy, #323)
**Tokens consumed:** `--swh-color-danger`, `--swh-color-danger-surface`, `--swh-space-md`, `--swh-space-xl`, `--swh-radius-sm`

**A11y notes:**
- The `h3` inside the zone inherits `--swh-color-danger` for emphasis. Pair destructive actions with an explicit `aria-describedby` pointing to the explanatory paragraph; colour alone is not a signal.
- Submit buttons inside should carry a confirm step (JS `confirm()` or a typed-confirmation pattern).

**Required structure:**
```html
<section class="swh-danger-zone">
  <h3>Delete all data on uninstall</h3>
  <p id="dz-desc">Removes every ticket, attachment, and setting.</p>
  <label><input type="checkbox" name="swh_delete_on_uninstall" aria-describedby="dz-desc"> Yes, delete on uninstall</label>
</section>
```

---

### `.swh-ticket-card-list`

**Type:** CSS class
**File:** `simple-wp-helpdesk/assets/swh-frontend.css:412-454+`
**Since:** v3.4.0 (My Tickets portal redesign, #320)
**Tokens consumed:** `--swh-space-lg`, `--swh-space-sm`, `--swh-space-md`, `--swh-color-bg`, `--swh-color-border`, `--swh-color-border-subtle`, `--swh-radius-md`, `--swh-transition-normal`, `--swh-color-primary`, `--swh-color-bg-highlight`

**Sub-elements:**
- `.swh-ticket-card` — single row (flex layout, hover lifts the shadow)
- `.swh-ticket-card--unread` — left primary border + highlight background
- `.swh-ticket-card-body` — flexes to fill, truncates title with ellipsis
- `.swh-ticket-card-title` — single-line truncated title

**A11y notes:**
- The list is a `<ul>` with `list-style: none` — the semantic list role is preserved for AT users.
- For "unread" rows, supplement the colour cue with `aria-label="Unread"` or a visible "New" pill — colour-only is not accessible.

**Required structure:**
```html
<ul class="swh-ticket-card-list">
  <li class="swh-ticket-card swh-ticket-card--unread">
    <div class="swh-ticket-card-body">
      <p class="swh-ticket-card-title">Printer offline in lobby</p>
      <p class="swh-text-secondary">Opened 2h ago · In Progress</p>
    </div>
    <a href="...">View</a>
  </li>
</ul>
```

---

### Dark-mode token system

**Type:** CSS custom-property overrides (no class)
**Files:**
- Frontend portal: `simple-wp-helpdesk/assets/swh-shared.css:130-147` (`@media (prefers-color-scheme: dark) .swh-helpdesk-wrapper`)
- Frontend light escape hatch: `simple-wp-helpdesk/assets/swh-shared.css:149-163` (`.swh-helpdesk-wrapper[data-swh-theme="light"]`)
- Admin: `simple-wp-helpdesk/assets/swh-shared.css:235-250` (`body.swh-helpdesk-admin.swh-admin-theme-dark`)

**Since:** v3.6.0 (frontend dark + portal opt-out, #321; admin dark, #339)

**Activation rules:**
- **Frontend** — automatic via `prefers-color-scheme: dark`. Sites that force light mode set `swh_portal_theme = 'light'`, which adds `data-swh-theme="light"` to every `.swh-helpdesk-wrapper` and overrides the media query.
- **Admin** — opt-in per-user. `swh_admin_color_is_dark()` returns true when the WP user's `admin_color` is `midnight`, `modern`, or `ectoplasm`. The settings / reports / ticket-list controllers then add the class `swh-admin-theme-dark` to `<body>` (alongside the always-present `swh-helpdesk-admin` class). The dual-class selector (`body.swh-helpdesk-admin.swh-admin-theme-dark`) is load-bearing — both classes must be present.

**Token surface remapped:** `--swh-color-bg`, `--swh-color-bg-subtle`,
`--swh-color-surface`, `--swh-color-bg-highlight`, `--swh-color-border`,
`--swh-color-border-input`, `--swh-color-border-subtle`,
`--swh-color-text`, `--swh-color-text-secondary`, `--swh-color-muted`,
`--swh-color-primary`, `--swh-color-track`.

**A11y notes:**
- All dark-mode mappings target WCAG-AA contrast or better against the
  paired `--swh-color-text` value. Don't introduce a new dark variant
  without re-verifying the contrast ratio for the surface it sits on.
- The `prefers-reduced-motion` override in `swh-shared.css:166-174`
  applies regardless of theme — every transition gets clamped to
  0.01ms when the user requests reduced motion.

---

## v4.0 component gaps (planned, not yet built)

These primitives do **not** exist in the codebase today. v4.0 UI work
will introduce them. Before reinventing one of these locally, check the
linked issue — and prefer reaching for `@wordpress/components` (which
v3.7.0's `@wordpress/scripts` build pipeline now makes trivially
available) rather than writing a bespoke implementation. The v4.0
indigo-refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355))
will retoken every primitive — primitives shipped before #355 lands
risk needing a token rewrite.

### `.swh-modal`

**Status:** Not yet built — needed by v4.0 admin UI work.
**Tracking issue:** part of the v4.0 admin-UX rewrite milestone ([#18](https://github.com/seanmousseau/Simple-WP-Helpdesk/milestone/18)).
**Recommended base:** `@wordpress/components` `Modal` covers focus trap, ESC dismissal, ARIA wiring, and overlay; usually only token-level styling on top is needed.
**Depends on:** indigo-refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355)) for final colour tokens.

### `.swh-drawer` (quick-reply)

**Status:** Not yet built.
**Tracking issue:** [#350 — Quick-reply drawer from inbox preview](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/350)
**Recommended base:** No `@wordpress/components` equivalent; closest is a custom slide-in panel. Likely needs to be built from scratch using token primitives — start from `--swh-shadow-lg`, `--swh-z-modal`, and reuse `.swh-panel-group` for sectioning.
**Depends on:** indigo-refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355)).

### `.swh-virtual-list` (inbox virtualization)

**Status:** Not yet built.
**Tracking issue:** [#349 — Inbox-style Tickets admin page](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/349)
**Recommended base:** Consider `@tanstack/react-virtual` or a small bespoke virtualizer; `@wordpress/components` does not ship virtualization. Token surface will reuse `.swh-ticket-card-list` styling.
**Depends on:** indigo-refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355)) and the v4.0 settings-schema migration ([#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356)) for saved-view persistence.

### `.swh-command-bar` (Ctrl/Cmd+K palette)

**Status:** Not yet built.
**Tracking issue:** [#351 — Command palette (Ctrl/Cmd+K)](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/351)
**Recommended base:** `@wordpress/components` `ComboboxControl` + `Modal` handle most of the UX; only the action-runner layer and keybinding capture need custom code.
**Depends on:** indigo-refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355)).

### `.swh-tabs` (saved views)

**Status:** Not yet built.
**Tracking issue:** [#352 — Saved views (per-user filter persistence)](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/352)
**Recommended base:** `@wordpress/components` exports `TabPanel`; v3.7's existing `[role="tab"]` focus-ring scope in `swh-shared.css:185-200` already handles keyboard styling.
**Depends on:** indigo-refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355)) and per-user storage from settings split ([#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356)).

---

## Appendix — design tokens referenced by the primitives above

All tokens are defined in `simple-wp-helpdesk/assets/swh-shared.css:1-75`
under the `:root` selector. Frontend dark mode and admin dark mode each
remap a subset (see "Dark-mode token system" entry).

**Colours:** `--swh-color-primary`, `--swh-color-primary-dark`,
`--swh-color-primary-shadow`, `--swh-color-danger`,
`--swh-color-danger-dark`, `--swh-color-danger-surface`,
`--swh-color-success-bg`, `--swh-color-success-bd`,
`--swh-color-success-text`, `--swh-color-success-accent`,
`--swh-color-error-bg`, `--swh-color-error-bd`,
`--swh-color-error-text`, `--swh-color-warning`,
`--swh-color-warning-bg`, `--swh-color-warning-bd`,
`--swh-color-warning-text`, `--swh-color-info-bg`,
`--swh-color-info-bd`, `--swh-color-info-text`, `--swh-color-text`,
`--swh-color-text-secondary`, `--swh-color-muted`,
`--swh-color-border`, `--swh-color-border-input`,
`--swh-color-border-subtle`, `--swh-color-bg`,
`--swh-color-bg-subtle`, `--swh-color-bg-highlight`,
`--swh-color-surface`, `--swh-color-star`, `--swh-color-focus`,
`--swh-color-track`, `--swh-color-note-bg`,
`--swh-color-note-border`, `--swh-color-note-text`,
`--swh-color-note-bg-hover`, `--swh-color-note-bd-hover`,
`--swh-color-on-solid`.

**Radius:** `--swh-radius-sm` (4px), `--swh-radius-md` (5px), `--swh-radius-pill` (9999px).

**Spacing:** `--swh-space-xs` (5px), `--swh-space-sm` (10px), `--swh-space-md` (15px), `--swh-space-lg` (20px), `--swh-space-xl` (30px).

**Typography:** `--swh-font-sm` (12px), `--swh-font-base` (15px), `--swh-font-md` (16px), `--swh-line-height` (1.6).

**Motion:** `--swh-transition-fast` (0.1s), `--swh-transition-normal` (0.2s), `--swh-ease-out`, `--swh-ease-in-out`.

**Elevation / shadow:** `--swh-shadow-sm`, `--swh-shadow-md`, `--swh-shadow-lg`.

**Z-index scale:** `--swh-z-base` (1), `--swh-z-dropdown` (100), `--swh-z-modal` (200), `--swh-z-toast` (300).
