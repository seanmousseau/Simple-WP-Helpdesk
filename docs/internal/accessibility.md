# Accessibility (a11y)

The plugin targets **WCAG 2.2 Level AA** across both light and dark themes, in both the admin and frontend surfaces. This doc is the working reference for contributors — what we ship today, what we test against, and what we won't compromise on.

> Accessibility is not a feature. It is a quality bar that applies to every UI change. A PR that ships an inaccessible UI is incomplete.

---

## What we ship today

- **Skip-to-content** link on Settings and Reports pages — the first focusable element jumps over WP chrome.
- **`:focus-visible` focus rings** using `--swh-color-focus` — visible for keyboard users, hidden for mouse clicks.
- **44×44 px touch targets** on CSAT stars, merge toggle, and status filter chips (WCAG 2.5.5).
- **ARIA live regions** on SLA badge transitions and KPI card value updates (`aria-live="polite"`).
- **Semantic fieldsets + `<legend>`** group every settings tab and form section.
- **Heading hierarchy** has been audited — no skipped levels, no duplicate `<h1>` across portal or admin views.
- **Helper `swh_render_empty_state( $title, $desc, $icon_svg_path, $heading_level = 'h2' )`** so every empty-state emits the correct heading level for its surrounding context.
- **Internal-note contrast** raised from AA (4.5:1) to AAA (7:1) on the amber background.
- **Focus management on dialogs and transitions:** CSAT submit moves focus to the thank-you message; portal token expiry moves focus to the lookup form; modal Esc returns focus to the trigger.
- **Logical CSS properties** (`margin-inline-*`, `padding-block-*`) so RTL locales render correctly.

---

## Hard rules

Apply to every PR. None are negotiable.

### 1. Every interactive element must be keyboard-reachable

Test by literally tabbing through. Focus must be:

- **Visible** (focus ring present).
- **In a logical order** (DOM order = tab order; no positive `tabindex`).
- **Not trapped** (Esc dismisses any modal that doesn't have a "Close" button).

### 2. Every form control has a programmatic label

- `<label for>` paired with `id` on the input, **or**
- `aria-label` / `aria-labelledby` if no visible label exists (rare — prefer visible labels).

Placeholder text is **not** a label.

### 3. Every icon-only button has an accessible name

```php
<button type="button" aria-label="<?php esc_attr_e( 'Close', 'simple-wp-helpdesk' ); ?>">
    <span class="dashicons dashicons-no" aria-hidden="true"></span>
</button>
```

The icon itself is `aria-hidden="true"` so screen readers don't read out "dashicons-no".

### 4. Color is never the only signal

Status badges have a colored background **and** an unambiguous label. SLA states use both color and the words "Warning" / "Breach". Form errors use color + an icon + an error message.

### 5. Contrast minimums

| Surface | Minimum |
|---|---|
| Body text | 4.5:1 |
| Large text (18px / 14px bold) | 3:1 |
| Focus rings | 3:1 against adjacent colors |
| UI components (button borders, form inputs) | 3:1 |
| Internal notes amber background | 7:1 (AAA — we exceed AA here intentionally) |

Check with Chrome DevTools or `mcp__plugin_chrome-devtools-mcp_chrome-devtools__lighthouse_audit`.

### 6. Heading hierarchy is consistent

- One `<h1>` per page, matching the page title.
- Never skip a level (no `<h2>` → `<h4>`).
- Use the `swh_render_empty_state` helper's `$heading_level` argument when rendering empty states inside an existing heading context.

### 7. ARIA only where HTML can't do the job

Prefer native semantics:

- `<button>` not `<div role="button">`.
- `<fieldset>` + `<legend>` for grouped form controls.
- `<nav>`, `<main>`, `<aside>` for landmarks.
- `<details>`/`<summary>` for collapsible sections.

Use ARIA when HTML genuinely doesn't express the pattern: live regions, dialogs (`role="dialog"` + `aria-modal`), composite widgets (tablist/tab/tabpanel).

### 7a. Per-component checklist — shipped surfaces

#### Shown-once secret modal (`.swh-apikey-modal`, v4.1.0 #365)

- [x] `role="dialog"`, `aria-modal="true"`, `aria-labelledby` → heading id, `aria-describedby` → warning-banner id.
- [x] Initial focus on the **Copy** button (highest-stakes action — no tab presses needed to reach it).
- [x] Wrap-around focus trap across `[Copy → readonly input → Close]` (extends with `[Cancel, Close anyway]` when the un-copied confirmation is shown).
- [x] ESC behaves like Close — triggers the "haven't copied yet?" inline confirmation if plaintext hasn't been copied.
- [x] On close, focus returns to the opener (the Generate button).
- [x] Plaintext input is `readonly` and carries `aria-label="Generated API key (copy now — you will not see this again)"`.
- [x] Copy success is announced via `aria-live="polite"` region ("API key copied to clipboard.") AND visually via a button-label flip (`Copy` → `Copied!`) for 2 s.
- [x] Backdrop click follows the same un-copied-confirmation path as Close (no silent destruction of the only copy of the secret).

#### API keys table (Integrations tab)

- [x] Real `<table>` with `<thead>` / `<tbody>` and `<th scope="col">` on every header.
- [x] Per-row Revoke button has `aria-label="Revoke key <label>"` so screen-reader users get the row context without sniffing surrounding cells.
- [x] Revoked rows: keep DOM order, drop opacity to 0.55, strike-through the label cell, and replace the Revoke button with a `<span class="swh-api-key-revoked-label">Revoked <date></span>` — opacity is a reinforcing signal, the strike-through + the textual "Revoked" label are the primary signals (no color-only encoding).
- [x] Confirmation modal for revoke uses the same dialog/aria-modal pattern; default focus is **Cancel** so accidental Enter presses don't destroy a working key.

### 8. Dark mode parity

Every check above applies in dark mode too. A focus ring that's visible in light mode but invisible in dark is a bug. Run audits in both modes.

---

## Testing

### Automated checks

- **Lighthouse** via Chrome DevTools MCP: `mcp__plugin_chrome-devtools-mcp_chrome-devtools__lighthouse_audit` on every new page or major UI change. Accessibility category must score ≥ 95.
- **`chrome-devtools-mcp:a11y-debugging` skill** — use when reviewing UI work for semantic HTML, ARIA, focus, keyboard nav, tap targets, contrast.

### Manual checks per PR

For any UI-touching PR, the author confirms (in the PR description):

- [ ] Tabbed through every interactive element — focus visible, order logical, no traps.
- [ ] Tested in dark mode (frontend `prefers-color-scheme: dark`; admin under a dark WP color scheme).
- [ ] Lighthouse a11y score ≥ 95.
- [ ] No color-only signals.
- [ ] No new `tabindex > 0`.

### Screen-reader spot-checks

Once per release (Gate 2 — extended test suite) we run VoiceOver against:

- Submission form (empty, with validation errors, with file uploads).
- Portal view of a Resolved ticket (Close block + CSAT widget).
- Ticket editor with status change + reply (admin).
- Settings → General + Settings → Email Templates.

We are not running NVDA / JAWS regularly today — note this as a known limitation. Issue #TBD tracks adding NVDA to the manual sign-off.

---

## What we don't do (yet)

These are deliberate gaps — tracked, not forgotten:

- **Full WCAG AAA across the board.** We hit AAA on selected high-stakes surfaces (internal notes) but the broad target is AA. Bumping the whole UI to AAA contrast would conflict with the brand palette.
- **Voice-control specific affordances** (over and above keyboard).
- **High-contrast mode** beyond the built-in dark/light themes (Windows High Contrast Mode is supported by virtue of using semantic HTML, but not specifically tested).
- **Reduced-motion overrides** on every animated element — many animations respect `prefers-reduced-motion`, but coverage is not exhaustive. Track in `parked_features.md`.

---

## Routing

| Touching … | Read first |
|---|---|
| Any new UI | This doc + `design-guide.md` |
| A form | `design-guide.md` form patterns + this doc |
| Color tokens / palette | `component-inventory.md` + contrast section above |
| Dialogs / modals / popovers | `component-inventory.md` (dialog primitive) + ARIA section above |
| Reports / Charts | This doc — Chart.js needs explicit aria-labels on the canvas |
| Settings tab additions | This doc + `i18n.md` (every label must be translatable) |

---

## When in doubt

Ask the user. Accessibility decisions sometimes trade off against brand or layout density; those trade-offs require explicit approval, not a unilateral call by the implementer.
