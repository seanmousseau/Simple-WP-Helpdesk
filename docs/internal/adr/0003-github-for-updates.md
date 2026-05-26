# ADR-0003: Distribute plugin updates via GitHub releases

- **Status:** Accepted
- **Date:** 2024-09-01 (back-dated)
- **Deciders:** Sean Mousseau

## Context

WordPress plugins are conventionally distributed via the WordPress.org plugin directory, which provides automatic updates through the WP admin without any code in the plugin itself. Simple WP Helpdesk is **not** on the WordPress.org directory — partly by choice (avoiding the .org guidelines' constraints on bundled dependencies), partly by practicality (the .org review process is unpredictable and slow).

Operators still need painless updates. Self-hosted plugins that ask users to download a ZIP from a website and re-upload it for every patch are unmaintainable in practice. We need an update mechanism that's:

- Automatic (visible in the WP admin's standard update flow).
- Source-controlled (no separate "update server" infrastructure).
- Compatible with future ambitions like the WordPress.org directory if we ever change our mind.

## Decision

**Updates ship through GitHub Releases via [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker), bundled in `simple-wp-helpdesk/vendor/`.**

Initialization happens in the plugin bootstrap:

```php
PucFactory::buildUpdateChecker(
    'https://github.com/seanmousseau/Simple-WP-Helpdesk',
    SWH_PLUGIN_FILE,
    'simple-wp-helpdesk'
)->setBranch( 'main' )->getVcsApi()->enableReleaseAssets();
```

When a new release is published on GitHub, the library polls the GitHub API, parses the release metadata, downloads the attached `simple-wp-helpdesk.zip` asset, and surfaces it through the WordPress core update API. Operators see the standard "Update Available" notice and click once to update.

## Consequences

### Costs

- **One runtime dependency** that ships with the plugin (`yahnis-elsts/plugin-update-checker`). Listed in `dependencies.md`. We carry the cost of tracking its releases for security and compat.
- **Rate limit:** unauthenticated GitHub API requests are capped at 60/hour per IP. For most sites this is fine; busy hosts behind a shared IP could hit it. The library supports token auth for sites that need higher limits.
- **No `wordpress.org/plugins/` searchability.** New operators have to know about the GitHub repo to find us.
- **Private repos** would require an access token. The repo is currently public; if we ever switch, this needs revisiting.
- **Update payload is the GitHub release asset.** The release workflow must produce a valid ZIP (`simple-wp-helpdesk.zip`) and attach it — not just create a tag.

### Benefits

- **Zero new infrastructure.** GitHub releases are the source of truth.
- **Standard WP admin UX.** Operators don't learn a new flow.
- **The release tag is authoritative.** Tag, release, asset all live together in version control.
- **Easy to migrate.** If we ever do publish on .org, removing the update checker is a one-commit change.
- **Self-hosted operators stay self-hosted.** No call-home, no telemetry — the update check is to GitHub's public API.

## Alternatives considered

- **WordPress.org plugin directory.** Considered ongoing but rejected for now. Forces removal of bundled `vendor/` dependencies, restricts how the plugin can update, requires plugin-team review for every release.
- **Self-hosted update server.** Considered and rejected — adds infrastructure we don't want to run. Existing solutions (Freemius, EDD Software Licensing) exist but pull in heavy dependencies and rev models we don't need.
- **No auto-updater, manual ZIP only.** Rejected — operationally untenable for security patches.

## Implementation invariants

- The release workflow (`.github/workflows/release.yml`) must produce `simple-wp-helpdesk.zip` and attach it as a release asset on every `vX.Y.Z` tag push.
- The plugin header `Version:` and the `SWH_VERSION` constant must match the tag.
- The library lives at `simple-wp-helpdesk/vendor/plugin-update-checker/` — do not move it without updating the autoloader.

## See also

- `dependencies.md` — runtime dep tracking.
- `planning-and-release-process.md` — the release-gate procedure that ends in a tag push.
- `release_v4.x.x_roadmap.md` — current release status.
