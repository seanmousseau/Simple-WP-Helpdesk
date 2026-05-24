#!/usr/bin/env python3
"""
Pre-flight and post-run cleanup for the RC extended-test runbook.

Deletes every ticket whose title starts with `CLAUDE-TEST-` from the dev
WordPress instance, and expunges the test mailbox of messages whose
subject contains the same prefix.

Per the runbook (docs/internal/extended-test-plan.md), this runs:
  - Before each section starts (pre-flight, to avoid stale state).
  - After section 9 (post-run, courtesy cleanup so the operator doesn't
    leave clutter in the dev environment).

Usage:

    # Default: prompt for confirmation, show what would be deleted.
    python testing/extended/cleanup.py

    # Non-interactive (CI-safe) — deletes without prompting.
    python testing/extended/cleanup.py --confirm

    # Dry-run — list candidates without deleting.
    python testing/extended/cleanup.py --dry-run

Exit codes:
    0  Success (something deleted, or nothing to delete).
    1  Partial failure (some deletions failed; details on stderr).
    2  Configuration error.

Safety:
    - Only acts on the documented prefix. Tickets without `CLAUDE-TEST-`
      are never touched.
    - WP REST DELETE with force=true (permanent — does NOT trash).
    - IMAP messages are marked \\Deleted and expunged.
"""

from __future__ import annotations

import argparse
import imaplib
import os
import sys
from typing import Optional

try:
    import requests
except ImportError:
    print("ERROR: `requests` is required. Run: pip install requests", file=sys.stderr)
    sys.exit(2)

PREFIX = "CLAUDE-TEST-"


def env(name: str, default: Optional[str] = None) -> str:
    val = os.environ.get(name, default)
    if val is None or val == "":
        print(f"ERROR: env var {name} is not set. Source ~/.claude/dev-secrets.env first.",
              file=sys.stderr)
        sys.exit(2)
    return val


def find_test_tickets(wp_url: str, auth: tuple[str, str]) -> list[dict]:
    """List every helpdesk_ticket whose title starts with CLAUDE-TEST-."""
    out: list[dict] = []
    page = 1
    while True:
        resp = requests.get(
            f"{wp_url}/wp-json/wp/v2/helpdesk_ticket",
            auth=auth,
            params={
                "per_page": 100,
                "page": page,
                "search": PREFIX,
                "status": "any",
            },
            timeout=20,
        )
        if resp.status_code == 400 and page > 1:
            # WP returns 400 when page exceeds total pages.
            break
        if resp.status_code != 200:
            print(f"ERROR: ticket list returned HTTP {resp.status_code}: {resp.text[:300]}",
                  file=sys.stderr)
            sys.exit(2)
        batch = resp.json()
        if not batch:
            break
        # The search param matches anywhere in title/content. Filter strictly to
        # tickets whose title actually STARTS with the prefix — protects against
        # collateral damage if a legitimate ticket happens to mention the prefix.
        kept = [t for t in batch if isinstance(t, dict)
                and isinstance(t.get("title"), dict)
                and str(t["title"].get("rendered", "")).startswith(PREFIX)]
        out.extend(kept)
        if len(batch) < 100:
            break
        page += 1
    return out


def delete_ticket(wp_url: str, auth: tuple[str, str], post_id: int) -> bool:
    resp = requests.delete(
        f"{wp_url}/wp-json/wp/v2/helpdesk_ticket/{post_id}",
        auth=auth,
        params={"force": "true"},
        timeout=15,
    )
    return resp.status_code in (200, 410)


def cleanup_tickets(args: argparse.Namespace) -> int:
    wp_url = env("WP_TESTING_URL").rstrip("/")
    auth = (env("WP_TESTING_USER"), env("WP_TESTING_PASS"))

    print(f"[tickets] Listing CLAUDE-TEST- tickets at {wp_url}...")
    candidates = find_test_tickets(wp_url, auth)
    if not candidates:
        print("[tickets] None found. Nothing to delete.")
        return 0

    print(f"[tickets] Found {len(candidates)} test ticket(s):")
    for t in candidates:
        title = (t.get("title") or {}).get("rendered", "<no title>")
        print(f"          id={t.get('id')} title={title!r}")

    if args.dry_run:
        print("[tickets] --dry-run: skipping deletion.")
        return 0

    if not args.confirm:
        try:
            response = input(f"[tickets] Delete all {len(candidates)} ticket(s) permanently? [y/N] ")
        except EOFError:
            response = ""
        if response.strip().lower() not in ("y", "yes"):
            print("[tickets] Aborted. No deletions performed.")
            return 0

    failures = 0
    for t in candidates:
        post_id = int(t.get("id", 0))
        if post_id <= 0:
            continue
        ok = delete_ticket(wp_url, auth, post_id)
        if not ok:
            failures += 1
            print(f"[tickets] FAIL to delete id={post_id}", file=sys.stderr)
        else:
            print(f"[tickets] deleted id={post_id}")
    print(f"[tickets] {len(candidates) - failures} deleted, {failures} failed.")
    return 0 if failures == 0 else 1


def cleanup_mailbox(args: argparse.Namespace) -> int:
    host = env("MAIL_IMAP_ADDRESS")
    port_raw = env("MAIL_IMAP_PORT")
    try:
        port = int(port_raw)
    except ValueError:
        print(f"ERROR: MAIL_IMAP_PORT is not numeric: {port_raw!r}", file=sys.stderr)
        return 2
    user = env("MAIL_USERNAME")
    password = env("MAIL_PASSWORD")

    print(f"[mailbox] Connecting IMAP {host}:{port} as {user}")
    try:
        conn = imaplib.IMAP4_SSL(host, port, timeout=15)
        conn.login(user, password)
        conn.select("INBOX")
    except Exception as e:  # noqa: BLE001
        print(f"ERROR: IMAP connect/login failed: {e}", file=sys.stderr)
        return 2

    try:
        # SUBJECT search matches the substring anywhere in the subject header.
        typ, data = conn.search(None, f'(SUBJECT "{PREFIX}")')
        if typ != "OK" or not data or not data[0]:
            print("[mailbox] No test messages found.")
            return 0
        msg_ids = data[0].split()
        print(f"[mailbox] Found {len(msg_ids)} message(s) with subject containing '{PREFIX}'.")

        if args.dry_run:
            print("[mailbox] --dry-run: skipping deletion.")
            return 0

        if not args.confirm:
            try:
                response = input(f"[mailbox] Delete all {len(msg_ids)} message(s)? [y/N] ")
            except EOFError:
                response = ""
            if response.strip().lower() not in ("y", "yes"):
                print("[mailbox] Aborted.")
                return 0

        for mid in msg_ids:
            conn.store(mid, "+FLAGS", "\\Deleted")
        conn.expunge()
        print(f"[mailbox] Deleted and expunged {len(msg_ids)} message(s).")
        return 0
    finally:
        try:
            conn.logout()
        except Exception:  # noqa: BLE001
            pass


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--confirm", action="store_true",
                   help="Skip interactive confirmation. Required for non-TTY use (CI).")
    p.add_argument("--dry-run", action="store_true",
                   help="List candidates without deleting anything.")
    p.add_argument("--skip-tickets", action="store_true",
                   help="Skip WP ticket cleanup (mailbox only).")
    p.add_argument("--skip-mailbox", action="store_true",
                   help="Skip mailbox cleanup (tickets only).")
    args = p.parse_args()

    if args.confirm and args.dry_run:
        print("ERROR: --confirm and --dry-run are mutually exclusive.", file=sys.stderr)
        return 2

    rc = 0
    if not args.skip_tickets:
        rc = max(rc, cleanup_tickets(args))
    if not args.skip_mailbox:
        rc = max(rc, cleanup_mailbox(args))
    return rc


if __name__ == "__main__":
    sys.exit(main())
