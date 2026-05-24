#!/usr/bin/env python3
"""
Inbound webhook round-trip test.

Used inside docs/internal/extended-test-plan.md section 2. Performs a real
SMTP send to one of the plugin's documented inbound addresses and polls the
WP REST API to verify a helpdesk_reply comment appears on the matching
ticket within the timeout.

Usage:

    # Happy path — send to the first WP_WEBHOOK_TIED_TO address.
    python testing/extended/webhook_roundtrip.py

    # Send to a specific inbound address (must be one of the addresses wired
    # to the plugin webhook, listed in $WP_WEBHOOK_TIED_TO).
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
    Sources ~/.claude/dev-secrets.env. Required keys (no defaults — all must be set):
        WP_TESTING_URL            Base URL of the dev WP instance
        WP_TESTING_USER           Admin username for REST auth
        WP_TESTING_PASS           Admin password (or application password)
        WP_TESTING_CLIENT_EMAIL_1 Email used as the ticket requester
        WP_WEBHOOK_TIED_TO        Comma-separated list of inbound webhook addresses;
                                  the first one is used unless --inbound-address overrides
        MAIL_SMTP_ADDRESS         SMTP host
        MAIL_SMTP_PORT            SMTP port (typically 587)
        MAIL_USERNAME             SMTP auth username (test mailbox account)
        MAIL_PASSWORD             SMTP auth password
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

try:
    import requests
except ImportError:
    print("ERROR: `requests` is required. Run: pip install requests", file=sys.stderr)
    sys.exit(2)


def env(name: str, default: Optional[str] = None) -> str:
    """Read env var or die — refuses to silently use empty strings."""
    val = os.environ.get(name, default)
    if val is None or val == "":
        print(f"ERROR: env var {name} is not set. Source ~/.claude/dev-secrets.env first.",
              file=sys.stderr)
        sys.exit(2)
    return val


def create_test_ticket(wp_url: str, auth: tuple[str, str], requester_email: str,
                       title_prefix: str) -> tuple[int, str]:
    """
    Creates a CLAUDE-TEST- ticket via the plugin's frontend submission flow.

    The REST API doesn't expose a ticket-create endpoint (intentional per matrix
    gap note), so we POST to the public form via admin-ajax / the shortcode handler.
    To keep the script self-contained, we use the WP REST `wp/v2/helpdesk_ticket`
    endpoint which is registered by WP core for any CPT (admin only).

    Returns (post_id, ticket_uid).
    """
    title = f"{title_prefix}-{uuid.uuid4().hex[:8]}"
    resp = requests.post(
        f"{wp_url}/wp-json/wp/v2/helpdesk_ticket",
        auth=auth,
        json={
            "title": title,
            "content": f"Round-trip ticket created at {time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}",
            "status": "publish",
            "meta": {
                "_ticket_email": requester_email,
                "_ticket_status": "Open",
            },
        },
        timeout=15,
    )
    if resp.status_code not in (200, 201):
        print(f"FAIL — ticket create returned {resp.status_code}: {resp.text[:400]}",
              file=sys.stderr)
        sys.exit(1)
    body = resp.json()
    post_id = int(body["id"])

    # WP REST may not set ticket-meta from a single REST POST if meta wasn't
    # registered for show_in_rest. Set the required ones via the meta endpoint
    # which Simple WP Helpdesk does expose for ticket fields.
    for key, value in (
        ("_ticket_email", requester_email),
        ("_ticket_status", "Open"),
    ):
        requests.post(
            f"{wp_url}/wp-json/wp/v2/helpdesk_ticket/{post_id}",
            auth=auth,
            json={"meta": {key: value}},
            timeout=15,
        )

    # _ticket_uid is set on submission via shortcode but may not be auto-generated
    # for REST-created tickets. Default to TKT-<padded-id> which matches the
    # plugin's fallback formula seen in TicketController.
    ticket_uid = f"TKT-{post_id:04d}"
    return post_id, ticket_uid


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


def get_ticket_comments(wp_url: str, auth: tuple[str, str], post_id: int) -> list[dict]:
    """Fetch all comments on a ticket via the plugin's TicketController."""
    resp = requests.get(
        f"{wp_url}/wp-json/swh/v1/tickets/{post_id}",
        auth=auth,
        timeout=15,
    )
    if resp.status_code != 200:
        return []
    data = resp.json()
    thread = data.get("thread")
    return thread if isinstance(thread, list) else []


def poll_for_new_comment(wp_url: str, auth: tuple[str, str], post_id: int,
                         baseline_count: int, body_marker: str,
                         timeout_s: int) -> Optional[dict]:
    """
    Poll the ticket's comment thread every 2 seconds. Return the first new
    comment whose body contains the marker, or None on timeout.
    """
    deadline = time.time() + timeout_s
    last_count = baseline_count
    while time.time() < deadline:
        comments = get_ticket_comments(wp_url, auth, post_id)
        if len(comments) > last_count:
            # New comments since baseline.
            for c in comments[last_count:]:
                if isinstance(c, dict) and body_marker in str(c.get("body", "") + c.get("content", "")):
                    return c
            last_count = len(comments)
        time.sleep(2)
    return None


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--inbound-address", default=None,
                   help="Which inbound address to send TO. Defaults to the first "
                        "entry in the comma-separated $WP_WEBHOOK_TIED_TO env var.")
    p.add_argument("--sender-override", default=None,
                   help="Send FROM this address instead of the ticket's _ticket_email. "
                        "Used to test sender_mismatch rejection.")
    p.add_argument("--subject-override", default=None,
                   help="Use this subject instead of the auto-generated `Re: [TKT-NNNN] roundtrip`. "
                        "Used to test no_ticket_id rejection.")
    p.add_argument("--expect-rejection", default=None,
                   choices=["sender_mismatch", "no_ticket_id"],
                   help="If set, the script expects NO new comment to appear and PASSes on timeout.")
    p.add_argument("--timeout", type=int, default=60,
                   help="Seconds to wait for the comment to appear (or confirm it doesn't).")
    p.add_argument("--title-prefix", default="CLAUDE-TEST-WEBHOOK",
                   help="Ticket title prefix so cleanup.py can find this later.")
    args = p.parse_args()

    wp_url = env("WP_TESTING_URL").rstrip("/")
    wp_user = env("WP_TESTING_USER")
    wp_pass = env("WP_TESTING_PASS")
    client_email = env("WP_TESTING_CLIENT_EMAIL_1")
    smtp_host = env("MAIL_SMTP_ADDRESS")
    smtp_port_raw = env("MAIL_SMTP_PORT")
    try:
        smtp_port = int(smtp_port_raw)
    except ValueError:
        print(f"ERROR: MAIL_SMTP_PORT is not numeric: {smtp_port_raw!r}", file=sys.stderr)
        return 2
    smtp_user = env("MAIL_USERNAME")
    smtp_pass = env("MAIL_PASSWORD")

    # Resolve the inbound address from --inbound-address, or fall back to the
    # first entry of WP_WEBHOOK_TIED_TO (a comma-separated list).
    if args.inbound_address:
        inbound_address = args.inbound_address
    else:
        tied_to = env("WP_WEBHOOK_TIED_TO")
        candidates = [a.strip() for a in tied_to.split(",") if a.strip()]
        if not candidates:
            print("ERROR: WP_WEBHOOK_TIED_TO is empty.", file=sys.stderr)
            return 2
        inbound_address = candidates[0]

    auth = (wp_user, wp_pass)

    print(f"[1/4] Creating test ticket ({args.title_prefix}) as requester {client_email}...")
    post_id, ticket_uid = create_test_ticket(wp_url, auth, client_email, args.title_prefix)
    print(f"      post_id={post_id} ticket_uid={ticket_uid}")

    baseline = get_ticket_comments(wp_url, auth, post_id)
    print(f"[2/4] Baseline comment count on ticket: {len(baseline)}")

    marker = f"roundtrip-{uuid.uuid4().hex[:8]}"
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

    new_comment = poll_for_new_comment(wp_url, auth, post_id,
                                       baseline_count=len(baseline),
                                       body_marker=marker,
                                       timeout_s=args.timeout)

    if args.expect_rejection:
        # Negative test: we EXPECT no comment to appear.
        if new_comment is None:
            print(f"PASS — webhook correctly rejected {args.expect_rejection} "
                  f"(no comment appeared within {args.timeout}s).")
            return 0
        print(f"FAIL — expected webhook to reject ({args.expect_rejection}) but a comment "
              f"was inserted: id={new_comment.get('id')} body={str(new_comment)[:200]}",
              file=sys.stderr)
        return 1

    # Positive test: we EXPECT a comment to appear.
    if new_comment is None:
        print(f"FAIL — no helpdesk_reply with marker '{marker}' appeared on ticket "
              f"{post_id} within {args.timeout}s.",
              file=sys.stderr)
        print(f"       Check: (a) Apache forwards Authorization header (per "
              f"api-contract.md note); (b) inbound_secret is configured; (c) the "
              f"MTA between the mail host and WP is delivering.", file=sys.stderr)
        return 1

    print(f"PASS — webhook accepted message. New comment:")
    print(f"       id  : {new_comment.get('id')}")
    print(f"       body: {str(new_comment.get('body') or new_comment.get('content'))[:200]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
