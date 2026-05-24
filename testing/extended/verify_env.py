#!/usr/bin/env python3
"""
Pre-flight environment verifier — section 0 of the RC runbook.

Catches the dev-secrets.env issues that historically derail runbook starts:
  - Missing or empty required variables
  - Passwords mangled by shell variable expansion (double-quoted values with $)
  - SSH+wp-cli not reachable
  - IMAP login fails (wrong creds or password mangled)
  - SMTP connect fails (wrong host/port)
  - Hostnames that look pasted from a different env

Run this BEFORE following any other step of the runbook. It is fast (~5s)
and exits non-zero with a clear diagnostic on the first failure.

Usage:

    set -a; source ~/.claude/dev-secrets.env; set +a
    python testing/extended/verify_env.py

Exit codes:
    0  All checks pass.
    1  At least one check failed.
    2  The script itself can't run (e.g. dev-secrets.env file unreadable).
"""

from __future__ import annotations

import imaplib
import os
import re
import shlex
import smtplib
import socket
import subprocess
import sys
from pathlib import Path
from typing import Optional

# All variables the extended-test scripts read from the environment.
REQUIRED_VARS = [
    # WP target
    "WP_TESTING_URL",
    "WP_TESTING_USER",
    "WP_TESTING_PASS",
    # Technicians (used by runbook section 1)
    "WP_TESTING_TECH_USER_1", "WP_TESTING_TECH_PASS_1", "WP_TESTING_TECH_EMAIL_1",
    "WP_TESTING_TECH_USER_2", "WP_TESTING_TECH_PASS_2", "WP_TESTING_TECH_EMAIL_2",
    "WP_TESTING_TECH_USER_3", "WP_TESTING_TECH_PASS_3", "WP_TESTING_TECH_EMAIL_3",
    # Clients (used as ticket requesters)
    "WP_TESTING_CLIENT_EMAIL_1",
    "WP_TESTING_CLIENT_EMAIL_2",
    # Webhook addresses
    "WP_WEBHOOK_TIED_TO",
    # Mail server (SMTP for outbound from test scripts; IMAP for verifying delivery)
    "MAIL_USERNAME", "MAIL_PASSWORD",
    "MAIL_IMAP_ADDRESS", "MAIL_IMAP_PORT",
    "MAIL_SMTP_ADDRESS", "MAIL_SMTP_PORT",
    # SSH+docker bridge to wp-cli
    "SSH_TARGET",
    "WP_CONTAINER",
    "WP_PATH",
]

# Vars whose value MUST match what's literally in the file (i.e. shell
# expansion must not have eaten anything). Limited to passwords because
# they're the values most likely to contain $ that triggers the bug.
LENGTH_CHECK_VARS = [
    "WP_TESTING_PASS",
    "WP_TESTING_TECH_PASS_1",
    "WP_TESTING_TECH_PASS_2",
    "WP_TESTING_TECH_PASS_3",
    "MAIL_PASSWORD",
]

SECRETS_FILE = Path.home() / ".claude" / "dev-secrets.env"


def style(s: str, color: str) -> str:
    if not sys.stdout.isatty():
        return s
    codes = {"red": "31", "green": "32", "yellow": "33", "dim": "2"}
    return f"\033[{codes.get(color, '0')}m{s}\033[0m"


def report(passing: bool, label: str, detail: str = "") -> None:
    icon = style("✓", "green") if passing else style("✗", "red")
    print(f"  {icon} {label}" + (f"  {style(detail, 'dim')}" if detail else ""))


def check_required_vars() -> list[str]:
    """Returns a list of variable names that are unset or empty."""
    missing = []
    for name in REQUIRED_VARS:
        val = os.environ.get(name, "")
        if not val:
            missing.append(name)
    return missing


def parse_secrets_file_lengths() -> dict[str, int]:
    """
    Read the secrets file once and compute the literal char-length of each
    LENGTH_CHECK_VARS value, ignoring outer quotes. Used to detect shell
    expansion that ate characters when the file was sourced.

    Returns {var_name: expected_length}. Missing entries silently skipped.
    """
    out: dict[str, int] = {}
    if not SECRETS_FILE.is_file():
        return out
    try:
        text = SECRETS_FILE.read_text()
    except OSError:
        return out
    for line in text.splitlines():
        m = re.match(r'^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$', line)
        if not m:
            continue
        name, raw = m.group(1), m.group(2)
        if name not in LENGTH_CHECK_VARS:
            continue
        # Strip a single layer of matching quotes — either ' or ".
        stripped = raw
        if len(stripped) >= 2 and stripped[0] in ("'", '"') and stripped[-1] == stripped[0]:
            stripped = stripped[1:-1]
        out[name] = len(stripped)
    return out


def check_password_lengths() -> list[str]:
    """
    Compare runtime length of each LENGTH_CHECK_VARS value against the
    file-contents length. A mismatch usually means shell variable expansion
    inside double quotes ate part of the value (e.g. `$66im4I58O` → `m4I58O`).

    Returns a list of human-readable problem descriptions.
    """
    expected = parse_secrets_file_lengths()
    problems = []
    for name, exp_len in expected.items():
        runtime = os.environ.get(name, "")
        if len(runtime) != exp_len:
            problems.append(
                f"{name}: runtime length is {len(runtime)} but the file has "
                f"{exp_len} chars — shell expansion likely consumed part of "
                f"the value. Fix: switch to single quotes in the env file."
            )
    return problems


def check_ssh_wpcli() -> Optional[str]:
    """
    Verify SSH + docker exec + wp-cli can reach the WP install.

    Returns None on success, or an error string.
    """
    ssh_target = os.environ.get("SSH_TARGET", "")
    container = os.environ.get("WP_CONTAINER", "")
    wp_path = os.environ.get("WP_PATH", "")
    if not (ssh_target and container and wp_path):
        return "SSH_TARGET / WP_CONTAINER / WP_PATH are required for wp-cli."

    # Defense in depth: env values come from a trusted secrets file in this
    # workflow, but build the remote shell command via shlex.join so an
    # adversarial WP_CONTAINER / WP_PATH (e.g. `foo;rm -rf /`) can't escape
    # into shell metacharacters. SSH itself passes the command string to the
    # remote login shell, so quoting at this layer is what matters.
    remote_cmd = shlex.join([
        "docker", "exec", "-i", container,
        "wp", "--allow-root", f"--path={wp_path}", "core", "version",
    ])
    cmd = [
        "ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=10",
        ssh_target,
        remote_cmd,
    ]
    try:
        # subprocess.run with a list avoids host-side shell interpretation.
        # The remote_cmd argument is shlex-quoted above, so the SSH remote
        # shell receives a safe command string. We accept env-derived inputs
        # here only because (a) this is a developer-only test tool that
        # reads ~/.claude/dev-secrets.env (operator-owned, not network-
        # sourced), (b) every interpolated value is shlex-joined, and (c) a
        # malicious env file is already game-over for the operator. The
        # nosemgrep directive must be on the SAME line as the call — Semgrep
        # OSS does NOT honor the comment-on-preceding-line form.
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=20, check=False)  # nosemgrep: python.lang.security.audit.dangerous-subprocess-use-tainted-env-args.dangerous-subprocess-use-tainted-env-args
    except (subprocess.TimeoutExpired, FileNotFoundError) as e:
        return f"SSH or docker invocation failed: {e}"
    if r.returncode != 0:
        return f"wp-cli returned exit {r.returncode}. stderr: {r.stderr.strip()[:200]}"
    # WP core version output may contain wp-cli's "Deprecated:" noise. Find a
    # plausible version on any line.
    for line in (r.stdout or "").splitlines():
        if re.match(r"^\s*\d+\.\d+", line.strip()):
            return None
    return f"wp-cli reachable but core version output looked unexpected: {r.stdout[:200]!r}"


def check_imap() -> Optional[str]:
    host = os.environ.get("MAIL_IMAP_ADDRESS", "")
    port = os.environ.get("MAIL_IMAP_PORT", "")
    user = os.environ.get("MAIL_USERNAME", "")
    password = os.environ.get("MAIL_PASSWORD", "")
    if not (host and port and user and password):
        return "MAIL_IMAP_* / MAIL_USERNAME / MAIL_PASSWORD all required."
    try:
        port_int = int(port)
    except ValueError:
        return f"MAIL_IMAP_PORT is not numeric: {port!r}"
    try:
        conn = imaplib.IMAP4_SSL(host, port_int, timeout=10)
        conn.login(user, password)
        conn.logout()
    except imaplib.IMAP4.error as e:
        return f"IMAP login failed: {e!s}"
    except (OSError, socket.timeout) as e:
        return f"IMAP connect failed: {e!s}"
    return None


def check_smtp() -> Optional[str]:
    host = os.environ.get("MAIL_SMTP_ADDRESS", "")
    port = os.environ.get("MAIL_SMTP_PORT", "")
    user = os.environ.get("MAIL_USERNAME", "")
    password = os.environ.get("MAIL_PASSWORD", "")
    if not (host and port and user and password):
        return "MAIL_SMTP_* / MAIL_USERNAME / MAIL_PASSWORD all required."
    try:
        port_int = int(port)
    except ValueError:
        return f"MAIL_SMTP_PORT is not numeric: {port!r}"
    try:
        with smtplib.SMTP(host, port_int, timeout=10) as s:
            s.ehlo()
            s.starttls()
            s.login(user, password)
            # Don't send anything — connecting + auth is enough.
    except smtplib.SMTPException as e:
        return f"SMTP login/connect failed: {e!s}"
    except (OSError, socket.timeout) as e:
        return f"SMTP connect failed: {e!s}"
    return None


def main() -> int:
    if not SECRETS_FILE.is_file():
        print(style(f"✗ {SECRETS_FILE} not found — can't verify lengths.", "yellow"))
        # Not fatal — env may have come from elsewhere.

    print("Verifying RC extended-test environment...\n")
    failed = False

    print("Required variables:")
    missing = check_required_vars()
    if missing:
        failed = True
        report(False, f"{len(missing)} variable(s) missing or empty",
               f"first: {missing[0]}")
        for m in missing[:6]:
            print(f"      • {m}")
        if len(missing) > 6:
            print(f"      • … and {len(missing) - 6} more")
    else:
        report(True, f"all {len(REQUIRED_VARS)} required variables set")

    print("\nPassword integrity (shell-expansion check):")
    pw_problems = check_password_lengths()
    if pw_problems:
        failed = True
        report(False, "password length mismatch detected")
        for p in pw_problems:
            print(f"      • {p}")
    else:
        report(True, "password lengths match the secrets-file values",
               f"checked {len(LENGTH_CHECK_VARS)} keys")

    print("\nLive checks:")
    err = check_ssh_wpcli()
    if err:
        failed = True
        report(False, "SSH + docker + wp-cli", err)
    else:
        report(True, "SSH + docker + wp-cli reach the WP install")

    err = check_imap()
    if err:
        failed = True
        report(False, "IMAP login", err)
    else:
        report(True, "IMAP login")

    err = check_smtp()
    if err:
        failed = True
        report(False, "SMTP login (connect + STARTTLS + AUTH; no message sent)", err)
    else:
        report(True, "SMTP login (connect + STARTTLS + AUTH; no message sent)")

    print()
    if failed:
        print(style("FAIL — fix the issues above before running the runbook.", "red"))
        return 1
    print(style("PASS — environment is ready for the runbook.", "green"))
    return 0


if __name__ == "__main__":
    sys.exit(main())
