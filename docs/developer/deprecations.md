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
