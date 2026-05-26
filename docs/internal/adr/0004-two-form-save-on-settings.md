# ADR-0004: Two-form save pattern on the settings page

- **Status:** Accepted
- **Date:** 2024-12-01 (back-dated; codified after the Tools-tab incident)
- **Deciders:** Sean Mousseau

## Context

The plugin's settings page hosts ~100 operator-tunable options across ten tabs. The most natural WP pattern is one big `<form>` wrapping all the tabs, one nonce, one POST handler. Initially that's what we had.

Two problems surfaced:

1. **The Tools tab contains destructive operations** — Purge All Tickets, GDPR Email Purge, Factory Reset. A single shared form means a typo in the save handler could trigger one of these silently. The blast radius of a settings-form bug becomes "we deleted all tickets".
2. **Retention settings on the Tools tab kept resetting** when other tabs were saved. The shared handler had branches per tab, and a missing `else` branch was nulling the retention values. Reproducible across multiple PRs.

We needed a structural separation, not just discipline.

## Decision

**Render two independent forms on the settings page, each with its own nonce:**

| Form | Nonce action | Owns |
|---|---|---|
| Main settings | `swh_save_settings_action` | Every tab except Tools |
| Tools form | `swh_save_tools_action` | Retention settings, `swh_delete_on_uninstall`, factory reset trigger, GDPR purge trigger |

A POST to the main form cannot touch any Tools-owned option, and vice versa, because the two nonces verify against different actions.

## Consequences

### Costs

- **Slightly more complex render.** The Tools tab's panel is rendered outside the main `<form>` element in HTML, and contains its own `<form>`. Operators who expect a "save changes" button in the same location on every tab get a different (Tools-specific) button there.
- **Two save buttons exist on the page** — operators must save Main and Tools separately when changing settings in both. We accept this; the alternative (cross-form save) reintroduces the original bug.
- **Documentation overhead.** This pattern surprises contributors and has to be called out in `contract-settings.md`.

### Benefits

- **Destructive operations are structurally segregated** from routine save flows. A bug in the main save handler cannot trigger a purge.
- **The bug class that motivated this is eliminated.** The retention-reset bug cannot recur — the main handler does not see retention keys.
- **The Tools tab can have its own confirmation UX** without disrupting the main form's flow.
- **Each form has a single responsibility.** Main = save settings; Tools = perform operations.

## Alternatives considered

- **Single form, branching save handler with strict allowlists per tab.** Original approach. Failed twice. Not robust to future contributor edits.
- **Single form, with destructive operations behind a separate AJAX endpoint.** Better than the original but added complexity (CSRF tokens on top of the form nonce, separate JS) and didn't solve the retention-reset bug.
- **Move the Tools tab to a separate admin page (`Tools → Helpdesk`).** Cleanest separation but adds a navigation indirection. We kept it as a tab for discoverability.

## Implementation invariants

These are restated in `contract-settings.md`:

- `swh_retention_*` and `swh_delete_on_uninstall` MUST live in the Tools form. Moving them to the main form silently resets them.
- The Tools form's nonce action is `swh_save_tools_action`. Don't reuse the main action name.
- Both handlers verify `current_user_can( 'manage_options' )` separately.

## Evolution

The pattern has since extended to **three** form-submission paths: the original Main + Tools split is still in place, and the Tools tab additionally has a separate **danger-zone** form (`swh_danger_action` nonce) for Purge / GDPR / Factory Reset. The split rationale is unchanged — the danger-zone form just makes the segregation more explicit. See `contract-settings.md` for the current shape.

## See also

- `contract-settings.md` — full settings contract.
- `security-model.md` — capability + nonce model.
