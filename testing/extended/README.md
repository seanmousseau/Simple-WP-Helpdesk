# Extended Test Suite

Scripts that support the **release-candidate sign-off runbook** at
`docs/internal/extended-test-plan.md`. They run against the real dev WordPress
instance and a real mail server — not Docker, not stubs.

**These scripts are NOT invoked by CI.** They're invoked by hand once per RC,
following the runbook step-by-step.

## When to use this directory

- You're preparing a `dev → main` release-candidate PR.
- All per-push gates are green (`make test-docker`, PHPUnit, invariants, etc.).
- You want the manual / mechanical confidence check before promoting to `main`.

For per-PR work, the regular `make test-docker` / Playwright `make e2e-docker`
flow is the right tool. This directory is for the bigger checkpoint.

## Setup (one-time)

```bash
# Install runtime dependencies.
cd testing && python3 -m venv .venv && source .venv/bin/activate
pip install requests
```

The IMAP and SMTP libraries used (`imaplib`, `smtplib`, `email`) are in the
Python standard library — no install needed.

## Before every run

Source the secrets file in your current shell. Every script reads credentials
exclusively from environment variables; nothing is hard-coded.

```bash
set -a; source ~/.claude/dev-secrets.env; set +a

# Three extra vars not in dev-secrets.env yet — for the wp-cli bridge:
export SSH_TARGET=<user>@<host>
export WP_CONTAINER=<docker-container-name>
export WP_PATH=<absolute-path-to-WP-inside-container>
```

Then run the verifier — it checks every required var, IMAP/SMTP login, and
SSH+wp-cli reachability in one go:

```bash
python3 testing/extended/verify_env.py
```

Expected output ends with `PASS — environment is ready for the runbook.` —
exit code 0. Any red tick means a real problem; don't proceed until it's green.

### Env-file gotcha — single-quote values containing `$`

Values like passwords that contain `$` must be **single-quoted** in
`dev-secrets.env`. Double quotes trigger shell variable expansion when the
file is sourced: `"abc$1xyz"` becomes `abcxyz` (the `$1` is the empty
positional parameter). The `verify_env.py` length check catches this by
cross-referencing the runtime value length against the literal length in
the file, but it's faster to just write the file correctly from the start.

## Scripts

### `verify_env.py`

Pre-flight environment check. Verifies every required env var is set,
cross-checks password lengths against the literal file values (to catch
shell-expansion mangling), and live-tests SSH+wp-cli + IMAP + SMTP. Runs
in ~5s. Always run this before the runbook.

```bash
python3 verify_env.py
```

### `webhook_roundtrip.py`

End-to-end test of the inbound-email webhook. Creates a test ticket, SMTP-sends
a reply to one of the addresses in `$WP_WEBHOOK_TIED_TO`, polls WP REST until the
helpdesk_reply comment shows up (or until timeout). Used in section 2 of the
runbook.

```bash
# Happy path (uses first address in $WP_WEBHOOK_TIED_TO).
python3 webhook_roundtrip.py

# Negative case — sender mismatch (auto-uses $WP_TESTING_CLIENT_EMAIL_2):
python3 webhook_roundtrip.py --expect-rejection sender_mismatch

# Negative case — subject missing [TKT-XXXX]:
python3 webhook_roundtrip.py --expect-rejection no_ticket_id

# Negative case — subject references a ticket UID that doesn't exist:
python3 webhook_roundtrip.py --expect-rejection ticket_not_found

python3 webhook_roundtrip.py --help    # full options
```

### `webhook_direct_post.py`

Direct POST to the webhook (bypassing SMTP) for auth-layer rejection tests
that SMTP can't exercise — wrong bearer, missing Authorization header, or
malformed payloads.

```bash
# Wrong bearer → expect 401
python3 webhook_direct_post.py --post-id <real-ticket-id> \
    --bearer "definitely-not-the-real-secret" --expect-status 401

# Missing Authorization header → expect 401
python3 webhook_direct_post.py --post-id <real-ticket-id> \
    --no-auth-header --expect-status 401

# Omit a required field → expect 200 + success:false
python3 webhook_direct_post.py --post-id <real-ticket-id> \
    --omit-sender --expect-status 200 --expect-success false
```

### `email_delivery_check.py`

Polls the test mailbox via IMAP for a message matching a recipient alias and
optional subject/body substrings. Used in section 3 of the runbook to verify
that outbound emails from WP actually arrive.

```bash
# Wait up to 60s for a TKT-* message to land at the first client alias:
python3 email_delivery_check.py --to "$WP_TESTING_CLIENT_EMAIL_1" \
                               --subject-contains "TKT-" \
                               --body-contains "UNIQUE-MARKER-HERE" \
                               --timeout 60

python3 email_delivery_check.py --help
```

### `capture_screenshots.py`

Drives a headless Chromium through the admin inbox, settings, and reports
pages in each of light / dark / auto theme. Saves PNGs to
`screenshots/<timestamp>/` for visual sanity review in section 7.4 of the
runbook.

```bash
pip install playwright && playwright install chromium   # one-time
python3 capture_screenshots.py
python3 capture_screenshots.py --headed                  # watch it work
python3 capture_screenshots.py --only inbox,settings     # subset
```

### `cleanup.py`

Deletes every ticket whose title starts with `CLAUDE-TEST-` and expunges
matching messages from the test mailbox. Run at the START of the runbook
(pre-flight, to avoid stale state from a previous aborted run) and at the END
(courtesy, so the dev environment doesn't accumulate junk).

```bash
# Interactive (prompts before deleting):
python3 cleanup.py

# Non-interactive (auto-confirms):
python3 cleanup.py --confirm

# Show what would be deleted without acting:
python3 cleanup.py --dry-run

# Only touch one side:
python3 cleanup.py --skip-mailbox       # tickets only
python3 cleanup.py --skip-tickets       # mailbox only
```

## Invocation summary

The runbook is the source of truth, but for quick reference:

```bash
make test-extended       # prints the runbook URL + pre-flight cleanup
                         # then expects the operator to follow the markdown
```

## Conventions

- **Test ticket title prefix:** `CLAUDE-TEST-`. Cleanup uses this as a strict
  startswith match, so non-test tickets that happen to mention the prefix
  somewhere in their title are unaffected.
- **Markers in test message bodies:** every script that sends mail includes a
  short random marker (`<random-hex>`) so the verifier can distinguish a
  freshly-sent message from any historical mail in the inbox. Use the same
  approach in manual steps: include `<TEST-MARKER-${RANDOM}>` in any text you
  type so subsequent IMAP polls can identify it precisely.
- **Failure mode:** every script exits with `0` on PASS, `1` on FAIL with a
  diagnostic, `2` on configuration error (typically missing env var). The
  runbook treats `1` as a release blocker and `2` as a setup bug.

## Adding a new script

If you find yourself adding a 4th script: don't skip the audit. Every script in
this directory should:

1. Read **all** credentials and identifiers from `os.environ`. No hardcoded
   passwords, no hardcoded hostnames, no hardcoded email aliases. (Hostnames
   used to be hardcoded as defaults; that was changed deliberately — see PR
   merge history.)
2. Exit with code 2 (not 1) if a required env var is missing.
3. Print a `PASS` / `FAIL` line as the LAST line of stdout when finished so the
   runbook operator can copy-paste the result into the PR.
4. Be re-runnable. No "this only works once per session" state.
