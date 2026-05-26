# ADR-0005: Bearer-token auth on the inbound email webhook

- **Status:** Accepted
- **Date:** 2025-02-15 (back-dated)
- **Deciders:** Sean Mousseau

## Context

The inbound email webhook (`POST /wp-json/swh/v1/inbound-email`) accepts data from third-party email services (Mailgun, SendGrid, Postmark, etc.) and converts it into ticket replies. The endpoint must be authenticated — anyone who can POST to it can inject content into tickets.

The constraints:

- It must be callable from any mail provider's outbound webhook system. Providers vary in what they can send: some allow custom headers (Mailgun), some don't (Postmark out of the box, unless front-ended by a Worker).
- It must be testable locally without setting up a real mail provider.
- It must follow WP REST API conventions where possible.
- It must not require a WP user account for the caller (mail providers don't have logins).

Three viable mechanisms:

1. **HMAC signature** of the request body (`X-Webhook-Signature`).
2. **Bearer token** in the `Authorization` header.
3. **Shared-secret query parameter.**

## Decision

**Use a bearer token in the `Authorization: Bearer <secret>` header, compared with `hash_equals()`.**

- The secret is stored as the `swh_inbound_secret` WP option.
- An empty secret disables the endpoint entirely (returns `503 swh_not_configured`).
- Sender pinning is layered on top: even with a valid bearer, the email's `From:` address must match `_ticket_email` on the target ticket (also `hash_equals()` compared, lowercased on both sides).

## Consequences

### Costs

- **Apache may strip the `Authorization` header** before PHP sees it. Common on shared hosts. We document the `SetEnvIf` workaround in end-user troubleshooting; not all operators apply it correctly.
- **Providers that can't set custom headers** (Postmark unaltered) need a small relay (Cloudflare Worker, Lambda) to inject the header. We accept this — the documented examples cover Mailgun (works directly) and SendGrid (works directly), plus a relay recipe for Postmark.
- **Single secret per site.** No per-provider scoping. If a secret leaks, every provider is compromised until the operator rotates. (Acceptable for v4.x; v4.2's outbound-webhook keys are per-subscription and will inform a future per-channel inbound model.)

### Benefits

- **Simple to implement and reason about.** A 4-line check (header present? bearer format? `hash_equals`? non-empty?).
- **Testable.** A `curl -H 'Authorization: Bearer …'` against the endpoint replicates the production path exactly. The E2E suite uses this.
- **Constant-time comparison** is one function call. HMAC verification is more involved and easier to get wrong (which body bytes to sign, in which encoding, with which separators).
- **Sender pinning layers on top.** Even a leaked bearer can only inject content into existing tickets, and only as the email address the ticket was opened with.
- **Soft-vs-hard failure semantic** (200 with `success:false` vs 401) plays nicely with mail-provider retry policies. Documented in `contract-inbound-webhook.md`.

## Alternatives considered

- **HMAC signature of the body.** Stronger cryptographically (the body cannot be replayed-and-modified). Rejected because (a) providers vary wildly in how they format the body before signing — Mailgun signs the form-encoded body, Postmark signs the JSON, others vary — making "one HMAC verifier" much more code than "one bearer check"; (b) we don't have an authenticated-channel problem so much as an authentication problem; (c) the sender-pinning second layer addresses the "valid auth + wrong target" case.
- **Shared-secret query parameter.** Rejected. Query strings end up in webserver logs, browser history, referer headers, and proxy logs. Bearer headers don't (or at least, not by default).
- **WP application passwords.** Rejected. They're tied to a WP user account, which doesn't fit the "any mail provider" use case.

## Implementation invariants

These are restated in `contract-inbound-webhook.md`:

- Auth check uses `hash_equals()` against the bearer token. Constant-time.
- Empty secret → endpoint disabled (503).
- Sender pinning is non-optional. Even with valid auth, sender mismatch → soft failure.
- The handler emits its own 401 when the bearer is missing/wrong, rather than relying on the REST `permission_callback` (centralises the error code).

## Future evolution

When the v4.2 outbound webhook system lands (HMAC-signed event subscriptions with per-key delivery logs), the inbound side will get a similar upgrade: per-provider keys, signed payloads, audit log. That will likely be a new ADR superseding parts of this one — but only if it ships with materially better operator UX. The current bearer flow is the floor, not the ceiling.

## See also

- `contract-inbound-webhook.md` — full handler contract.
- `security-model.md` — overall trust model.
- End-user `docs/inbound-webhook.md` — operator-facing setup.
