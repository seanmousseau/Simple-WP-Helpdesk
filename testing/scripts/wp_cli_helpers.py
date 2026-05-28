"""
Thin WP-CLI helpers for Playwright E2E tests.

Phase 10 of the v4.1.0 plan. The legacy `_clear_rate_limits()` helper in
`test_helpdesk_pw.py` predates the v4.1 RateLimiter and only deletes the
legacy `swh_rl_*` *options*. The v4.1 RateLimiter writes:

  - transients prefixed `swh_rl_api_*` and `swh_rl_ip_*`, and
  - a wp_options floor prefixed `_swh_rl_*` (fallback storage written by
    `\\SWH\\Rest\\RateLimiter::write()` so the limit survives a transient
    flush — see Phase 7 plan & `RateLimiter.php`).

`swh_test_clear_rate_limits()` (a global function defined inline below) is the
canonical "fresh state" reset used by Phase 10 Python tests. Implementation
matches `tests/Support/RateLimitTestHelpers.php` (the PHPUnit-side helper) so
unit and E2E paths converge on the same SQL.
"""
from __future__ import annotations

import base64
from typing import Optional


def _php_str(value: str) -> str:
    """Render a Python string as a PHP expression that evaluates to the value.

    Defends against BOTH layers of interpretation that the value will pass
    through on the way to PHP:

    1. **PHP parser** — the expression is embedded in a `wp eval` payload.
       Naive interpolation could let a caller-supplied label/title close the
       PHP string literal and inject arbitrary PHP.
    2. **Shell quoting** — the payload is then handed to `sh -c "wp eval
       '...'"` (and, in SSH mode, through a second shell on the remote
       host). A literal `'` in the value (e.g. ``"O'Brien"``) would break
       the outer single-quoted shell argument.

    Base64-encoding satisfies both: the output is in the `[A-Za-z0-9+/=]`
    alphabet — no characters with special meaning in PHP string syntax or
    in shell quoting. PHP decodes at runtime via `base64_decode()`.
    """
    b64 = base64.b64encode(value.encode("utf-8")).decode("ascii")
    return f'base64_decode("{b64}")'


_CLEAR_RL_PHP = (
    'global $wpdb; '
    '$wpdb->query("DELETE FROM {$wpdb->options} WHERE '
    "option_name LIKE '\\\\_transient\\\\_swh\\\\_rl\\\\_%' "
    "OR option_name LIKE '\\\\_transient\\\\_timeout\\\\_swh\\\\_rl\\\\_%' "
    "OR option_name LIKE 'swh\\\\_rl\\\\_%' "
    "OR option_name LIKE '\\\\_swh\\\\_rl\\\\_%'"
    '"); '
    'wp_cache_flush();'
)


def swh_test_clear_rate_limits(wpcli) -> None:
    """Clear every SWH REST rate-limit bucket (transient + option floor).

    Args:
        wpcli: The module-level ``wpcli(cmd: str) -> str`` helper from
            ``test_helpdesk_pw.py`` (passed in to avoid a circular import).

    Idempotent. Safe to call from setUp and tearDown.
    """
    wpcli(f"eval '{_CLEAR_RL_PHP}'")
    # Belt-and-suspenders: object-cache may hold a stale read past the SQL.
    wpcli("cache flush")


def revoke_api_keys_with_label_prefix(wpcli, prefix: str = "test_") -> None:
    """Wipe every API key whose label starts with `prefix`.

    Used as a teardown safety net — `revoke_all_test_keys()` in
    `rest_helpers.py` is the primary path (drives the UI) but a previous run
    may have left rows behind if the suite crashed mid-test. The CLI path
    deletes the entries outright from the integrations grouped option so the
    next run starts clean.

    Args:
        wpcli: The module-level ``wpcli(cmd)`` helper.
        prefix: Label prefix to match (default ``test_``).
    """
    php = (
        f"$prefix = {_php_str(prefix)}; "
        "$opt = get_option('swh_settings_integrations', array()); "
        "if (!is_array($opt)) { $opt = array(); } "
        "$keys = isset($opt['api_keys']) && is_array($opt['api_keys']) ? $opt['api_keys'] : array(); "
        "$kept = array(); "
        "foreach ($keys as $entry) { "
        "  if (is_array($entry) && isset($entry['label']) && is_string($entry['label']) "
        "      && strpos($entry['label'], $prefix) === 0) { continue; } "
        "  $kept[] = $entry; "
        "} "
        "$opt['api_keys'] = $kept; "
        "update_option('swh_settings_integrations', $opt);"
    )
    wpcli(f"eval '{php}'")


def seed_test_ticket(wpcli, title: str, content: str = "seed body") -> Optional[int]:
    """Create a published `helpdesk_ticket` and return its post id (or None)."""
    php = (
        "$id = wp_insert_post(array('post_type'=>'helpdesk_ticket',"
        f"'post_title'=>{_php_str(title)},'post_content'=>{_php_str(content)},"
        "'post_status'=>'publish')); echo (int) $id;"
    )
    out = wpcli(f"eval '{php}'")
    try:
        pid = int(out.strip().splitlines()[-1])
        return pid if pid > 0 else None
    except (ValueError, IndexError):
        return None
