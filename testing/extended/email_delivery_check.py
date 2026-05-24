#!/usr/bin/env python3
"""
IMAP-based email delivery verifier.

Used in docs/internal/extended-test-plan.md section 3 to confirm that an
outbound email from the WordPress test instance actually arrived in the
test mailbox within a timeout.

The mail server hosts multiple aliases on a single IMAP account. This
script polls the master mailbox (`$MAIL_USERNAME`) which receives mail
for every configured alias.

Usage:

    # Basic — wait up to 60s for any message to the configured client address
    # whose subject contains 'TKT-' and body contains the supplied marker.
    python testing/extended/email_delivery_check.py \\
        --to "$WP_TESTING_CLIENT_EMAIL_1" \\
        --subject-contains "TKT-" \\
        --body-contains "OUTBOUND-DELIVERY-CHECK-1700000000" \\
        --timeout 60

    # Match by recipient alias only (no body marker), useful for assignment emails.
    python testing/extended/email_delivery_check.py \\
        --to "$WP_TESTING_TECH_EMAIL_1" \\
        --subject-contains "assigned"

Exit codes:
    0  PASS (matching message arrived within timeout)
    1  FAIL (timeout — no matching message)
    2  Configuration error (missing env var, IMAP connect failure)

Environment:
    Sources ~/.claude/dev-secrets.env. Required keys (no defaults):
        MAIL_IMAP_ADDRESS    IMAP host
        MAIL_IMAP_PORT       IMAP port (typically 993)
        MAIL_USERNAME        IMAP account username (the master mailbox)
        MAIL_PASSWORD        IMAP account password

The script does NOT delete messages. Run cleanup.py at the end of the
runbook to expunge the test mailbox.
"""

from __future__ import annotations

import argparse
import email
import email.utils
import imaplib
import os
import sys
import time
from email.message import Message
from typing import Optional


def env(name: str, default: Optional[str] = None) -> str:
    val = os.environ.get(name, default)
    if val is None or val == "":
        print(f"ERROR: env var {name} is not set. Source ~/.claude/dev-secrets.env first.",
              file=sys.stderr)
        sys.exit(2)
    return val


def connect_imap(host: str, port: int, user: str, password: str) -> imaplib.IMAP4_SSL:
    try:
        conn = imaplib.IMAP4_SSL(host, port, timeout=15)
        conn.login(user, password)
        conn.select("INBOX")
        return conn
    except Exception as e:  # noqa: BLE001
        print(f"ERROR: IMAP connection failed: {e}", file=sys.stderr)
        sys.exit(2)


def message_text(msg: Message) -> str:
    """Best-effort extract the message body as text. Handles multipart."""
    if msg.is_multipart():
        for part in msg.walk():
            if part.get_content_type() == "text/plain":
                try:
                    payload = part.get_payload(decode=True)
                    if isinstance(payload, bytes):
                        return payload.decode(part.get_content_charset() or "utf-8",
                                              errors="replace")
                except Exception:  # noqa: BLE001
                    continue
        # Fallback: any part
        for part in msg.walk():
            try:
                payload = part.get_payload(decode=True)
                if isinstance(payload, bytes):
                    return payload.decode("utf-8", errors="replace")
            except Exception:  # noqa: BLE001
                continue
        return ""
    payload = msg.get_payload(decode=True)
    if isinstance(payload, bytes):
        charset = msg.get_content_charset() or "utf-8"
        return payload.decode(charset, errors="replace")
    return str(payload or "")


def _message_timestamp(msg: Message) -> Optional[float]:
    """
    Try to extract a unix timestamp from the Date header. Returns None if
    the header is missing or unparseable. Callers should treat None as
    "unknown" and decide whether to include or skip.
    """
    raw = msg.get("Date") or msg.get("Received") or ""
    if not raw:
        return None
    # parsedate_to_datetime tolerates wide format variation. If the header
    # has multiple `Received:` hops concatenated, take the most recent one
    # (first when iterating get_all, but we already grabbed a single value).
    try:
        dt = email.utils.parsedate_to_datetime(raw)
    except (TypeError, ValueError):
        return None
    if dt is None:
        return None
    try:
        return dt.timestamp()
    except (OverflowError, OSError, ValueError):
        return None


def fetch_recent_messages(conn: imaplib.IMAP4_SSL, since_unix: float) -> list[Message]:
    """
    Return messages received since `since_unix`. IMAP SINCE only supports
    day-granularity, so we pad by a day on the server side and then filter
    by Date header to second-granularity client-side. Messages with no
    parseable Date are included (better to over-include than to silently
    miss a message that's there).
    """
    since_struct = time.gmtime(since_unix - 86400)
    since_str = time.strftime("%d-%b-%Y", since_struct)
    typ, data = conn.search(None, f'(SINCE "{since_str}")')
    if typ != "OK" or not data or not data[0]:
        return []
    msg_ids = data[0].split()
    out: list[Message] = []
    # Tolerance: 60s past the requested floor handles clock skew and Received
    # vs Date differences. Tightening this would be more precise but risks
    # dropping just-delivered messages.
    cutoff = since_unix - 60
    for mid in msg_ids:
        typ, msg_data = conn.fetch(mid, "(RFC822)")
        if typ != "OK" or not msg_data:
            continue
        for part in msg_data:
            if isinstance(part, tuple) and len(part) >= 2 and isinstance(part[1], (bytes, bytearray)):
                msg = email.message_from_bytes(bytes(part[1]))
                ts = _message_timestamp(msg)
                # Include messages with no parseable Date — over-include is safer
                # than under-include for a delivery verifier.
                if ts is None or ts >= cutoff:
                    out.append(msg)
    return out


def matches(msg: Message, to_addr: str, subject_substr: str, body_substr: str) -> bool:
    to_header = (msg.get("To") or "") + " " + (msg.get("Delivered-To") or "") + " " + (msg.get("X-Original-To") or "")
    if to_addr.lower() not in to_header.lower():
        return False
    if subject_substr and subject_substr.lower() not in (msg.get("Subject") or "").lower():
        return False
    if body_substr:
        body = message_text(msg)
        if body_substr.lower() not in body.lower():
            return False
    return True


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--to", required=True,
                   help="Recipient address to look for in the To: / Delivered-To: / X-Original-To: headers.")
    p.add_argument("--subject-contains", default="",
                   help="Substring that must appear in the Subject header (case-insensitive).")
    p.add_argument("--body-contains", default="",
                   help="Substring that must appear in the message body (case-insensitive).")
    p.add_argument("--timeout", type=int, default=60,
                   help="Total seconds to keep polling.")
    p.add_argument("--poll-interval", type=int, default=5,
                   help="Seconds between IMAP polls.")
    args = p.parse_args()

    if args.timeout <= 0:
        p.error("--timeout must be > 0")
    if args.poll_interval <= 0:
        p.error("--poll-interval must be > 0")
    if args.poll_interval > args.timeout:
        p.error("--poll-interval cannot exceed --timeout")

    imap_host = env("MAIL_IMAP_ADDRESS")
    imap_port_raw = env("MAIL_IMAP_PORT")
    try:
        imap_port = int(imap_port_raw)
    except ValueError:
        print(f"ERROR: MAIL_IMAP_PORT is not numeric: {imap_port_raw!r}", file=sys.stderr)
        return 2
    imap_user = env("MAIL_USERNAME")
    imap_pass = env("MAIL_PASSWORD")

    print(f"[1/2] Connecting IMAP {imap_host}:{imap_port} as {imap_user}")
    started = time.time()
    deadline = started + args.timeout

    while time.time() < deadline:
        conn = connect_imap(imap_host, imap_port, imap_user, imap_pass)
        try:
            messages = fetch_recent_messages(conn, started - 60)
        finally:
            try:
                conn.logout()
            except Exception:  # noqa: BLE001
                pass

        for msg in messages:
            if matches(msg, args.to, args.subject_contains, args.body_contains):
                print(f"PASS — matching message found:")
                print(f"       From   : {msg.get('From')}")
                print(f"       To     : {msg.get('To')}")
                print(f"       Subject: {msg.get('Subject')}")
                print(f"       Date   : {msg.get('Date')}")
                return 0

        elapsed = int(time.time() - started)
        remaining = int(deadline - time.time())
        print(f"[2/2] No match yet ({elapsed}s elapsed, {remaining}s remaining, "
              f"{len(messages)} candidates scanned). Sleeping {args.poll_interval}s...")
        time.sleep(args.poll_interval)

    print(f"FAIL — no message matching to={args.to!r}, "
          f"subject contains {args.subject_contains!r}, "
          f"body contains {args.body_contains!r} arrived in {args.timeout}s.",
          file=sys.stderr)
    print("       Check: (a) WP test instance's mail config (SMTP from / wp_mail filter); "
          "(b) DNS for the recipient alias resolves to the mail host; (c) outbound "
          "queue isn't backlogged.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
