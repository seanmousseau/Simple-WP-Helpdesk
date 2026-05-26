# Contract: Settings

The 10-tab admin Settings page. Every operator-tunable knob in the plugin is here. This contract describes the page's structure, how saves are gated, and the rules for adding a new setting.

> Touch this contract before adding a new tab, a new setting, or changing how saves are dispatched.

---

## Surface

| Entry point | Path |
|---|---|
| Admin page render | `simple-wp-helpdesk/admin/class-settings.php` |
| Save handler | `swh_handle_settings_save()` in the same file |
| Defaults | `swh_get_defaults()` in `simple-wp-helpdesk/includes/helpers.php` |
| Operator-facing doc | end-user `docs/configuration.md` |
| Internal reference | `config-reference.md` (full key list with types and defaults) |

The page lives under **Tickets → Settings** (capability: `manage_options`).

---

## Tab inventory

| Tab | `data-tab` | Group key (in `swh_get_option`) |
|---|---|---|
| General | `tab-general` | `general` |
| Assignment & Routing | `tab-routing` | `routing` |
| Portal | `tab-portal` | `portal` |
| Email Templates | `tab-emails` | `email_templates` |
| Messages | `tab-messages` | `messages` |
| Anti-Spam | `tab-spam` | `antispam` |
| Integrations | `tab-integrations` | `integrations` |
| Canned Responses | `tab-canned` | `tools` |
| Templates | `tab-templates` | `tools` |
| Tools | `tab-tools` | `tools` |

10 tabs total. Tabs render as semantic `role="tab"` buttons in a `role="tablist"`; panels are `role="tabpanel"`. Keyboard arrow-key navigation is supported.

The Canned/Templates/Tools group key collision is historical and documented in `config-reference.md`.

---

## Multi-form save pattern

The settings page has **three** independent form-submission paths, each with its own nonce. This is a hard invariant — moving fields between paths silently resets them on save.

| Form | Nonce action | Nonce field | Owns |
|---|---|---|---|
| Main settings | `swh_save_settings_action` | `swh_settings_nonce` | All tabs except the Tools tab |
| Tools — retention | `swh_save_tools_action` | `swh_tools_nonce` | `swh_retention_*`, `swh_delete_on_uninstall` |
| Tools — danger zone | `swh_danger_action` | `swh_danger_nonce` | Purge All Tickets, GDPR Email Purge, Factory Reset (one shared nonce; each action discriminates on its own POST flag) |

Why separate: the Tools tab handles **dangerous** operations (purge, reset). They are intentionally segregated from the main save handler so that an unrelated save can't trigger them, and the danger-zone actions share a single dedicated nonce to keep destructive operations behind a clearly-named action key.

---

## Adding a new setting

Five steps. Skipping any of them is a release blocker.

1. **Add the key + default to `swh_get_defaults()`** in `includes/helpers.php`. Choose a name with the `swh_` prefix.
2. **Document it in `config-reference.md`** — name, type, default, group, where it's used.
3. **Add the field markup** to the appropriate tab in `admin/class-settings.php`. Use semantic `<fieldset>` + `<legend>` for grouped fields. Use the `swh_field()` helper where applicable.
4. **Add the save block** to `swh_handle_settings_save()`. Pick the right form — main or Tools — based on the rules above.
5. **Sanitize and validate.** Every input goes through `sanitize_text_field` / `sanitize_email` / `absint` / `wp_kses_post` as appropriate. No raw `$_POST` writes to options.

The installer's upgrade routine (`swh_upgrade`) reads `swh_get_defaults()` to register any new keys with `add_option`, so existing sites get the new option on plugin update without code changes.

---

## Defaults are the single source of truth

`swh_get_defaults()` returns the canonical list of every option the plugin owns. Used by:

- The installer (registers options on activation + upgrade).
- The factory reset action (restores every option to its default).
- The uninstall routine (deletes every option listed).
- `swh_get_option( $group, $key, $fallback )` and `swh_get_string_option( $key, $fallback )` helpers.

Not adding a new option here means the option will:

- Not be registered on fresh installs.
- Not be reset by Factory Reset.
- Not be cleaned up on uninstall.
- Survive plugin removal as orphan data.

---

## Anti-spam conditional rendering

The Anti-Spam tab conditionally shows rows based on the selected method:

- `recaptcha` → show site/secret keys, type radio (v2 / Enterprise).
- `recaptcha` + Enterprise → additionally show project ID, API key, threshold.
- `turnstile` → show site/secret keys.

This is implemented via inline JS that toggles `style.display` on rows with `.swh-recaptcha-row`, `.swh-recaptcha-enterprise-row`, `.swh-recaptcha-v2-row`, `.swh-turnstile-row` classes. State is rebuilt on every change of `swh_spam_method` or `swh_recaptcha_type`.

Servers-side, irrelevant fields are still **read and stored** — they're just hidden. This avoids the "saved-then-lost" antipattern.

---

## Save flow

1. POST hits `swh_handle_settings_save()`.
2. Capability check: `current_user_can( 'manage_options' )`.
3. Nonce check: appropriate to the form. Failed → redirect with error notice.
4. Each save block reads `$_POST`, sanitizes, calls `update_option` (or `swh_set_option` for grouped keys).
5. Redirect with `?swh_notice=saved` → triggers the toast on page load. The URL param is removed via `history.replaceState` so a refresh doesn't re-trigger.

---

## Toast notifications

Globally available on any page that loads `swh-admin.js`:

```js
swhToast( message, type = 'success', duration = 4000 )
```

| Param | Type | Notes |
|---|---|---|
| `message` | string | Text to display |
| `type` | `'success'` \| `'error'` \| `'info'` | Visual variant |
| `duration` | number | ms; defaults to 4000 |

Auto-dismisses, dismissable via the × button, slides in from bottom-right. Used for save confirmations, Send Test Email feedback, and reset operations.

---

## Tools tab — the danger zone

Three actions, each behind a confirmation dialog, all sharing the `swh_danger_action` nonce (discriminated by the per-action POST flag — `swh_purge_tickets`, `swh_gdpr_delete`, `swh_factory_reset`):

| Action | Effect |
|---|---|
| Purge All Tickets | Deletes every ticket, attachment, and reply. No recovery. |
| GDPR Email Purge | Deletes all tickets, replies, and files tied to a specific email address. |
| Factory Reset | Resets all settings to defaults via `swh_get_defaults()`. Tickets untouched. |

Each requires `manage_options`. Each must be approved by the user in the operator UI via a JS confirm prompt before the form submits.

---

## Invariants

1. **Two forms, two nonces.** Don't merge them.
2. **Defaults are sole source.** Every option appears in `swh_get_defaults()`.
3. **`manage_options` capability check** runs before any save.
4. **Sanitize at the boundary** — never trust `$_POST`.
5. **Toast is the universal save feedback** — no inline "Settings saved" banners.
6. **Tab markup is semantic** — `role="tablist"`, `role="tab"`, `role="tabpanel"`, arrow-key nav.
7. **Conditional rows still save.** Hiding ≠ ignoring on POST.

---

## What integrators may rely on

- The `swh_save_settings_action` + `swh_save_tools_action` nonce action names within v4.x.
- The `swh_get_defaults()` shape (associative array, top-level keys).
- The `manage_options` capability gate.

## What integrators may NOT rely on

- The DOM structure of individual tab markup (we may rework with React in a future major).
- The inline conditional-row JS (private impl detail).
- The specific class names like `.swh-recaptcha-enterprise-row`.

---

## Routing

| Touching … | Read also |
|---|---|
| Add a setting | This doc + `config-reference.md` + `data-dictionary.md` + `i18n.md` (label) |
| Add a tab | This doc + `design-guide.md` + `accessibility.md` (tablist semantics) |
| Touch the save handler | This doc + `security-model.md` (nonce + sanitisation) |
| Touch the toast | `component-inventory.md` + this doc |
| Add a dangerous action | This doc + `security-model.md` (capability + confirmation) |
