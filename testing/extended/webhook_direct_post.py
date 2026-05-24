#!/usr/bin/env python3
"""
Direct POST to the inbound webhook, bypassing SMTP.

Used in runbook section 2 to exercise auth-layer rejection paths that
SMTP can't reach: bad bearer, missing bearer, malformed payload. SMTP
sits between the operator and the webhook in normal flow, so any auth
test has to bypass it.

Usage:

    # Happy path: posts directly with the real bearer, hits a real ticket.
    # Useful as a sanity check that the webhook is responding at all.
    python testing/extended/webhook_direct_post.py --post-id 123

    # Bad bearer → expect 401 swh_unauthorized
    python testing/extended/webhook_direct_post.py --post-id 123 \\
        --bearer "definitely-not-the-real-secret" \\
        --expect-status 401

    # Missing bearer entirely → expect 401
    python testing/extended/webhook_direct_post.py --post-id 123 \\
        --no-auth-header \\
        --expect-status 401

    # Malformed payload (e.g. no `sender` field) → 200 + {success:false}
    python testing/extended/webhook_direct_post.py --post-id 123 \\
        --omit-sender --expect-status 200 --expect-success false

Exit codes:
    0  PASS — actual status / success bit matched expectations
    1  FAIL
    2  Configuration error

Environment:
    Sources ~/.claude/dev-secrets.env. Required keys:
        WP_TESTING_URL
        SSH_TARGET, WP_CONTAINER, WP_PATH  (to fetch the real bearer
                                            from the swh_settings_integrations option)
"""

from __future__ import annotations

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _wpcli import env, wpcli  # noqa: E402

try:
    import requests
except ImportError:
    print("ERROR: `requests` required. pip install requests", file=sys.stderr)
    sys.exit(2)


def fetch_inbound_secret() -> str:
    """Read swh_settings_integrations.inbound_secret via wp-cli."""
    raw = wpcli([
        "option", "get",
        "swh_settings_integrations",
        "--format=json",
    ])
    # Strip wp-cli deprecation prefix (same approach as _wpcli.wpcli_json).
    for i, ch in enumerate(raw):
        if ch in "[{":
            raw = raw[i:]
            break
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError:
        raise RuntimeError(f"swh_settings_integrations option not JSON: {raw[:200]!r}")
    if not isinstance(parsed, dict):
        raise RuntimeError("swh_settings_integrations option is not an object.")
    secret = parsed.get("inbound_secret", "")
    if not isinstance(secret, str) or not secret:
        raise RuntimeError("inbound_secret is empty — webhook is unconfigured.")
    return secret


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--post-id", type=int, required=True,
                   help="An existing helpdesk_ticket post ID to reference in the subject.")
    p.add_argument("--bearer", default=None,
                   help="Override the bearer token. Default: real secret fetched via wp-cli.")
    p.add_argument("--no-auth-header", action="store_true",
                   help="Send NO Authorization header (tests the missing-bearer path).")
    p.add_argument("--omit-sender", action="store_true",
                   help="Send no `sender` field in the payload.")
    p.add_argument("--omit-subject", action="store_true",
                   help="Send no `subject` field in the payload.")
    p.add_argument("--omit-body", action="store_true",
                   help="Send no `body-plain` field in the payload.")
    p.add_argument("--expect-status", type=int, default=200,
                   help="Expected HTTP status. Default 200.")
    p.add_argument("--expect-success", choices=["true", "false", "any"], default="any",
                   help="Expected value of body['success']. 'any' to skip the check.")
    p.add_argument("--expect-error", default=None,
                   help="Expected value of body['error'] (when success=false).")
    args = p.parse_args()

    wp_url = env("WP_TESTING_URL").rstrip("/")

    # Resolve bearer.
    if args.bearer is None:
        try:
            bearer = fetch_inbound_secret()
        except RuntimeError as e:
            print(f"ERROR: {e}", file=sys.stderr)
            return 2
    else:
        bearer = args.bearer

    # Build payload.
    payload: dict[str, str] = {}
    if not args.omit_subject:
        payload["subject"] = f"Re: [TKT-{args.post_id:04d}] direct-post test"
    if not args.omit_body:
        payload["body-plain"] = "Direct webhook POST."
    if not args.omit_sender:
        # Reuse the configured client alias — sender match only matters if
        # the ticket exists and this address matches its _ticket_email.
        payload["sender"] = env("WP_TESTING_CLIENT_EMAIL_1")

    headers = {"Content-Type": "application/json"}
    if not args.no_auth_header:
        headers["Authorization"] = f"Bearer {bearer}"

    url = f"{wp_url}/wp-json/swh/v1/inbound-email"
    print(f"POST {url}")
    print(f"  auth-header   : {'omitted' if args.no_auth_header else 'present'}")
    print(f"  bearer        : {'real' if bearer == (args.bearer or '') else 'real (resolved)'}")
    print(f"  payload keys  : {list(payload.keys())}")

    resp = requests.post(url, headers=headers, json=payload, timeout=15)
    print(f"\nResponse:")
    print(f"  status: {resp.status_code}")
    try:
        body = resp.json()
    except json.JSONDecodeError:
        body = None
        print(f"  body  : (non-JSON) {resp.text[:200]!r}")
    else:
        print(f"  body  : {body}")

    # Compare.
    problems = []
    if resp.status_code != args.expect_status:
        problems.append(f"HTTP status {resp.status_code} != expected {args.expect_status}")
    if args.expect_success != "any" and isinstance(body, dict):
        expected_bool = (args.expect_success == "true")
        actual = body.get("success")
        if actual is not expected_bool:
            problems.append(f"body.success is {actual!r} != expected {expected_bool!r}")
    if args.expect_error is not None and isinstance(body, dict):
        actual_err = body.get("error", "")
        if actual_err != args.expect_error:
            problems.append(f"body.error is {actual_err!r} != expected {args.expect_error!r}")

    if problems:
        print("\nFAIL:")
        for p in problems:
            print(f"  • {p}")
        return 1
    print("\nPASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
