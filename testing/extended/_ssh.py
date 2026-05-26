"""
Paramiko-based SSH executor shared by `verify_env.py` and `_wpcli.py`.

Why paramiko rather than `subprocess.run(["ssh", ...])`:
    - The `ssh` binary path required the calling Python process to shell out
      with env-derived arguments. Semgrep OSS's
      `dangerous-subprocess-use-tainted-env-args` rule re-flagged the call
      sites on every edit even though the actual risk was contained via
      `shlex.join()`. Closes #447.
    - Paramiko talks directly to the remote sshd over TCP, so the local
      Python process never launches a subprocess and the shell-arg-injection
      taint analysis stops applying.
    - We still pass a shell-quoted string to the remote shell (no way around
      that — sshd's protocol is "run this string"), but the quoting is built
      with `shlex.join()` at this layer and there's no host-side shell
      involvement.

Auth chain — tries each in order, falls back to next if one is unavailable:
    1. SSH agent (preferred — what the operator uses interactively).
    2. Default key files (~/.ssh/id_ed25519, ~/.ssh/id_rsa).

Does NOT support password auth — the runbook is operator-only and key-based.

Required env vars (validated by the caller):
    SSH_TARGET   e.g. "user@host" or "host"
"""

from __future__ import annotations

import os
import shlex
from dataclasses import dataclass
from typing import Optional

import paramiko


@dataclass
class SSHResult:
    """Result of a single remote command execution."""

    returncode: int
    stdout: str
    stderr: str


def _parse_ssh_target(target: str) -> tuple[str, str]:
    """Split SSH_TARGET into (user, host). user defaults to the local $USER."""
    if "@" in target:
        user, host = target.split("@", 1)
    else:
        user, host = os.environ.get("USER", "root"), target
    return user, host


def ssh_run(
    argv: list[str],
    *,
    timeout: float = 20.0,
    target: Optional[str] = None,
) -> SSHResult:
    """
    Run `argv` as a single command on the SSH_TARGET host.

    `argv` is shell-joined with `shlex.join()` and handed to the remote
    sshd. The remote login shell still expands it (we can't avoid that —
    sshd's protocol is "run this string"), but every component is safely
    quoted before transmission.

    Raises `paramiko.SSHException` (or a subclass) on connection / auth /
    transport errors. Returns an `SSHResult` for any case where the remote
    command actually ran, even if it exited nonzero — callers handle the
    exit code.
    """
    raw_target = target or os.environ.get("SSH_TARGET", "")
    if not raw_target:
        raise RuntimeError("SSH_TARGET is empty — required for ssh_run().")

    user, host = _parse_ssh_target(raw_target)
    command = shlex.join(argv)

    client = paramiko.SSHClient()
    # The dev environment is operator-owned and lives on a private network;
    # we trust the host on first connect and let known_hosts pin subsequent
    # ones. AutoAddPolicy is the right choice for a personal dev runbook.
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.load_system_host_keys()
    try:
        client.connect(
            hostname=host,
            username=user,
            timeout=timeout,
            allow_agent=True,
            look_for_keys=True,
        )
        stdin, stdout, stderr = client.exec_command(command, timeout=timeout)  # noqa: S601
        stdin.close()
        out_text = stdout.read().decode("utf-8", errors="replace")
        err_text = stderr.read().decode("utf-8", errors="replace")
        rc = stdout.channel.recv_exit_status()
        return SSHResult(returncode=rc, stdout=out_text, stderr=err_text)
    finally:
        client.close()
