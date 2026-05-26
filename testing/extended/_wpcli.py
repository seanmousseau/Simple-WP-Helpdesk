"""
Shared WP-CLI shell-out helper for the extended-test scripts.

The plugin's REST endpoints require cookie auth or application passwords,
which the runbook intentionally avoids (operator only needs one credential
set in dev-secrets.env). WP-CLI over SSH is the simpler, uniform read+write
primitive — it inherits filesystem-level WP auth, no nonces, no headers.

Required env vars (no defaults — fail-closed):
    SSH_TARGET     e.g. user@host or just hostname
    WP_CONTAINER   docker container name running PHP-FPM/Apache
    WP_PATH        WP install path INSIDE the container

Optional:
    WP_CLI_ALLOW_ROOT  set to "1" to add --allow-root (typical for dockerized WP)
"""

from __future__ import annotations

import json
import os
import sys
from typing import Optional

from _ssh import ssh_run


def env(name: str, default: Optional[str] = None) -> str:
    val = os.environ.get(name, default)
    if val is None or val == "":
        print(f"ERROR: env var {name} is not set. Source ~/.claude/dev-secrets.env first.",
              file=sys.stderr)
        sys.exit(2)
    return val


def wpcli(args: list[str], capture: bool = True, check: bool = True) -> str:
    """
    Run `wp <args>` against the configured WP container via SSH+docker.

    Returns stdout (decoded) on success. Raises CalledProcessError on
    nonzero exit unless check=False.

    Uses paramiko via `_ssh.ssh_run()` — no local subprocess, so Semgrep
    OSS's `dangerous-subprocess-use-tainted-env-args` rule no longer
    re-flags this file on every edit. Closes #447. The remote command is
    still shell-quoted via `shlex.join()` inside `ssh_run()` before sshd
    transmits it.

    The `capture` parameter is retained for call-site compatibility but is
    now a no-op: paramiko always captures stdout/stderr separately.
    """
    _ = capture  # kept for signature compatibility; paramiko always captures
    env("SSH_TARGET")  # validate
    container = env("WP_CONTAINER")
    wp_path = env("WP_PATH")

    wp_cmd = ["wp"]
    if os.environ.get("WP_CLI_ALLOW_ROOT", "1") == "1":
        wp_cmd.append("--allow-root")
    wp_cmd.extend([f"--path={wp_path}"])
    wp_cmd.extend(args)

    # docker exec runs inside the container; prepend an env override that
    # silences PHP "Deprecated:" noise from the wp-cli phar (React/Promise
    # uses deprecated case-with-semicolon syntax under PHP 8.3+).
    # `E_ALL & ~E_DEPRECATED` leaves real errors visible while killing the
    # deprecations.
    remote_argv = [
        "docker", "exec", "-i",
        "-e", "WP_CLI_PHP_ARGS=-d error_reporting=E_ALL&~E_DEPRECATED",
        container,
    ] + wp_cmd
    result = ssh_run(remote_argv)

    if check and result.returncode != 0:
        msg = (
            f"wp-cli command failed (exit {result.returncode}):\n"
            f"  cmd: {' '.join(wp_cmd)}\n"
            f"  stderr: {result.stderr.strip()[:500]}"
        )
        raise RuntimeError(msg)
    return result.stdout


def wpcli_json(args: list[str]) -> object:
    """
    Convenience: wpcli() with --format=json appended, parsed.

    The wp-cli phar emits a PHP "Deprecated:" line to stdout on every
    invocation (React/Promise's case-with-semicolon syntax under PHP 8.3+).
    `WP_CLI_PHP_ARGS=-d error_reporting=...` doesn't help because the
    deprecation fires during the phar's own bootstrap, BEFORE PHP applies
    the env override. Strip any prefix lines before the first JSON-shaped
    character so the parse succeeds.
    """
    out = wpcli(args + ["--format=json"])
    if not out.strip():
        return []
    for i, ch in enumerate(out):
        if ch in "[{":
            out = out[i:]
            break
    return json.loads(out)


def list_test_tickets(prefix: str) -> list[dict]:
    """
    Return helpdesk_ticket posts whose title starts with `prefix`.

    Uses wp post list with --post_type=helpdesk_ticket and a server-side title
    search, then filters strict-startswith client-side (safer than relying on
    --s= which is full-text search across title+content).
    """
    out = wpcli_json([
        "post", "list",
        "--post_type=helpdesk_ticket",
        "--post_status=any",
        "--posts_per_page=500",
        "--fields=ID,post_title,post_status,post_date",
        f"--s={prefix}",
    ])
    if not isinstance(out, list):
        return []
    result: list[dict] = []
    for row in out:
        if not isinstance(row, dict):
            continue
        title = row.get("post_title", "")
        if isinstance(title, str) and title.startswith(prefix):
            result.append(row)
    return result


def create_test_ticket(title: str, requester_email: str, content: str = "") -> int:
    """
    Insert a helpdesk_ticket post. Returns the new post ID.

    Sets _ticket_email and _ticket_status meta as a separate step because wp
    post create doesn't accept arbitrary meta in a single call. The
    _ticket_uid is set by the plugin's submission hook for shortcode-created
    tickets but NOT for CLI-created ones — we use TKT-<padded-id> as the UID
    so the webhook can match.
    """
    raw = wpcli([
        "post", "create",
        "--post_type=helpdesk_ticket",
        "--post_status=publish",
        f"--post_title={title}",
        f"--post_content={content or 'Created by webhook_roundtrip.py'}",
        "--porcelain",  # outputs the post ID only
    ])
    # Strip the PHP deprecation prefix (see wpcli_json() docstring).
    digits = ""
    for line in raw.strip().splitlines():
        stripped = line.strip()
        if stripped.isdigit():
            digits = stripped
            break
    if not digits:
        raise RuntimeError(f"wp post create returned no parseable post id: {raw!r}")
    post_id = int(digits)

    ticket_uid = f"TKT-{post_id:04d}"
    for key, value in (
        ("_ticket_email", requester_email),
        ("_ticket_status", "Open"),
        ("_ticket_uid", ticket_uid),
    ):
        wpcli([
            "post", "meta", "update",
            str(post_id), key, value,
        ])
    return post_id


def delete_test_ticket(post_id: int) -> bool:
    """Force-delete (skip Trash). Returns True on success."""
    try:
        wpcli(["post", "delete", str(post_id), "--force"])
        return True
    except RuntimeError:
        return False


def get_ticket_meta(post_id: int, key: str) -> str:
    """Read a single post-meta value as string."""
    return wpcli([
        "post", "meta", "get",
        str(post_id), key,
    ]).strip()


def list_ticket_comments(post_id: int) -> list[dict]:
    """
    Return all comments on a ticket (any type, any status). Order: oldest first.

    IMPORTANT: must use --post_id (singular), NOT --post__in. The plugin's
    `swh_exclude_helpdesk_comments` filter (in includes/class-ticket.php)
    short-circuits — i.e. lets helpdesk_reply comments through — ONLY when
    the query sets `query_vars['post_id']` to a helpdesk_ticket. Using
    --post__in sets `post__in`, NOT `post_id`, so the short-circuit
    doesn't fire and helpdesk_replies are filtered out, leaving us with
    an empty result. This silently failed during initial development of
    webhook_roundtrip.py — the comments existed, they were just invisible.
    """
    out = wpcli_json([
        "comment", "list",
        f"--post_id={post_id}",
        "--number=500",
        "--orderby=comment_date_gmt",
        "--order=ASC",
        "--fields=comment_ID,comment_post_ID,comment_content,comment_type,comment_author,comment_date_gmt",
    ])
    return out if isinstance(out, list) else []
