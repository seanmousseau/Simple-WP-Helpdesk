# Parked Features (living document)

Features that were on a roadmap (or seriously considered) and have been intentionally deprioritized. Not cancelled — parked. Each entry says **why it's parked** and **what would have to change** to bring it back.

Last updated: 2026-05-04

---

## Zapier app

**Originally:** v4.3.0 — issue [#382](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/382) (closed 2026-05-04, "not planned").
**Parked:** 2026-05-04.

### Why parked

- Maintainer (Sean) doesn't use Zapier and has no internal pull for it.
- Separate repo + Zapier platform compliance is a meaningful maintenance surface (auth flows, schema drift, listing reviews).
- The v4.1 REST API + v4.2 signed webhooks cover the same automation use cases for users on Zapier, Make, n8n, IFTTT, or anything custom.
- A community-built Zapier wrapper can sit on top of the public API later without us owning it.

### What would bring it back

- Real, repeated user requests (not "would be nice" — actual people asking).
- A community contributor willing to own the Zapier-side code.
- Direct revenue tie (e.g. paid tier where Zapier listing is a meaningful acquisition channel).

### Successor / alternative

v4.1 REST API ([#362](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/362)–[#370](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/370)) + v4.2 webhooks ([#371](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/371)–[#378](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/378)).

---

## Candidates to consider parking

These are still on the roadmap but worth a deliberate keep/park decision before they land. Not parked yet — listed here so the question is in front of us.

### Indigo design refresh ([#355](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/355)) — RESOLVED

**Decision (2026-05-22):** Option A — shipped as opt-in in v4.0.0 as the **Violet theme**. Setting: `swh_settings_general.use_violet_theme` (default false). Palette: Vivid Violet (violet-800 primary, Geist Sans typography). Scope: admin + portal; emails out of scope.

See:
- Design spec: `docs/superpowers/specs/2026-05-22-v4.0.0-violet-theme-design.md`
- Implementation plan: `docs/superpowers/plans/2026-05-22-v4.0.0-violet-theme.md`
- CHANGELOG entry under v4.0.0

### Microsoft Teams integration ([#380](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/380))

- Adaptive Cards format is heavier than Slack/Discord webhooks.
- Smaller user overlap (WP + Teams shop) than Slack.
- **Option A:** keep — completionist (cover the big three).
- **Option B:** park — ship Slack + Discord first, add Teams if requested.

### Discord webhook ([#381](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/381))

- Same question as Teams from a different angle. Discord users are a niche segment for a WordPress helpdesk.
- **Option A:** keep — easy lift on top of the v4.2 webhook layer.
- **Option B:** park — community can build it via the public webhook API.

---

## Format

Each parked entry should answer:
1. **What it was** — milestone + issue link + date parked.
2. **Why parked** — concrete reasons, not "we'll get to it."
3. **What would bring it back** — the trigger conditions for un-parking.
4. **Successor / alternative** — what users do instead.

Don't park things silently. If something gets dropped from a milestone, it lands here with reasoning, even if the answer is "no real demand yet."
