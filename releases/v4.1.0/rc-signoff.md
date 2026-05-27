# v4.1.0 — RC Sign-off

**Branch tested:** `dev` @ `12083be` (Gate 2 merge of `release/v4.1.0`)
**Tested by:** Claude (Opus 4.7) via the `/godmode` v4.1.0 release execution
**Date:** 2026-05-27

## Status: HOLD — environmental blocker

The v4.1.0 Extended Test Suite and Manual Visual Regression Suite **could not be executed against the dev WordPress instance** because the dev instance is still running plugin **v4.0.7** — the v4.1.0 release has not been deployed to `$WP_TESTING_URL`.

Continuing the runbook would re-verify v4.0.7 behavior under a v4.1.0 label, which is precisely the anti-pattern flagged in the brief (PR #518 — confusing "pre-existing" with "introduced by current release"). Sign-off is withheld pending a v4.1.0 deploy to the dev site.

## Environment verification

| Check | Result | Evidence |
|---|---|---|
| `make test-extended-verify` (`testing/extended/verify_env.py`) | PASS | 24/24 required env vars; password-integrity check; SSH + docker + wp-cli; IMAP login; SMTP login (STARTTLS + AUTH) |
| Pre-flight cleanup (`testing/extended/cleanup.py --confirm`) | PASS | 0 stale tickets, 12 stale test-mailbox messages expunged |
| Git branch state | OK | `dev` @ `12083be` matches Gate 2 merge commit |

## Deployed plugin version on dev (the blocker)

```
$ wp plugin get simple-wp-helpdesk --field=version
4.0.7

$ head simple-wp-helpdesk.php  (file deployed to dev)
Version: 4.0.7

$ ls includes/rest-api  (on dev)
(does not exist — v4.1+ would have one)

$ git status  (in the deployed plugin dir on dev)
not a git checkout — plugin is deployed as a copy, not a git clone
```

The deployment on dev is **not** a git checkout that tracks `dev`. It is a file-copy install of the v4.0.7 bundle. There is no automated `dev → dev-site` deploy step documented in `planning-and-release-process.md` or `CLAUDE.md`, so this update is a manual operator step.

## What this blocks

| Section | Why it can't run on v4.0.7 |
|---|---|
| **8.5 — REST API smoke** (the v4.1.0-specific section the brief explicitly called out) | Endpoints `/wp-json/swh/v1/tickets` and `/wp-json/swh/v1/openapi.json` are not registered in v4.0.7. |
| Visual regression — Settings → Integrations → REST API keys table + Generate form | Section does not exist in v4.0.7. |
| Visual regression — Generate Key modal (shown-once secret) | Modal does not exist in v4.0.7. |
| Visual regression — Revoke Key confirmation modal | Modal does not exist in v4.0.7. |
| Visual regression — Tools → Helpdesk API Reference (Redoc viewer) | Page does not exist in v4.0.7. |
| Visual regression — API Access meta box on ticket editor | Meta box does not exist in v4.0.7. |

All other sections (1–8, 9 cleanup) could in principle run today, but doing so would only produce a v4.0.7 re-sign-off; the v4.0.7 sign-off already exists at `releases/v4.0.7/` (visual baseline present; no rc-signoff.md found). Running the rest of the runbook against v4.0.7 and labelling it "v4.1.0 RC sign-off" would be misleading and is exactly the failure mode the brief warned against.

## Extended Test Suite

| Section | Description | Result |
|---|---|---|
| 0 | Environment verification | **PASS** |
| Pre-flight | `make test-extended-cleanup CONFIRM=1` | **PASS** (0 tickets, 12 mailbox messages purged) |
| 1 | Authentication and access control | **NOT RUN** — would verify v4.0.7 behavior, not v4.1.0 |
| 2 | Inbound-email webhook | **NOT RUN** — same |
| 3 | Outbound email deliverability | **NOT RUN** — same |
| 4 | Portal flow | **NOT RUN** — same |
| 5 | Admin inbox surfaces (incl. 5.2 rewrite from #504) | **NOT RUN** — same |
| 6 | Settings (10 tabs) | **NOT RUN** — same |
| 7 | Reports + dark mode | **NOT RUN** — same |
| 8 | Accessibility spot-check | **NOT RUN** — same |
| **8.5** | **REST API happy-path smoke (v4.1+)** | **BLOCKED — feature absent on dev** |
| 9 | Post-run cleanup | (will run after the real RC sweep) |

**Total:** 1 PASS / 0 FAIL / 9 NOT RUN / 1 BLOCKED.

## Manual Visual Regression

Not captured. The directory `releases/v4.1.0/visual-regression/` was scaffolded (settings/, admin/, portal/, darkmode/, api/) but no screenshots were taken; the v4.1.0-new surfaces would be missing and the rest would only duplicate the existing `releases/v4.0.7/visual/` baseline.

**Total:** 0 captured.

## Issues found

| # | Severity | Title | Notes |
|---|---|---|---|
| 1 | **blocker (release process)** | Dev WP not updated to v4.1.0 before Gate 3 verification | The runbook assumes `$WP_TESTING_URL` is running the candidate build. Neither `planning-and-release-process.md` nor `extended-test-plan.md` documents the deploy step from `dev` → dev WP. Recommend adding a step under "Gate 3 — `dev → main`": _"Before running the Extended Test Suite, deploy the current `dev` branch's plugin bundle to `$WP_TESTING_URL`. Confirm via `wp plugin get simple-wp-helpdesk --field=version` that the deployed version matches the candidate."_ |
| 2 | non-blocker (tooling) | `Makefile` extended-test targets invoke `python3` directly | `testing/extended/*.py` need `paramiko`, which is installed only in `testing/.venv/`. Running `make test-extended-verify` from a clean shell fails with `ModuleNotFoundError: No module named 'paramiko'`. Worked around by invoking `./testing/.venv/bin/python` directly. Recommend the Makefile target use `testing/.venv/bin/python` (or document the activation step explicitly). |
| 3 | non-blocker (tooling noise) | wp-cli emits PHP 8.3 deprecation noise on every call | `Deprecated: Case statements followed by a semicolon …` from `react/promise` in the wp-cli phar. Confirmed in `_wpcli.py` — the WP_CLI_PHP_ARGS env override is set but only takes effect inside `docker exec -e …`; some invocations bypass it. Cosmetic only; affects log readability of scripted sections. |

## Gate 3 recommendation

**HOLD.** The Gate 3 prerequisites (Extended Test Suite + Manual Visual Regression Suite per `planning-and-release-process.md` § Gate 3) cannot be satisfied while dev runs v4.0.7. Required next steps before re-attempting RC sign-off:

1. Build the v4.1.0 plugin bundle from `dev` HEAD (`make js-build` + zip per release process).
2. Deploy it to `$WP_TESTING_URL` (replace the file-copy install, or convert that directory to a tracked checkout).
3. Confirm `wp plugin get simple-wp-helpdesk --field=version` returns `4.1.0`.
4. Re-run `make test-extended-verify` then continue this runbook from Section 1.

If the deploy step is expected to happen elsewhere (e.g. via the GitHub auto-updater after the release tag is pushed), then the **release-process documentation needs to clarify that Gate 3 verification happens on a separate staging install, not on `$WP_TESTING_URL`** — currently the runbook explicitly targets `$WP_TESTING_URL`, which makes that interpretation contradictory.
