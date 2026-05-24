#!/usr/bin/env python3
"""
Pre-flight and post-run cleanup for the RC extended-test runbook.

Deletes every ticket whose title starts with `CLAUDE-TEST-` and expunges
the test mailbox of messages whose subject contains the same prefix.

Per the runbook, this runs:
  - Before each section starts (pre-flight).
  - After section 9 (post-run, courtesy cleanup).

Usage:

    python testing/extended/cleanup.py            # interactive (prompts)
    python testing/extended/cleanup.py --confirm  # non-interactive
    python testing/extended/cleanup.py --dry-run  # list, don't delete

Exit codes:
    0  Success (something deleted, or nothing to delete)
    1  Partial failure
    2  Configuration error

Environment:
    SSH_TARGET, WP_CONTAINER, WP_PATH    (wp-cli over SSH; see _wpcli.py)
    MAIL_IMAP_ADDRESS, MAIL_IMAP_PORT
    MAIL_USERNAME, MAIL_PASSWORD

Safety:
    - Only acts on the documented prefix. Tickets without `CLAUDE-TEST-`
      are never touched (strict startswith match on title).
    - wp post delete --force (permanent — does NOT trash).
    - IMAP messages are marked \\Deleted and expunged.
"""

from __future__ import annotations

import argparse
import imaplib
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _wpcli import (  # noqa: E402
    env,
    list_test_tickets,
    delete_test_ticket,
)

PREFIX = "CLAUDE-TEST-"


def cleanup_tickets(args: argparse.Namespace) -> int:
    print(f"[tickets] Listing {PREFIX}* tickets via wp-cli...")
    try:
        candidates = list_test_tickets(PREFIX)
    except RuntimeError as e:
        print(f"ERROR: wp-cli listing failed: {e}", file=sys.stderr)
        return 2

    if not candidates:
        print("[tickets] None found. Nothing to delete.")
        return 0

    print(f"[tickets] Found {len(candidates)} test ticket(s):")
    for t in candidates:
        print(f"          id={t.get('ID')} status={t.get('post_status')} "
              f"title={t.get('post_title')!r}")

    if args.dry_run:
        print("[tickets] --dry-run: skipping deletion.")
        return 0

    if not args.confirm:
        try:
            response = input(f"[tickets] Delete all {len(candidates)} ticket(s) permanently? [y/N] ")
        except EOFError:
            response = ""
        if response.strip().lower() not in ("y", "yes"):
            print("[tickets] Aborted.")
            return 0

    failures = 0
    for t in candidates:
        post_id = int(t.get("ID", 0))
        if post_id <= 0:
            continue
        ok = delete_test_ticket(post_id)
        if ok:
            print(f"[tickets] deleted id={post_id}")
        else:
            failures += 1
            print(f"[tickets] FAIL to delete id={post_id}", file=sys.stderr)
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
        # IMAP select() can fail without raising — check status explicitly so
        # downstream search/store don't operate on an unselected mailbox.
        sel_typ, _ = conn.select("INBOX")
        if sel_typ != "OK":
            print(f"ERROR: IMAP SELECT INBOX returned non-OK: {sel_typ!r}", file=sys.stderr)
            return 2
    except Exception as e:  # noqa: BLE001
        print(f"ERROR: IMAP connect/login failed: {e}", file=sys.stderr)
        return 2

    try:
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

        # Check each store() and expunge() — IMAP returns non-OK without raising
        # if the server refuses (rare but possible: read-only mailbox, server
        # error, etc.). Reporting success when nothing actually got deleted
        # would leave the next runbook run thinking state was clean.
        store_failures = []
        for mid in msg_ids:
            store_typ, _ = conn.store(mid, "+FLAGS", "\\Deleted")
            if store_typ != "OK":
                store_failures.append(mid.decode("ascii", "replace"))
        if store_failures:
            print(f"[mailbox] FAIL — store() refused on {len(store_failures)} message(s): "
                  f"{', '.join(store_failures[:10])}", file=sys.stderr)
            return 1
        expunge_typ, _ = conn.expunge()
        if expunge_typ != "OK":
            print(f"[mailbox] FAIL — expunge() returned non-OK: {expunge_typ!r}", file=sys.stderr)
            return 1
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
                   help="Skip interactive confirmation. Required for non-TTY use.")
    p.add_argument("--dry-run", action="store_true",
                   help="List candidates without deleting.")
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
