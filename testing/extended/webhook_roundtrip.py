#!/usr/bin/env python3
"""
Inbound webhook round-trip test.

Used inside docs/internal/extended-test-plan.md section 2. Performs a real
SMTP send to one of the plugin's documented inbound addresses and polls
the ticket's comment thread via WP-CLI to verify a helpdesk_reply comment
appears within the timeout.

Usage:

    # Happy path — send to the first $WP_WEBHOOK_TIED_TO address.
    python testing/extended/webhook_roundtrip.py

    # Send to a specific inbound address (must be one of the addresses
    # wired to the plugin webhook).
    python testing/extended/webhook_roundtrip.py \\
        --inbound-address "$WP_WEBHOOK_SECOND_ADDRESS"

    # Negative case — sender doesn't match the ticket's _ticket_email.
    python testing/extended/webhook_roundtrip.py \\
        --sender-override "nobody@example.invalid" \\
        --expect-rejection sender_mismatch

    # Negative case — subject lacks the [TKT-XXXX] tag.
    python testing/extended/webhook_roundtrip.py \\
        --subject-override "Plain subject, no tag" \\
        --expect-rejection no_ticket_id

Exit codes:
    0  PASS
    1  FAIL (mismatch, timeout, or unexpected error)
    2  Configuration error (missing env var)

Environment:
    Sources ~/.claude/dev-secrets.env. Required keys (no defaults):
        WP_TESTING_CLIENT_EMAIL_1  Email used as the ticket requester
        WP_WEBHOOK_TIED_TO         Comma-separated list of inbound webhook
                                   addresses; the first is used by default
        MAIL_SMTP_ADDRESS, MAIL_SMTP_PORT
        MAIL_USERNAME, MAIL_PASSWORD
        SSH_TARGET, WP_CONTAINER, WP_PATH   (for wp-cli via SSH; see _wpcli.py)
"""

from __future__ import annotations

import argparse
import os
import smtplib
import ssl
import sys
import time
import uuid
from email.message import EmailMessage
from typing import Optional

# Import the local helper. Ensure this script can be run from any cwd.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _wpcli import (  # noqa: E402
    env,
    create_test_ticket,
    list_ticket_comments,
)


def smtp_send(host: str, port: int, username: str, password: str,
              from_addr: str, to_addr: str, subject: str, body: str) -> None:
    """Send a message via STARTTLS SMTP. Raises on any failure."""
    msg = EmailMessage()
    msg["From"] = from_addr
    msg["To"] = to_addr
    msg["Subject"] = subject
    msg.set_content(body)

    context = ssl.create_default_context()
    with smtplib.SMTP(host, port, timeout=15) as s:
        s.ehlo()
        s.starttls(context=context)
        s.login(username, password)
        s.send_message(msg)


def poll_for_new_comment(post_id: int, baseline_count: int, body_marker: str,
                         timeout_s: int) -> Optional[dict]:
    """
    Poll the ticket's comment thread via wp-cli every 3 seconds. Return the
    first new comment whose content contains the marker, or None on timeout.
    """
    deadline = time.time() + timeout_s
    last_count = baseline_count
    while time.time() < deadline:
        comments = list_ticket_comments(post_id)
        if len(comments) > last_count:
            for c in comments[last_count:]:
                content = str(c.get("comment_content", ""))
                if body_marker in content:
                    return c
            last_count = len(comments)
        time.sleep(3)
    return None


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--inbound-address", default=None,
                   help="Which inbound address to send TO. Defaults to the first entry "
                        "in the comma-separated $WP_WEBHOOK_TIED_TO env var.")
    p.add_argument("--sender-override", default=None,
                   help="Send FROM this address instead of the ticket's _ticket_email. "
                        "Used to test sender_mismatch rejection.")
    p.add_argument("--subject-override", default=None,
                   help="Use this subject instead of the auto-generated `Re: [TKT-NNNN] roundtrip`. "
                        "Used to test no_ticket_id rejection.")
    p.add_argument("--expect-rejection", default=None,
                   choices=["sender_mismatch", "no_ticket_id", "ticket_not_found"],
                   help="If set, expects NO new comment to appear and PASSes on timeout. "
                        "For sender_mismatch, --sender-override defaults to "
                        "$WP_TESTING_CLIENT_EMAIL_2 (a different authorized alias). "
                        "For ticket_not_found, the subject is auto-set to reference a "
                        "non-existent ticket UID.")
    p.add_argument("--timeout", type=int, default=60,
                   help="Seconds to wait for the comment to appear (or confirm it doesn't).")
    p.add_argument("--title-prefix", default=None,
                   help="Ticket title prefix so cleanup.py can find this later. "
                        "Defaults to CLAUDE-TEST-WEBHOOK-<YYYYMMDD-HHMMSS>.")
    args = p.parse_args()

    client_email = env("WP_TESTING_CLIENT_EMAIL_1")
    # Smart default for sender_mismatch tests: use a different authorized
    # alias of the same SMTP account. Sending from a totally external address
    # gets rejected by the relay (5.7.1) before WP ever sees the message.
    if args.expect_rejection == "sender_mismatch" and not args.sender_override:
        args.sender_override = os.environ.get("WP_TESTING_CLIENT_EMAIL_2", "")
        if not args.sender_override:
            print("ERROR: --expect-rejection sender_mismatch needs either --sender-override "
                  "OR $WP_TESTING_CLIENT_EMAIL_2 set.", file=sys.stderr)
            return 2

    # Auto-tag the title with a UTC timestamp so concurrent operators don't
    # collide and stale runs are visually distinguishable.
    title_prefix = args.title_prefix or f"CLAUDE-TEST-WEBHOOK-{time.strftime('%Y%m%d-%H%M%S', time.gmtime())}"

    smtp_host = env("MAIL_SMTP_ADDRESS")
    smtp_port_raw = env("MAIL_SMTP_PORT")
    try:
        smtp_port = int(smtp_port_raw)
    except ValueError:
        print(f"ERROR: MAIL_SMTP_PORT is not numeric: {smtp_port_raw!r}", file=sys.stderr)
        return 2
    smtp_user = env("MAIL_USERNAME")
    smtp_pass = env("MAIL_PASSWORD")

    # Resolve inbound address from --inbound-address, else first entry of
    # WP_WEBHOOK_TIED_TO (comma-separated).
    if args.inbound_address:
        inbound_address = args.inbound_address
    else:
        tied_to = env("WP_WEBHOOK_TIED_TO")
        candidates = [a.strip() for a in tied_to.split(",") if a.strip()]
        if not candidates:
            print("ERROR: WP_WEBHOOK_TIED_TO is empty.", file=sys.stderr)
            return 2
        inbound_address = candidates[0]

    title = f"{title_prefix}-{uuid.uuid4().hex[:8]}"
    print(f"[1/4] Creating test ticket {title!r} via wp-cli (requester={client_email})...")
    try:
        post_id = create_test_ticket(title, client_email)
    except RuntimeError as e:
        print(f"FAIL — ticket create error: {e}", file=sys.stderr)
        return 1
    ticket_uid = f"TKT-{post_id:04d}"
    print(f"      post_id={post_id} ticket_uid={ticket_uid}")

    baseline = list_ticket_comments(post_id)
    print(f"[2/4] Baseline comment count on ticket: {len(baseline)}")

    marker = f"roundtrip-{uuid.uuid4().hex[:8]}"
    # ticket_not_found auto-subject — reference a UID that won't exist.
    if args.expect_rejection == "ticket_not_found" and not args.subject_override:
        args.subject_override = f"Re: [TKT-999999] roundtrip {marker}"

    subject = args.subject_override or f"Re: [{ticket_uid}] roundtrip {marker}"
    from_addr = args.sender_override or client_email
    body = f"Round-trip body. Marker: {marker}\n"

    print(f"[3/4] SMTP-sending via {smtp_host}:{smtp_port}")
    print(f"      from   : {from_addr}")
    print(f"      to     : {inbound_address}")
    print(f"      subject: {subject}")
    try:
        smtp_send(smtp_host, smtp_port, smtp_user, smtp_pass,
                  from_addr, inbound_address, subject, body)
    except Exception as e:  # noqa: BLE001 — script-level catch
        print(f"FAIL — SMTP send error: {e}", file=sys.stderr)
        return 1
    print("      sent OK")

    print(f"[4/4] Polling ticket {post_id} for a new helpdesk_reply containing marker '{marker}'")
    print(f"      timeout: {args.timeout}s")

    new_comment = poll_for_new_comment(post_id,
                                       baseline_count=len(baseline),
                                       body_marker=marker,
                                       timeout_s=args.timeout)

    if args.expect_rejection:
        # Negative test: we EXPECT no comment.
        if new_comment is None:
            print(f"PASS — webhook correctly rejected {args.expect_rejection} "
                  f"(no comment appeared within {args.timeout}s).")
            return 0
        print(f"FAIL — expected webhook to reject ({args.expect_rejection}) but a comment "
              f"was inserted: id={new_comment.get('comment_ID')} "
              f"content={str(new_comment.get('comment_content',''))[:200]}",
              file=sys.stderr)
        return 1

    # Positive test: we EXPECT a comment.
    if new_comment is None:
        print(f"FAIL — no helpdesk_reply with marker '{marker}' appeared on ticket "
              f"{post_id} within {args.timeout}s.",
              file=sys.stderr)
        print(f"       Check: (a) Apache forwards Authorization header (per "
              f"api-contract.md note); (b) inbound_secret is configured; (c) the "
              f"MTA between the mail host and WP is delivering.", file=sys.stderr)
        return 1

    print(f"PASS — webhook accepted message. New comment:")
    print(f"       id     : {new_comment.get('comment_ID')}")
    print(f"       type   : {new_comment.get('comment_type')}")
    print(f"       content: {str(new_comment.get('comment_content',''))[:200]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
