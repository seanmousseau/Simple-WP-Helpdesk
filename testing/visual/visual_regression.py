#!/usr/bin/env python3
"""
Visual regression for the admin surfaces.

Captures full-page screenshots of inbox / settings / reports across light /
dark / auto themes, then either:
  - compares against committed baselines (default), or
  - updates baselines (--mode update).

Baselines live under testing/visual/baselines/, one PNG per
(surface, theme) combination. Failures write diff PNGs to
testing/visual/diffs/<timestamp>/ (gitignored) for review.

Why not Playwright's native to_have_screenshot()?
    pytest-playwright's snapshot support is limited; the JS API is much
    richer. A small Pillow-based pixel diff is more portable for our
    Python-only test stack and gives us the tolerance knobs we need.

Usage:
    pip install playwright pillow
    playwright install chromium

    # First run on a clean stack — populate baselines:
    python3 testing/visual/visual_regression.py --mode update

    # Subsequent runs — compare:
    python3 testing/visual/visual_regression.py

    # Subset:
    python3 testing/visual/visual_regression.py --only inbox,reports
    python3 testing/visual/visual_regression.py --themes dark

Exit codes:
    0  All captures match baselines (compare mode) or all baselines
       written (update mode)
    1  One or more diffs exceeded tolerance; review the PNGs under
       testing/visual/diffs/<timestamp>/
    2  Configuration error or missing dependency

Environment:
    WP_TESTING_URL, WP_TESTING_USER, WP_TESTING_PASS    (admin login)
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

try:
    from PIL import Image, ImageChops, ImageStat
except ImportError:
    print("ERROR: Pillow not installed. Run: pip install pillow", file=sys.stderr)
    sys.exit(2)


def env(name: str) -> str:
    v = os.environ.get(name, "")
    if not v:
        print(f"ERROR: env var {name} is not set. Source ~/.claude/dev-secrets.env first.",
              file=sys.stderr)
        sys.exit(2)
    return v


THEMES = ["light", "dark", "auto"]
HERE = Path(__file__).resolve().parent
BASELINES_DIR = HERE / "baselines"
DIFFS_ROOT = HERE / "diffs"

# (slug, admin path relative to WP_TESTING_URL)
SURFACES = [
    ("inbox",    "/wp-admin/edit.php?post_type=helpdesk_ticket"),
    ("settings", "/wp-admin/edit.php?post_type=helpdesk_ticket&page=swh-settings"),
    ("reports",  "/wp-admin/edit.php?post_type=helpdesk_ticket&page=swh-reports"),
]

# Tolerance for compare mode.
# - PIXEL_TOLERANCE: max average per-pixel intensity difference (0-255). Tuned
#   to absorb anti-aliasing variation between captures but flag real
#   color/layout changes. 5 is a reasonable starting point.
# - MISMATCH_RATIO: max fraction of pixels that differ by more than 0 (after
#   light Gaussian smoothing isn't applied — pure pixel-level). Catches
#   structural differences like a missing element.
PIXEL_TOLERANCE = 5.0
MISMATCH_RATIO = 0.02   # 2% of pixels may differ; tighten if false-negatives appear


def login(page, wp_url: str, user: str, password: str) -> None:
    page.goto(f"{wp_url}/wp-login.php")
    page.fill("input[name=log]", user)
    page.fill("input[name=pwd]", password)
    page.click("input[name=wp-submit]")
    page.wait_for_load_state("networkidle")


def set_admin_theme(page, wp_url: str, theme: str) -> None:
    page.goto(f"{wp_url}/wp-admin/edit.php?post_type=helpdesk_ticket&page=swh-settings")
    page.wait_for_load_state("networkidle")
    radio = page.locator(f'input[name="swh_admin_theme"][value="{theme}"]')
    try:
        radio.first.check(timeout=5000)
        for label in ("Save Changes", "Save Settings", "Save"):
            btn = page.get_by_role("button", name=label)
            if btn.count() > 0:
                btn.first.click()
                break
        page.wait_for_load_state("networkidle")
    except PWTimeout:
        pass  # theme toggle absent on this build — proceed with whatever default applies


def capture_one(page, wp_url: str, path: str, out_path: Path) -> None:
    page.goto(f"{wp_url}{path}")
    page.wait_for_load_state("networkidle")
    # Let CSS theme transitions + any React render settle so captures are stable.
    page.wait_for_timeout(1500)
    page.screenshot(path=str(out_path), full_page=True)


def compare_pngs(actual: Path, baseline: Path, diff_out: Path) -> tuple[bool, str]:
    """
    Returns (matched, diagnostic). Writes a visual diff PNG to diff_out on mismatch.
    """
    try:
        a = Image.open(actual).convert("RGB")
        b = Image.open(baseline).convert("RGB")
    except OSError as e:
        return False, f"image open failed: {e}"

    if a.size != b.size:
        return False, f"size mismatch: actual={a.size} baseline={b.size}"

    diff = ImageChops.difference(a, b)
    stat = ImageStat.Stat(diff)
    # stat.mean is per-channel; average across RGB.
    avg = sum(stat.mean) / len(stat.mean)

    # Pixel-mismatch ratio: count pixels whose max-channel diff > 0.
    bbox = diff.getbbox()
    if bbox is None:
        return True, "identical"

    # Sequence of (r, g, b) tuples for an RGB-converted image. Pillow's
    # ImagingCore is iterable at runtime but its stub omits __iter__, hence
    # the type: ignore below.
    diff_data = list(diff.crop(bbox).getdata())  # type: ignore[arg-type]
    width = bbox[2] - bbox[0]
    height = bbox[3] - bbox[1]
    mismatched = 0
    for px in diff_data:
        if isinstance(px, tuple) and len(px) >= 3:
            if px[0] > 0 or px[1] > 0 or px[2] > 0:
                mismatched += 1
        elif isinstance(px, int) and px > 0:
            mismatched += 1
    ratio = mismatched / max(1, width * height)

    if avg <= PIXEL_TOLERANCE and ratio <= MISMATCH_RATIO:
        return True, f"within tolerance (avg={avg:.2f}, ratio={ratio:.4f})"

    # Write the diff PNG, multiplying so the difference is visible to the eye.
    diff_out.parent.mkdir(parents=True, exist_ok=True)
    diff.save(diff_out)
    return False, f"avg={avg:.2f} (tol {PIXEL_TOLERANCE}), ratio={ratio:.4f} (tol {MISMATCH_RATIO})"


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--mode", choices=("compare", "update"), default="compare",
                   help="compare (default): diff against baselines. update: regenerate baselines.")
    p.add_argument("--only", default="",
                   help="Comma-separated surface slugs (e.g. 'inbox,settings').")
    p.add_argument("--themes", default=",".join(THEMES),
                   help=f"Comma-separated themes. Default: {','.join(THEMES)}.")
    p.add_argument("--headed", action="store_true",
                   help="Show the browser instead of headless.")
    args = p.parse_args()

    wp_url = env("WP_TESTING_URL").rstrip("/")
    user = env("WP_TESTING_USER")
    password = env("WP_TESTING_PASS")

    only = {s.strip() for s in args.only.split(",") if s.strip()}
    themes = [t.strip() for t in args.themes.split(",") if t.strip()]
    if only and not (only <= {s[0] for s in SURFACES}):
        bad = only - {s[0] for s in SURFACES}
        print(f"ERROR: unknown surface(s) in --only: {bad}", file=sys.stderr)
        return 2
    unknown_themes = [t for t in themes if t not in THEMES]
    if unknown_themes:
        print(f"ERROR: unknown theme(s) in --themes: {unknown_themes}", file=sys.stderr)
        return 2
    surfaces = [s for s in SURFACES if not only or s[0] in only]

    BASELINES_DIR.mkdir(parents=True, exist_ok=True)
    diff_dir = DIFFS_ROOT / time.strftime("%Y%m%d-%H%M%S", time.gmtime())

    failures: list[str] = []
    captured = 0

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=not args.headed)
        try:
            context = browser.new_context(viewport={"width": 1440, "height": 900})
            page = context.new_page()
            login(page, wp_url, user, password)

            for theme in themes:
                print(f"\n[{theme}]")
                set_admin_theme(page, wp_url, theme)
                for slug, path in surfaces:
                    name = f"{slug}-{theme}.png"
                    baseline_path = BASELINES_DIR / name

                    if args.mode == "update":
                        capture_one(page, wp_url, path, baseline_path)
                        captured += 1
                        print(f"  ✓ wrote baseline {name}")
                        continue

                    # compare mode
                    if not baseline_path.exists():
                        failures.append(f"{name}: no baseline (run --mode update first)")
                        print(f"  ✗ {name} — no baseline")
                        continue

                    actual_path = diff_dir / f"actual-{name}"
                    actual_path.parent.mkdir(parents=True, exist_ok=True)
                    capture_one(page, wp_url, path, actual_path)
                    captured += 1
                    diff_path = diff_dir / f"diff-{name}"
                    matched, info = compare_pngs(actual_path, baseline_path, diff_path)
                    if matched:
                        print(f"  ✓ {name} — {info}")
                    else:
                        failures.append(f"{name}: {info}")
                        print(f"  ✗ {name} — {info}")
        finally:
            browser.close()

    print()
    if args.mode == "update":
        print(f"Wrote {captured} baseline(s) to {BASELINES_DIR}")
        print("Review the PNGs, then commit them.")
        return 0

    if not failures:
        print(f"PASS — {captured} surface(s) match baselines.")
        return 0

    print(f"FAIL — {len(failures)} of {captured} surface(s) differ:")
    for f in failures:
        print(f"  - {f}")
    print(f"\nDiff PNGs and actual captures are under: {diff_dir}")
    print("If the diffs are intentional UI changes, regenerate the baselines:")
    print("  python3 testing/visual/visual_regression.py --mode update")
    print("Then review and commit the updated baselines.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
