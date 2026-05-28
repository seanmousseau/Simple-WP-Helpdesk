"""
Playwright pytest helpers for the v4.1.0 REST surface (sections 76–82).

Locked shape per `docs/internal/plan_v4.1.0.md` Phase 10:

    create_api_key(page, scope, label, rate_limit=None) -> {id, plaintext, prefix}
    api_request(page, method, path, key=None, body=None) -> APIResponse
    revoke_all_test_keys(page) -> None       # drives UI
    clear_rate_limits(page) -> None          # invokes WP-CLI shim

Key labels MUST start with `test_` so `revoke_all_test_keys()` can find them.

Anti-patterns (per plan):
  * No direct option writes for key seeding — drive the AJAX UI.
  * Rate-limit teardown is non-optional — sections 80/81 set up via this module.
"""
from __future__ import annotations

import json
import re
from typing import Any, Optional, Literal

from playwright.sync_api import APIResponse, Page

Scope = Literal["read", "write"]


# ── URL bases ────────────────────────────────────────────────────────────────

def _wp_base(page: Page) -> str:
    """Return the WordPress base URL (no trailing slash)."""
    # Imported lazily to avoid circular import (test_helpdesk_pw imports
    # rest_helpers and vice-versa via conftest).
    import test_helpdesk_pw as t
    return t.WP_URL


def _rest_url(page: Page, path: str) -> str:
    """Resolve `/tickets` etc. to a full /wp-json/swh/v1/<path> URL."""
    base = _wp_base(page).rstrip("/")
    path = "/" + path.lstrip("/")
    return f"{base}/wp-json/swh/v1{path}"


# ── API key creation (drives Settings UI) ───────────────────────────────────

def _navigate_to_integrations_tab(page: Page) -> None:
    """Open Settings → Integrations and wait for the API keys section."""
    import test_helpdesk_pw as t
    page.goto(f"{t.WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&page=swh-settings")
    page.wait_for_load_state("load")
    page.wait_for_selector('[role="tablist"]')
    page.locator("#swh-tab-integrations").click()
    page.wait_for_selector("#swh-apikey-generate", state="visible", timeout=5000)
    # Dismiss any pointers so they don't intercept clicks.
    page.evaluate("document.querySelectorAll('.wp-pointer').forEach(el => el.remove())")


def create_api_key(
    page: Page,
    scope: Scope,
    label: str,
    rate_limit: Optional[int] = None,
) -> dict:
    """Drive Settings → Integrations → API Keys UI to create a key.

    Returns: ``{ "id": str, "plaintext": str, "prefix": str }``.

    Labels must begin with ``test_`` so teardown can find them.
    """
    if not label.startswith("test_"):
        raise ValueError(f"API key label must start with 'test_' (got {label!r})")

    _navigate_to_integrations_tab(page)

    page.fill("#swh-apikey-label", label)
    if scope == "write":
        page.check('input[name="swh_apikey_scope"][value="write"]')
    else:
        page.check('input[name="swh_apikey_scope"][value="read"]')

    rate_input = page.locator("#swh-apikey-rate-limit")
    rate_input.fill("")
    if rate_limit is not None:
        rate_input.fill(str(rate_limit))

    # Capture the AJAX response so we can extract `id` + `plaintext` directly
    # rather than scraping the modal (more reliable + faster than DOM polling).
    with page.expect_response(
        lambda r: r.url.endswith("/wp-admin/admin-ajax.php")
        and r.request.method == "POST"
        and "swh_api_key_generate" in (r.request.post_data or "")
    ) as resp_info:
        page.click("#swh-apikey-generate")
    resp = resp_info.value
    payload = resp.json()
    if not payload.get("success"):
        msg = (payload.get("data") or {}).get("message") or "unknown error"
        raise RuntimeError(f"create_api_key failed: {msg}")

    entry = payload["data"]["entry"]
    plaintext = payload["data"]["plaintext"]

    # Wait for the secret modal to appear (visual contract — the UI flow that
    # operators see). Then dismiss it so subsequent calls in the same test
    # don't trip over the open modal.
    modal = page.locator("#swh-apikey-modal")
    modal.wait_for(state="visible", timeout=5000)

    # Operators must "Copy" before close — we don't need the clipboard so we
    # just click Copy (sets `copied = true`) then Close.
    page.click("#swh-apikey-copy")
    page.wait_for_timeout(150)  # button label flip is observable but quick
    page.click("#swh-apikey-close")
    modal.wait_for(state="hidden", timeout=3000)

    return {
        "id": str(entry["id"]),
        "plaintext": str(plaintext),
        "prefix": str(entry.get("prefix", "")),
    }


# ── API request helper ──────────────────────────────────────────────────────

def api_request(
    page: Page,
    method: str,
    path: str,
    key: Optional[str] = None,
    body: Optional[dict] = None,
    headers: Optional[dict] = None,
) -> APIResponse:
    """Issue a REST call against ``/wp-json/swh/v1{path}``.

    ``key`` (if provided) is sent as ``Authorization: Bearer <key>``.

    Returns the raw Playwright ``APIResponse``. Use ``.status``, ``.json()``,
    ``.headers``, ``.text()`` as needed.
    """
    url = _rest_url(page, path)
    hdrs: dict = {"Accept": "application/json"}
    if key:
        hdrs["Authorization"] = f"Bearer {key}"
    if body is not None:
        hdrs["Content-Type"] = "application/json"
    if headers:
        hdrs.update(headers)

    method_u = method.upper()
    ctx = page.context.request
    kwargs: dict[str, Any] = {"headers": hdrs}
    if body is not None:
        kwargs["data"] = json.dumps(body)

    if method_u == "GET":
        return ctx.get(url, **kwargs)
    if method_u == "POST":
        return ctx.post(url, **kwargs)
    if method_u == "PATCH":
        return ctx.patch(url, **kwargs)
    if method_u == "PUT":
        return ctx.put(url, **kwargs)
    if method_u == "DELETE":
        return ctx.delete(url, **kwargs)
    raise ValueError(f"Unsupported HTTP method: {method!r}")


# ── Teardown helpers ────────────────────────────────────────────────────────

def revoke_all_test_keys(page: Page) -> None:
    """Revoke every key with label starting ``test_``.

    Drives the UI to validate the revoke flow alongside the data state. Falls
    back to a CLI-level wipe if the UI couldn't be reached (admin auth lost,
    settings page broken, etc.) — the goal is to NEVER leave test keys behind.
    """
    import test_helpdesk_pw as t
    from wp_cli_helpers import revoke_api_keys_with_label_prefix

    try:
        # Make sure we're logged in as admin before touching the settings UI.
        t.wp_login(page, t.ADMIN_USER, t.ADMIN_PASS)
        _navigate_to_integrations_tab(page)

        # Find every active revoke button labelled with a test_ key. Iterate
        # by id (stable across DOM rewrites) rather than by element handle.
        rows = page.locator(
            "tr[data-swh-apikey-id]:has(.swh-api-key-revoke-btn[data-swh-apikey-label^='test_'])"
        )
        ids = []
        for i in range(rows.count()):
            kid = rows.nth(i).get_attribute("data-swh-apikey-id")
            if kid:
                ids.append(kid)

        for kid in ids:
            btn = page.locator(
                f".swh-api-key-revoke-btn[data-swh-apikey-id='{kid}']"
            )
            if btn.count() == 0:
                continue
            btn.scroll_into_view_if_needed()
            btn.click()
            modal = page.locator("#swh-apikey-revoke-modal")
            modal.wait_for(state="visible", timeout=3000)
            page.click("#swh-apikey-revoke-confirm")
            modal.wait_for(state="hidden", timeout=3000)
    except Exception as ui_err:
        # UI path failed (likely because the suite is mid-teardown). Fall back
        # to CLI wipe. revoke_api_keys_with_label_prefix is idempotent. If the
        # CLI wipe ALSO fails, surface the error — otherwise test runs would
        # pass while leaving stale keys in the DB that contaminate later runs.
        import sys
        try:
            revoke_api_keys_with_label_prefix(t.wpcli, "test_")
        except Exception as cli_err:
            print(
                f"revoke_all_test_keys: UI cleanup failed ({ui_err!r}) "
                f"AND CLI fallback failed ({cli_err!r}) — test keys may "
                f"leak between runs",
                file=sys.stderr,
            )
            raise


def clear_rate_limits(page: Page) -> None:
    """Clear every SWH REST rate-limit bucket (transient + option floor).

    Delegates to the WP-CLI shim in `wp_cli_helpers`. ``page`` is accepted for
    signature symmetry with the rest of this module but currently unused.
    """
    import test_helpdesk_pw as t
    from wp_cli_helpers import swh_test_clear_rate_limits
    swh_test_clear_rate_limits(t.wpcli)


# ── Misc utilities ──────────────────────────────────────────────────────────

_PLAINTEXT_RE = re.compile(r"^swhk_[A-Za-z0-9_-]+$")


def looks_like_plaintext_key(value: str) -> bool:
    """Light shape check for a freshly-generated plaintext API key."""
    return bool(_PLAINTEXT_RE.match(value or ""))
