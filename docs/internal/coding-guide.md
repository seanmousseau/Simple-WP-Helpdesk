# Coding Guide

**Audience:** plugin contributors.

This is the project's coding-conventions reference. Style is enforced by automation (PHPCS / PHPStan / Semgrep / pre-push hook); this doc explains the **why** behind the rules and the patterns that automation cannot enforce.

## Language baseline

| Layer | Version floor | Source |
|---|---|---|
| PHP | 7.4 | `Requires PHP:` header in `simple-wp-helpdesk/simple-wp-helpdesk.php:7`. |
| WordPress | 5.3 | `Requires at least:` in the same header. |
| PHPStan | level 9 | `phpstan.neon`. Requires PHP 8.1+ to run (dev-time). |
| PHPCS ruleset | WordPress Coding Standards | `.phpcs.xml`. Zero errors and zero warnings required. |
| JS build | `@wordpress/scripts` | `package.json`. Output to `simple-wp-helpdesk/assets/dist/`. Vanilla JS — no JSX in the current PoC. |

We do not use features beyond the PHP 7.4 baseline in shipped plugin code. Dev-time tooling (PHPStan, PHPUnit) may require newer PHP.

## Naming

| Kind | Prefix / convention | Example |
|---|---|---|
| Global function | `swh_` snake_case | `swh_get_defaults()`, `swh_send_email()` |
| Legacy class | `SWH_` PascalCase | (legacy; minimal usage) |
| PSR-4 namespaced class (v3.7+) | `SWH\…` | `SWH\Foo\Bar` under `simple-wp-helpdesk/src/` |
| Custom post type slug | snake_case literal | `helpdesk_ticket` |
| Comment type | snake_case literal | `helpdesk_reply` |
| Taxonomy slug | snake_case literal | `helpdesk_category` |
| Option | `swh_` prefix | `swh_default_priority` |
| Post meta | `_ticket_` or `_swh_` prefix (leading underscore = hidden) | `_ticket_status`, `_swh_unread` |
| Comment meta | `_is_*` or `_swh_*` | `_is_internal_note`, `_swh_reply_orignames` |
| Transient | `swh_` prefix | `swh_unread_count`, `swh_report_*` |
| Rate-limit key (stored as option) | `swh_rl_` + md5 | `swh_rl_<hash>` |
| Cron lock transient | `swh_lock_` + slug | `swh_lock_autoclose` |
| Capability | `edit_helpdesk_tickets` style (custom) or post caps | granted via Technician role in `class-installer.php:18-41` |
| CSS class | `swh-` kebab-case | `swh-empty-state`, `swh-panel-group` |
| JS handle | `swh-` kebab-case | `swh-toast` |

The uninstall sweep at `class-installer.php:226-229` relies on these prefixes. A mis-prefixed key will persist forever.

## Security conventions (hot list)

Each rule below is enforced by automation, code review, or both. See `security-model.md` for rationale and trust boundaries.

| Rule | One-liner |
|---|---|
| Nonces | Every form, AJAX, and **cookie-authenticated** REST handler verifies a WP nonce via `wp_verify_nonce()` or `check_ajax_referer()`. Token-authenticated REST endpoints (e.g. the inbound-email Bearer endpoint, `class-email.php`) verify the token with `hash_equals()` instead — they have no cookie context for a nonce. |
| Capability checks | `manage_options` for plugin-admin actions; `edit_post` for ticket-specific actions; REST and AJAX always check after the nonce. |
| Token compare | Always `hash_equals( $expected, $provided )`. Never `==` or `===` on token strings. |
| Client IP | Always `swh_get_client_ip()`. Never `$_SERVER['REMOTE_ADDR']` directly. |
| File serving | Always through `swh_serve_file()`. Path must resolve inside `wp_get_upload_dir()['basedir']`. |
| Anti-spam | Public POST handlers call `swh_check_antispam( $check_captcha )` before persisting. |
| Rate limiting | Public POST handlers call `swh_is_rate_limited( $per_action_key )` before persisting. |
| Closed-defaults | Misconfigured reCAPTCHA / Turnstile fails **closed** — see `helpers.php:392-393, 404`. |

## Sanitization and escaping

Sanitize on input; escape on output. Pick the function that matches the destination:

| Untrusted input → | Function | Notes |
|---|---|---|
| Plain text (single line) | `sanitize_text_field()` | strips tags, normalises whitespace |
| Email address | `sanitize_email()` | combine with `is_email()` for validation |
| Integer ID | `absint()` | for non-negative ints; `intval()` for signed |
| HTML body (admin-only) | `wp_kses_post()` | retains the WP post allowlist |
| File name | `sanitize_file_name()` | use before storing or echoing |
| Slug | `sanitize_title()` | bulk action keys derive from this |
| Raw POST scalar | `wp_unslash()` then sanitize | always unslash before passing to a sanitizer |

| Output context → | Function |
|---|---|
| HTML text node | `esc_html()` |
| HTML attribute value | `esc_attr()` |
| URL | `esc_url()` (rendered) or `esc_url_raw()` (stored) |
| Translation string with HTML | `wp_kses()` with explicit allowlist |
| JSON | `wp_send_json_*()` / `wp_json_encode()` — both escape correctly |

Calling sites worth reading as canonical examples: `swh_get_client_ip()` at `helpers.php:356-365` (input), `swh_render_empty_state()` at `helpers.php:811-828` (output, with `wp_kses` allowlist for an inline SVG).

## Error handling

The project prefers explicit early-return guards over try/catch in PHP.

- **WP API failures:** check `is_wp_error()` on every `wp_remote_*`, `wp_insert_*`, `wp_update_*` return.
- **AJAX failures:** return via `wp_send_json_error( array( 'message' => __( '…', 'simple-wp-helpdesk' ) ), $http_status )`. Always include a translatable message and a status code.
- **REST failures:** return `new WP_Error( $code, $message, array( 'status' => $http_status ) )`.
- **Silent fallback:** acceptable only when the surface is genuinely cosmetic (e.g. attachment origname fallback to `basename($url)` for pre-v2.3.0 data). Anywhere a write or auth check fails, log nothing but surface a user-visible error.

Canonical pattern — see the merge AJAX handler at `simple-wp-helpdesk.php:183-199`:

```php
function swh_ajax_merge_ticket() {
    check_ajax_referer( 'swh_merge_ticket', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'simple-wp-helpdesk' ) ), 403 );
    }
    $source_id = isset( $_POST['source_id'] ) && is_scalar( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
    $target_id = isset( $_POST['target_id'] ) && is_scalar( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
    if ( ! $source_id || ! $target_id || $source_id === $target_id ) {
        wp_send_json_error( array( 'message' => __( 'Invalid ticket IDs.', 'simple-wp-helpdesk' ) ) );
    }
    if ( ! swh_merge_tickets( $source_id, $target_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Merge failed. Check that both tickets exist.', 'simple-wp-helpdesk' ) ) );
    }
    wp_send_json_success( array( 'message' => __( 'Tickets merged successfully.', 'simple-wp-helpdesk' ) ) );
}
```

Nonce → capability → input sanitization → input validation → action → JSON response. In that order.

## Comments policy

Comment the **WHY**, not the **WHAT**. Reading well-named code already tells the reader what it does; a comment that paraphrases the code is noise.

Comment is required when:
- A piece of code exists to work around a specific WP behaviour, host quirk, or upstream bug. Include the version or reproduction step.
- A `phpcs:ignore` or `nosemgrep:` is being added — the comment justifies why it is safe.
- A non-obvious invariant is being preserved. The canonical example is the docblock on `swh_set_ticket_status()` at `helpers.php:144-163` — it explains why initial-create callers must call `update_post_meta()` directly rather than going through the helper.

Comment is **not** wanted when:
- Restating the function name (`// Get the user IP` above `$ip = swh_get_client_ip();`).
- Describing trivially obvious branching (`// if empty, return`).
- Explaining what a well-named WP function does.

Docblocks on public functions are mandatory and PHPCS-enforced; they document parameters, return type, and `@since`.

## i18n

- Wrap every user-facing string with `__()`, `_e()`, `esc_html__()`, or `esc_html_e()`.
- Text domain is `'simple-wp-helpdesk'` everywhere.
- **Do not** wrap admin-editable default values (the defaults in `swh_get_defaults()` at `helpers.php:22-118`). They are stored verbatim in `wp_options`, and translators have no way to translate an option that the operator can edit. They become the operator's content the moment the plugin activates.
- Use `printf` / `sprintf` with `%s` / `%d` for substitutions, and include a `/* translators: */` comment when the substitution is non-obvious. See examples at `simple-wp-helpdesk.php:111, 117, 125`.

## JS conventions

See `js-architecture.md`. In short:

- Vanilla JS, built via `@wordpress/scripts` to `assets/dist/`.
- One IIFE per module; no global mutation other than a documented `window.swh*` namespace (e.g. `window.swhToast`).
- Asset manifests (`*.asset.php`) drive `wp_enqueue_script` version + deps — see `swh_enqueue_toast_script()` at `helpers.php:864-891`.

## PR-time gates

These are non-negotiable. The pre-push hook enforces #1; reviewers enforce the rest.

1. **`make test-docker` passes locally before push.** No `--no-verify`. No host-PHP fallback.
2. **Schema-ish change?** If you added, renamed, or removed any option / post meta / comment meta / transient / capability / CPT / taxonomy — update `data-dictionary.md` in the same PR.
3. **Setting change?** If you added, changed the default of, or removed any item in `swh_get_defaults()` — update `config-reference.md` in the same PR.
4. **Public API change?** If you added, changed the signature of, or removed any `do_action('swh_*')`, `apply_filters('swh_*')`, REST endpoint, AJAX endpoint, or shortcode attribute — update `api-contract.md` and `docs/developer/hooks.md` in the same PR. See `api-contract.md` for the SemVer breaking-change taxonomy.
5. **Security posture change?** If you added a new untrusted input source, a new auth flow, a new file or URL handling path — update `security-model.md` in the same PR.
6. **New UI primitive or design token?** Update `component-inventory.md` in the same PR.
7. **User-facing feature or regression?** A new or extended Playwright section in `testing/scripts/test_helpdesk_pw.py` ships in the same PR. See `testing-guide.md` for the test update policy.
8. **Operator-visible change?** Update `docs/` (user-facing) and `CHANGELOG.md` in the same PR.

## Update protocol

Update this doc when:
- A new sanitization/escaping function becomes a standard pattern in the codebase.
- A new PR-time gate is added or an existing one is dropped.
- The PHP / WP version floor changes.
- A naming convention is added (new prefix, new namespace).
- The "canonical pattern" example becomes stale because a better one lands.
