# Simple WP Helpdesk v4.1.0 — release artifacts

The release ZIP (`simple-wp-helpdesk-v4.1.0.zip`) and SHA256 checksum
file (`simple-wp-helpdesk-v4.1.0.zip.sha256`) for this release are
built and attached by the GitHub `release.yml` workflow on `v4.1.0`
tag push (Phase 12 of the v4.1.0 plan).

## What shipped

**v4.1.0 — Event Surface & REST API** (2026-05-27)

Public REST API v1 (`swh/v1`) covering tickets CRUD, replies,
technicians, categories, and a curated public-settings allowlist;
scoped, bcrypt-hashed, revocable API keys with per-key rate-limit
overrides; per-key + per-IP rate limiting with `X-RateLimit-*`
headers; OpenAPI 3.1 contract served at
`GET /wp-json/swh/v1/openapi.json` plus an admin Tools → Helpdesk API
Reference page rendered through a vendored Redoc bundle (no CDN);
opt-in per-ticket API access audit log; 7 new Playwright pytest
sections (76–82) covering the REST surface end-to-end.

See the full release notes in [`CHANGELOG.md`](../../CHANGELOG.md) under
the `[4.1.0]` section.

## Install

Download `simple-wp-helpdesk-v4.1.0.zip` from the [GitHub Release
page](https://github.com/seanmousseau/Simple-WP-Helpdesk/releases/tag/v4.1.0)
and upload via **Plugins → Add New → Upload Plugin** in the WordPress
admin. Sites already running v4.0.x will be offered the update via the
in-dashboard updater (GitHub auto-updater).

## Operator docs

- API keys: <https://seanmousseau.github.io/Simple-WP-Helpdesk/api-keys>
- API reference: <https://seanmousseau.github.io/Simple-WP-Helpdesk/api-reference>
- Full doc site: <https://seanmousseau.github.io/Simple-WP-Helpdesk/>
