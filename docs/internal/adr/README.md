# Architectural Decision Records

This directory captures **locked architectural decisions** for Simple WP Helpdesk. Each ADR records:

- The **context** that prompted the decision.
- The **decision** itself.
- The **consequences** — what it costs us and what it buys us.
- The **status** — proposed, accepted, superseded, deprecated.

ADRs answer the question: *"Why is the code shaped this way?"* When someone proposes reverting a decision in a year, the ADR is the record of why we picked the current shape over the alternatives.

---

## Format

Every ADR follows the same skeleton:

```markdown
# ADR-NNNN: <Short title>

- **Status:** Accepted | Proposed | Superseded by ADR-MMMM | Deprecated
- **Date:** YYYY-MM-DD
- **Deciders:** <names>

## Context
What forces are at play. What problem are we solving.

## Decision
The choice we made. One paragraph; precise.

## Consequences
What this costs (downsides, constraints, tech debt).
What this buys us (upsides).
Forward-looking implications.

## Alternatives considered
What else we looked at and why we rejected it.
```

---

## Numbering

ADRs are numbered sequentially. **Never reuse a number** — if an ADR is superseded, the new ADR gets a new number and references the old one.

| ID | Title | Status |
|---|---|---|
| [ADR-0001](0001-no-custom-db-tables.md) | No custom database tables | Accepted |
| [ADR-0002](0002-comments-as-reply-storage.md) | Use WP comments for ticket replies | Accepted |
| [ADR-0003](0003-github-for-updates.md) | Distribute plugin updates via GitHub releases | Accepted |
| [ADR-0004](0004-two-form-save-on-settings.md) | Two-form save pattern on settings page | Accepted |
| [ADR-0005](0005-bearer-auth-inbound-webhook.md) | Bearer-token auth on the inbound email webhook | Accepted |
| [ADR-0006](0006-token-only-portal-auth.md) | Token-only authentication for the client portal | Accepted |

---

## When to write a new ADR

Write one when:

- You're about to make an architectural choice with long-term consequences.
- You're about to revert or change a decision recorded in an existing ADR (write the new one; mark the old as superseded).
- A reviewer asks "why did we do it this way?" and there isn't an existing ADR to point at.

Don't write ADRs for:

- Day-to-day implementation choices (those go in PR descriptions).
- Coding-style preferences (those go in `coding-guide.md`).
- Process / release decisions (those go in `planning-and-release-process.md`).

ADRs are for **the small set of decisions that everything else hangs from**.

---

## Process

1. Copy the skeleton above into a new file `NNNN-short-slug.md`.
2. Write the ADR. Be concise — most fit in under 250 words.
3. PR it on its own. ADRs are easy to review and shouldn't ride along with implementation PRs.
4. Add the entry to the table above.
5. **User approval is required** before merging.
