---
title: REST API
nav_order: 7
---

# REST API

Simple WP Helpdesk exposes a small REST surface under the `/wp-json/swh/v1/` namespace. This page is the operator- and integrator-facing reference.

> **Status (v4.0.x):** the public REST surface is currently limited to the **inbound email webhook** documented below. A full ticket / reply / settings API with scoped key authentication is planned for **v4.1.0** — see the [roadmap on GitHub](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/docs/internal/roadmap.md). This page will be expanded as those endpoints land.

---

## Available endpoints

| Method | Path | Purpose | Status |
|---|---|---|---|
| `POST` | `/wp-json/swh/v1/inbound-email` | Accept replies from a mail provider's inbound webhook | **Stable (v3.0+)** |

Detailed endpoint references follow.

---

## `POST /wp-json/swh/v1/inbound-email`

Converts an inbound email reply into a `helpdesk_reply` comment on the matching ticket. The full setup guide, payload format, provider examples (Mailgun / SendGrid / Postmark), and troubleshooting live on the dedicated page:

**→ [Inbound Email Webhook](inbound-webhook)**

Quick summary:

- **Auth:** `Authorization: Bearer <secret>` where `<secret>` matches **Tickets → Settings → Integrations → Webhook Secret**.
- **Body:** form-encoded or JSON. Must contain `subject` (with `[TKT-XXXX]`), `body-plain` (or `text`), and `sender` (or `from`).
- **Returns:** `200 {"success":true}` on accept; `200 {"success":false,"error":"..."}` for soft failures; `401`/`503` for auth/config errors.

---

## Coming in v4.1.0

Per the [project roadmap](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/docs/internal/roadmap.md), v4.1.0 will introduce a full **public REST API** with:

| Endpoint group | Purpose |
|---|---|
| `GET/POST/PATCH/DELETE /wp-json/swh/v1/tickets[/<id>]` | Tickets CRUD |
| `GET/POST /wp-json/swh/v1/tickets/<id>/replies` | Replies on a ticket |
| `GET /wp-json/swh/v1/technicians` | Technician roster (read-only) |
| `GET /wp-json/swh/v1/categories` | Categories taxonomy (read-only) |
| `GET /wp-json/swh/v1/settings` | Public-safe settings (read-only) |

Authentication will use **scoped API keys** managed in **Settings → Integrations** (read-only, read-write, admin scopes), with **per-key rate limiting** (default 60/min, configurable) and **per-endpoint capability checks**. An OpenAPI 3.1 spec will be available under a hidden admin page.

When v4.1 ships, this page expands to cover each endpoint: method, path, parameters, response shape, status codes, scope requirements, rate-limit headers, and example cURL invocations.

---

## Stability guarantees

The endpoints listed under "Available endpoints" are **stable for the v4.x line**. Specifically:

- Paths and methods will not change without a major version bump.
- Response shapes will not change in a backwards-incompatible way.
- Authentication mechanisms will not be removed or weakened.
- Deprecations follow the [Deprecation Policy](developer/deprecations) — minimum two-minor-release notice before removal.

The `/wp-json/swh/v1/inbound-email` endpoint specifically has this guarantee since v3.0.

---

## Reporting issues

REST-specific issues — wrong status codes, missing headers, payload shape problems — go through the standard [issue tracker](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues). Security issues with the REST surface (auth bypass, capability escalation, etc.) follow the [Security Policy](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/SECURITY.md) — please report privately via GitHub Security Advisories.

---

## See also

- [Inbound Email Webhook](inbound-webhook) — the only currently-stable endpoint, in detail
- [Security](security) — token auth model, anti-spam, capability checks
- [Hooks Reference](hooks) — PHP hooks for in-process integration (no REST round-trip)
