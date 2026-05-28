# Security Policy

## Reporting a vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Use [GitHub Security Advisories](https://github.com/seanmousseau/Simple-WP-Helpdesk/security/advisories/new) to report vulnerabilities privately. This keeps the details confidential until a fix is released.

If GitHub Security Advisories are unavailable to you, email <sean@seanmousseau.com> with the subject `SWH security:` and the details below.

---

## What to include

- A description of the vulnerability and its potential impact.
- Steps to reproduce (proof-of-concept code or a minimal scenario is ideal).
- Affected versions of Simple WP Helpdesk, WordPress, and PHP.
- Any suggested remediation, if you have one.
- Whether you'd like public credit for the disclosure once the fix ships.

---

## What to expect

| When | What |
|---|---|
| Within 48 hours | Acknowledgement of the report |
| Within 7 days | Initial triage — confirmed / not reproducible / out of scope, with rationale |
| Within 30 days | A fix released for confirmed vulnerabilities at High severity or above, or a written ETA if more time is needed |
| At fix release | Coordinated disclosure: advisory published, CVE requested where applicable, credit given (unless you opt out) |

We will keep you informed at each step.

---

## Severity

We use the CVSS 4.0 base score to triage:

| Range | Action |
|---|---|
| 9.0–10.0 (Critical) | Out-of-band patch release within 7 days where possible |
| 7.0–8.9 (High) | Patch in the next scheduled release; expedited if the fix is small |
| 4.0–6.9 (Medium) | Rolled into the next minor release |
| 0.1–3.9 (Low) | Tracked publicly; fixed when convenient |

---

## Scope

In scope:

- Anything in the `simple-wp-helpdesk/` plugin directory shipped on `main`.
- The inbound email webhook endpoint and any other REST/AJAX endpoint we register.
- The public REST API v1 surface (`swh/v1`) — bearer auth, scope checks, rate limiting, public-settings allowlist.
- The API key management surface — storage, generation, revocation, plaintext lifecycle.
- The token-based portal authentication and download proxy.
- Anti-spam handling, rate limiting, and CSRF (nonce) gating.
- File upload validation and the protected uploads directory.

### API key vulnerabilities

API keys (v4.1.0+) are stored as **bcrypt hashes** (cost 12); the plaintext is shown once at generation and is never persisted. Keys carry an explicit scope (`read` or `read+write`) and can be revoked at any time from Settings → Integrations → API Keys, which invalidates them immediately on the next request. The REST surface enforces per-key and per-IP rate limits, and never reveals secret settings (the public-settings endpoint is gated by an explicit allowlist). If you suspect a key has been compromised or you can demonstrate a bypass of any of these protections, report it via the channels above and treat it as a high-severity finding.

Out of scope:

- Issues in dependencies we don't bundle (e.g. WordPress core, your hosting environment, third-party plugins).
- Issues that require the attacker to already have administrator-level WordPress access.
- Social-engineering attacks against operators or end users.
- Theoretical attacks without a working proof of concept.

---

## Hardening references

For background on the security model, capability matrix, and threat model, see [`docs/internal/security-model.md`](docs/internal/security-model.md).
