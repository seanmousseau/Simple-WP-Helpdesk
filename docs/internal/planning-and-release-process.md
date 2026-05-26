# Planning & Release Process

This is the **authoritative end-to-end process** for planning, implementing, and shipping a release of Simple WP Helpdesk. It is non-negotiable — every release follows it. If a step gets skipped, the release is paused until it is done.

It applies to every minor and patch release. Major releases (e.g. v5.0.0) may add additional steps but never remove these.

---

## Table of contents

1. [Feature & milestone planning](#feature--milestone-planning)
2. [Release kickoff](#release-kickoff)
3. [Release implementation](#release-implementation)
4. [Branching & PR methodology](#branching--pr-methodology)
5. [PR & merge gates](#pr--merge-gates)
6. [Release gate](#release-gate)
7. [Non-negotiable rules](#non-negotiable-rules)
8. [Routing — when to read which doc](#routing--when-to-read-which-doc)

---

## Feature & milestone planning

The goal of planning is to **know exactly what is shipping, why, and what it touches** before any code is written.

### Rules

- **Always check the code.** Never assume how something works, what already exists, or how a setting is wired up. Read the actual file. The plan is a derivative of reality, not a hypothesis about it.
- **One feature, one release** wherever practical. If a feature is too large to land in one release, split it deliberately:
  - Open a GitHub issue for each outstanding chunk and **tag it against a future milestone**.
  - Note the split in the current release plan with a forward link to the follow-up issues.
- **Stick to a theme per milestone** where practical. Examples: "Reporting expansion", "REST API surface", "Portal v3". Bundling small bug fixes or unrelated polish is fine; bundling unrelated large features is not.
- **Keep releases manageable.** A release should be reviewable in a single sitting. If it isn't, split it.
- **All roadmap ideas need user approval before they are documented.** Free-floating "I think we should add X" notes do not belong in `roadmap.md`, the per-version roadmap, or any milestone description. Brainstorm in `new_features_discussion.md` first; promote on approval only.
- **Fit-for-project gate.** Before suggesting any feature, ask: does this fit Simple WP Helpdesk's identity (lightweight, native WP, no third-party services required, no custom DB tables)? If unsure, ask the user before adding it to any plan.

### Outputs of the planning phase

A milestone is "planning complete" when:

- Every issue in the milestone is scoped and has acceptance criteria.
- Every issue is sized (small / medium / large is enough — formal points aren't required).
- A theme or short description is on the milestone itself in GitHub.
- The roadmap (`roadmap.md`) reflects this milestone's position relative to others.
- The user has explicitly approved the scope.

---

## Release kickoff

The kickoff converts an approved milestone into an executable plan.

### Steps

1. **Review the relevant milestone** in GitHub. Read every issue tagged to it end-to-end, not just the title.
2. **Verify the previous milestone shipped completely.** Pull up the immediately prior milestone:
   - Are all its issues closed?
   - If any are still open, decide — with the user — whether to fold them into the current release or push them out.
   - Open issues that were silently dropped are a planning failure; surface them.
3. **Review the full open issue and milestone list.** Prioritize and re-sequence as needed. Specifically:
   - **Re-milestoning** an issue requires user approval.
   - **Postponing** an issue requires user approval.
   - **Scope reduction** on an issue requires user approval.
   - **Scope rewriting** an issue (changing what "done" means) requires user approval.
4. **Confirm release scope with the user.** This is the explicit gate before plan writing starts.
5. **Write the plan using `superpowers:writing-plans`.** The plan must include, at minimum:
   - **Per-PR/step breakdown** of every issue and feature in the release, grouped by the PR/step that will deliver it.
   - **Implementation detail per step** — files to be touched, new files to be added, classes/functions to add or modify, data-model implications.
   - **Documentation updates required** — both internal (`docs/internal/`) and end-user (`docs/`). Name every doc that needs a touch. CLAUDE.md, README.md, CHANGELOG.md, SECURITY.md, CONTRIBUTING.md updates if applicable.
   - **Test-suite updates** — every new feature, every functionality change, and every bug fix must come with test updates across **all relevant suites**:
     - PHP test suite (`make phpunit` / `make test-docker`)
     - E2E test suite (`make e2e-docker`)
     - Extended test suite (`docs/internal/extended-test-plan.md` runbook)
     - Manual visual regression suite (`docs/internal/visual-regression-suite.md`)
6. **Surface every open question** that came up during planning. Plan with questions in it is not a finished plan.
7. **Present the plan to the user for approval.** **Do not start implementation without approval.**

The plan lives under `docs/internal/` for the duration of the release cycle. After the release ships, it is archived to `docs/internal/archive/`.

---

## Release implementation

### How implementation runs

- **Prefer subagent-driven development.** The `superpowers:subagent-driven-development` skill, the `superpowers:dispatching-parallel-agents` skill, and the project's plugin agents (`pr-review-toolkit:*`, `feature-dev:*`) exist for this. Use them.
- **Brief subagents well.** Subagents start cold. Give them:
  - The exact files they need to touch and why.
  - The acceptance criteria for "done".
  - Pointers to the relevant internal doc (contract doc, design doc, security model).
  - The test suites they need to update.
- **Verify what subagents deliver.** Their summary describes intent. Read the diff before declaring the step complete.
- **Use the right skill per task:**
  - Plans: `superpowers:writing-plans`.
  - UI/UX work: `ui-ux-pro-max`.
  - Documentation: built-in `documentation` skill where applicable.
  - Debugging: `superpowers:systematic-debugging`.
  - Pre-completion verification: `superpowers:verification-before-completion`.
  - PR review: `pr-review-toolkit:review-pr`, `coderabbit:*`.
- **Use LSPs, MCPs, claude-mem and Memory MCP** wherever they reduce friction. See the global `~/.claude/CLAUDE.md` for Memory MCP entity-naming conventions.

### Memory MCP discipline

- At the start of every implementation session: `open_nodes(["user:sean"])` + `search_nodes("project:simple-wp-helpdesk")`.
- Before touching code on a new issue: create or load the `project:simple-wp-helpdesk:bug:<slug>` (or `:epic:<slug>`) entity.
- After every meaningful change: `add_observations` with a one-line fact, files touched, commit hash, test results.
- When work closes out: final observation with the closing commit / tag / bundle SHA256.

---

## Branching & PR methodology

```text
Feature/Step Branch 1 ─┐
Feature/Step Branch 2 ─┤── PRs to ──► Release Branch (release/vX.Y.Z)
Feature/Step Branch 3 ─┤
       …               │
Feature/Step Branch N ─┘
                                            │
                                            ▼
                                          dev
                                            │
                                            ▼
                                          main ──► tag vX.Y.Z ──► release
```

### Branch types

| Branch | Purpose | Naming |
|---|---|---|
| `main` | Always shippable. Tagged releases live here. | — |
| `dev` | Integration branch. RC validation runs here. | — |
| `release/vX.Y.Z` | Per-release integration branch. PRs from all feature/step branches land here. Killed after the release ships. | `release/v4.0.7` |
| Feature/step branch | One per PR. Implements a single step from the plan. | `feat/<slug>`, `fix/<slug>`, `chore/<slug>`, `docs/<slug>` |

### Flow

1. Cut `release/vX.Y.Z` from `dev` at the start of the cycle.
2. Each feature/step branch is cut from `release/vX.Y.Z` and PRs back into it when complete.
3. After every planned step has merged into the release branch, the release branch PRs into `dev`.
4. `dev` PRs into `main`.
5. After `main` merge, tag `vX.Y.Z`. The release workflow builds the bundle and publishes the GitHub Release.

---

## PR & merge gates

Every gate is **non-negotiable**. A gate that hasn't been met means no merge — full stop.

### Gate 1 — Feature/step branch → `release/vX.Y.Z`

1. Local test suites pass — at minimum **PHP suite** (`make test-docker`) and **E2E** (`make e2e-docker`).
2. All CI failures and PR comments addressed (including CodeRabbit — see [non-negotiables](#non-negotiable-rules) on push-back).
3. Documentation and test updates for this step are in the PR.

→ Merge when satisfied.

### Gate 2 — `release/vX.Y.Z` → `dev`

1. All feature/step branches have merged into the release branch.
2. All documentation and test-suite updates for the release are complete and on the release branch.
3. Release bundle has been created and is on the release branch (or in `releases/vX.Y.Z/`).
4. All CI failures and PR comments addressed.
5. Local test suites pass — PHP and E2E.
6. **Functional review:** confirm everything was delivered, nothing was missed, what landed makes sense and works.
7. **Documentation review:** confirm every relevant doc (internal, end-user, CLAUDE.md, README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, etc.) is updated, accurate, and coherent.
8. **Test-suite review:** confirm tests were added for every new feature, every functionality change, and every bug fix.
9. **User approval to merge granted.**

→ Merge when satisfied.

### Gate 3 — `dev` → `main`

1. Release branch has merged into `dev`.
2. Local test suites pass, **including the Extended Test Suite and Manual Visual Regression Suite**. (PHP + E2E gates were already enforced upstream; this gate is where the human-verified suites land.)
3. All CI failures and PR comments addressed.
4. **User approval to merge granted.**

→ Merge when satisfied.

---

## Release gate

1. Everything is on `main`.
2. **User approval to release granted.**

Only then: tag `vX.Y.Z`, push the tag, let the release workflow build and publish.

---

## Approval scope (important)

**Every "user approval" line in the gates above is its own decision.** A single approval — "merge", "ship it", "continue", "go ahead" — covers **one named action only**: the merge being asked about, or the tag push being asked about. It does **not** cascade to the next gate.

Specifically:

- Approval for `release → dev` merge does NOT imply approval for `dev → main`.
- Approval for `dev → main` merge does NOT imply approval for the release tag push.
- Approval to release does NOT imply approval to do anything else after the release.

After every approval-gated action, **pause and re-ask** before the next gated action — even when the path forward looks obvious. The gates exist because a result at one step (a CI failure, a visual-regression anomaly, a late finding) can change the decision at the next step. Inferring approval through a chain defeats the purpose of having multiple gates.

When in doubt, re-ask. Cost of an extra question is one line of text; cost of an inferred approval is a real process bug. See `lessons-learned.md` → "Per-gate approval is per-gate, not per-chain (v4.0.7 retrospective)" for the incident that codified this rule.

---

## Non-negotiable rules

These are bright lines. They apply at every stage above.

- **Don't make assumptions about the code.** Read it. This covers planning, issue writing, milestone writing, plan writing, plan execution, and development.
- **Scope and scope changes must be user-approved.**
- **Functionality changes must be user-approved.**
- **Nothing is deferred without explicit user approval.**
- **Deferred items must be documented.** Every deferred issue or scope item must have a GitHub issue tagged against a future milestone — unless the user explicitly agrees otherwise. If indefinitely deferred, it goes in `parked_features.md`.
- **If in doubt, ask the user.** Cheap question vs. expensive rework.
- **No shortcuts that create tech debt.** Do it right now rather than pay for it later.
- **Think through CodeRabbit findings.** Don't just blindly apply suggestions. If there is a valid reason to push back, reply with the reasoning. **Get user approval before pushing back.**
- **Testing is not optional.**
- **PR and merge gates are not optional.**
- **The release gate is not optional.**
- **Documentation and test-suite updates accompany all changes.**
- **PR and branch methodology must be followed.**
- **Reach for `ui-ux-pro-max` on every UI/UX design and implementation task.**
- **Plans go through `superpowers:writing-plans`** — that's the supported planning surface.
- **The documentation skill** should assist with doc creation and updates wherever it helps.
- **Other skills, LSPs, MCPs, `claude-mem`, and the Memory MCP** are first-class tools — lean on them wherever they reduce work.
- **The Memory MCP graph must stay current at every step** (see global `~/.claude/CLAUDE.md` for entity conventions).
- **Keep all documentation updated**, including:
  - End-user documentation (`docs/`)
  - Internal documentation (`docs/internal/`)
  - `CLAUDE.md`, `README.md`, `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md`
- **Archive outdated documentation** — move into an `archive/` folder in the same directory as the doc. Don't delete; future context is valuable.

---

## Routing — when to read which doc

| You are about to … | Read first |
|---|---|
| Kick off a release | This document, the relevant `archive/release_vX.x.x_roadmap.md` (predecessor) and the current per-version roadmap, `roadmap.md` |
| Brainstorm a new feature | `new_features_discussion.md` (get user approval before promoting to `roadmap.md` or any milestone) |
| Write a plan | `superpowers:writing-plans` + this document's [planning](#feature--milestone-planning) section |
| Touch a major functional area | The matching contract doc in `docs/internal/contract-*.md` |
| Add a setting / option | `config-reference.md`, `data-dictionary.md` |
| Add a hook | `api-contract.md`, end-user `docs/hooks.md` |
| Touch the inbound webhook | `contract-inbound-webhook.md`, end-user `docs/inbound-webhook.md`, `security-model.md` |
| Touch security-sensitive code | `security-model.md` |
| Touch UI | `design-guide.md`, `component-inventory.md`, `accessibility.md`, `ui-ux-pro-max` skill |
| Add/modify a test | `testing-overview.md` → the relevant per-suite doc |
| Add a dependency | `dependencies.md` |
| Localize a new string | `i18n.md` |
| Defer something | Open a GitHub issue against a milestone, or add to `parked_features.md` if indefinitely deferred |

---

## Living references

This is a living document. When the process changes, update it here first — then update CLAUDE.md to point at the new version. Outdated copies of this process anywhere else are bugs.
