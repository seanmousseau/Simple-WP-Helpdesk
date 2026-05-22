# Deprecation Policy

When SWH deprecates a hook (action or filter), we follow this policy.

## Lifecycle

1. **Deprecate** — wrap the firing site in `swh_apply_deprecated_filter()` or `swh_do_deprecated_action()` with the SWH version that introduced the deprecation. Notices appear in `debug.log` under `WP_DEBUG`.
2. **Document** — add a "Deprecated" section to `docs/developer/hooks.md` listing the old hook, the replacement, and the version of deprecation.
3. **Window** — minimum **two minor releases** between deprecation and removal. E.g. deprecated in 3.7 → not removed before 3.9 (or 4.1, if 4.0 ships first as a major).
4. **Remove** — only in a major version, never in a minor or patch. Removal is a breaking change.

## Version-tag format

The version argument to the helpers is the SWH minor version, e.g. `'3.7'`. The helpers prepend `SWH` when emitting notices: `SWH 3.7`.

## When to deprecate vs hard-remove

- **Public hooks** (documented in `hooks.md`, fired in the developer docs): always deprecate, never remove without notice.
- **Private hooks** (undocumented, internal-only): can be removed in any release. If you suspect external code may have hooked it, deprecate to be safe.

## Examples

### Deprecating a filter

```php
$result = swh_apply_deprecated_filter(
    'swh_legacy_filter_name',
    array( $value, $context ),
    '3.7',
    'swh_new_filter_name',
    'Filter renamed for consistency with the v3.7 lifecycle hooks.'
);
```

### Deprecating an action

```php
swh_do_deprecated_action(
    'swh_legacy_action_name',
    array( $ticket_id ),
    '3.7',
    'swh_ticket_status_changed'
);
```

## 4.0.0 deprecations

The following 8 pre-3.0 filter hooks are now wrapped in `swh_apply_deprecated_filter()`. They continue to fire for existing listeners (back-compat preserved) but emit `_doing_it_wrong` in `WP_DEBUG` environments. Earliest removal: SWH 4.2 (per the 2-minor window policy above).

| Hook | Location | Replacement |
|---|---|---|
| `swh_ticket_statuses` | `includes/helpers.php` | None yet — see v4.1 REST API #364 |
| `swh_ticket_priorities` | `includes/helpers.php` | None yet — see v4.1 REST API #364 |
| `swh_allowed_file_types` | `frontend/class-shortcode.php` (2 sites) | None yet |
| `swh_submission_data` | `frontend/class-shortcode.php` | Subscribe via the v4.1 REST API events |
| `swh_autoclose_threshold` | `includes/class-cron.php` | None yet |
| `swh_rate_limit_ttl` | `includes/helpers.php` | None yet |
| `swh_parse_template` | `includes/class-email.php` | None yet |
| `swh_email_headers` | `includes/class-email.php` | None yet |

`swh_sla_open_statuses` (in `includes/class-cron.php`) is post-3.0 and remains supported.
