#!/usr/bin/env python3
"""
Screenshot capture for the RC runbook's visual sanity section.

Drives a headless Chromium via Playwright through the admin inbox,
drawer, reports, settings, and portal in each theme (light/dark/auto),
and saves PNGs to testing/extended/screenshots/<timestamp>/.

The operator attaches a subset to the RC PR (section 7.4 of the runbook).

Usage:

    python testing/extended/capture_screenshots.py
    python testing/extended/capture_screenshots.py --headed   # watch it work
    python testing/extended/capture_screenshots.py --only inbox,drawer

Exit codes:
    0  All captures succeeded
    1  One or more captures failed (others still saved)
    2  Configuration error

Environment:
    WP_TESTING_URL, WP_TESTING_USER, WP_TESTING_PASS

Dependencies:
    pip install playwright && playwright install chromium
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from pathlib import Path

try:
    from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout
except ImportError:
    print("ERROR: playwright not installed. Run: pip install playwright && playwright install chromium",
          file=sys.stderr)
    sys.exit(2)


def env(name: str) -> str:
    v = os.environ.get(name, "")
    if not v:
        print(f"ERROR: env var {name} is not set.", file=sys.stderr)
        sys.exit(2)
    return v


THEMES = ["light", "dark", "auto"]

# Each entry: (slug, admin-path-relative-to-WP_TESTING_URL, optional setup callable)
SHOTS = [
    ("inbox",    "/wp-admin/edit.php?post_type=helpdesk_ticket"),
    ("settings", "/wp-admin/edit.php?post_type=helpdesk_ticket&page=swh-settings"),
    ("reports",  "/wp-admin/edit.php?post_type=helpdesk_ticket&page=swh-reports"),
]


def login(page, wp_url: str, user: str, password: str) -> None:
    page.goto(f"{wp_url}/wp-login.php")
    page.fill("input[name=log]", user)
    page.fill("input[name=pwd]", password)
    page.click("input[name=wp-submit]")
    page.wait_for_load_state("networkidle")


def set_admin_theme(page, wp_url: str, theme: str) -> None:
    """
    Toggle the admin dark-mode setting via wp-cli would be cleaner, but to
    keep this script self-contained we just hit the settings page and update
    the radio. Falls back gracefully if the option isn't present (older builds).
    """
    page.goto(f"{wp_url}/wp-admin/edit.php?post_type=helpdesk_ticket&page=swh-settings")
    page.wait_for_load_state("networkidle")
    radio = page.locator(f'input[name="swh_admin_theme"][value="{theme}"]')
    try:
        radio.first.check(timeout=5000)
        # Save button — try a few common labels.
        for label in ("Save Changes", "Save Settings", "Save"):
            btn = page.get_by_role("button", name=label)
            if btn.count() > 0:
                btn.first.click()
                break
        page.wait_for_load_state("networkidle")
    except PWTimeout:
        # Theme toggle may not exist on older builds — leave whatever the
        # default is and let the screenshots reflect reality.
        pass


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--headed", action="store_true",
                   help="Run with a visible browser instead of headless.")
    p.add_argument("--only", default="",
                   help="Comma-separated list of shot slugs to take (e.g. 'inbox,settings').")
    p.add_argument("--themes", default=",".join(THEMES),
                   help=f"Comma-separated themes. Default: {','.join(THEMES)}")
    p.add_argument("--out-dir", default=None,
                   help="Override the output directory.")
    args = p.parse_args()

    wp_url = env("WP_TESTING_URL").rstrip("/")
    user = env("WP_TESTING_USER")
    password = env("WP_TESTING_PASS")

    shot_filter = {s.strip() for s in args.only.split(",") if s.strip()}
    theme_filter = [t.strip() for t in args.themes.split(",") if t.strip()]
    shots = [s for s in SHOTS if not shot_filter or s[0] in shot_filter]

    # Validate that filters didn't reduce the work set to empty. Without this
    # guard a typo like --only=inboxs silently succeeds with 0 screenshots.
    if shot_filter and not shots:
        valid = ", ".join(s[0] for s in SHOTS)
        print(f"ERROR: --only={args.only!r} matched no known shots. "
              f"Valid: {valid}", file=sys.stderr)
        return 2
    if not theme_filter:
        valid = ", ".join(THEMES)
        print(f"ERROR: --themes={args.themes!r} produced an empty set. "
              f"Valid: {valid}", file=sys.stderr)
        return 2
    unknown_themes = [t for t in theme_filter if t not in THEMES]
    if unknown_themes:
        valid = ", ".join(THEMES)
        print(f"ERROR: --themes contains unknown value(s): {unknown_themes}. "
              f"Valid: {valid}", file=sys.stderr)
        return 2

    ts = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    out_dir = Path(args.out_dir) if args.out_dir else (
        Path(__file__).parent / "screenshots" / ts
    )
    out_dir.mkdir(parents=True, exist_ok=True)
    print(f"Screenshots → {out_dir}")

    failures = 0
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=not args.headed)
        try:
            context = browser.new_context(viewport={"width": 1440, "height": 900})
            page = context.new_page()
            login(page, wp_url, user, password)

            for theme in theme_filter:
                if theme not in THEMES:
                    print(f"  skip unknown theme {theme!r}")
                    continue
                print(f"\n[{theme}]")
                set_admin_theme(page, wp_url, theme)
                for slug, path in shots:
                    target = f"{wp_url}{path}"
                    out_path = out_dir / f"{slug}-{theme}.png"
                    try:
                        page.goto(target)
                        page.wait_for_load_state("networkidle")
                        # Give React mount + dark-mode CSS a beat to settle.
                        page.wait_for_timeout(1200)
                        page.screenshot(path=str(out_path), full_page=True)
                        print(f"  ✓ {out_path.name}")
                    except Exception as e:  # noqa: BLE001 — capture-all
                        failures += 1
                        print(f"  ✗ {out_path.name} — {e}", file=sys.stderr)
        finally:
            browser.close()

    print(f"\nDone. {failures} failure(s).")
    return 0 if failures == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
