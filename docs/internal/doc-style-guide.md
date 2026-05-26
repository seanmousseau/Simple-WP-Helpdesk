# Documentation Style Guide

How we write documentation in this project — voice, structure, formatting, when to use tables vs prose. Applies to **every** doc in `docs/` and `docs/internal/`.

The goal: docs that a smart contributor can read cold and trust. Short. Specific. Reviewable.

---

## Voice

- **Direct, second-person ("you").** Not "the user", not "one should". The reader is the audience; address them.
- **Imperative for instructions.** "Run `make test-docker`" not "You should run `make test-docker`" not "The test suite can be run by executing `make test-docker`".
- **Plain English.** Prefer "before" over "prior to", "use" over "utilize", "set up" over "configure" (unless "configure" is more precise in context).
- **No marketing voice.** Don't sell. Don't say "blazing-fast" or "seamlessly". State what is true.
- **No hedging.** If you're sure, say it. If you're not sure, say "as of v4.0.6" or "this may change" and move on.

### Honesty over salesmanship

Document gaps as gaps. Document limitations as limitations. The "What we don't do (yet)" section in `accessibility.md` is the model — explicit about scope, lists what's deferred, links to where the tracking lives.

---

## Structure

### Hierarchy

- One `<h1>` per file. Matches the file's title.
- `<h2>` for top-level sections. Most files need 4–8 of these.
- `<h3>` for subsections inside an `<h2>`. Use sparingly — if you reach `<h4>` consider splitting the section.
- Never skip a heading level.

### Required sections by doc type

| Doc type | Required sections |
|---|---|
| Contract docs (`contract-*.md`) | Surface · Invariants · Failure modes · Routing |
| End-user pages (`docs/*.md`) | Lead paragraph · Step-by-step or reference body · Examples · See also (where applicable) |
| ADRs (`adr/NNNN-*.md`) | Context · Decision · Consequences · Alternatives considered |
| Test-suite docs | Prerequisites · How to run · What's covered · Failure modes |
| Roadmap / planning | Now · Next · Later · Watching · Explicitly not doing |

### Length

- A doc that's hard to scan in 60 seconds is too long. Split it.
- An ADR over 400 words is suspicious. The decision should be precise; if it's not, the decision isn't fully baked.
- A contract doc should fit in 250 lines. Above that, the contract is probably blurring two concerns.

---

## Tables vs prose

Default to tables when:

- Comparing 3+ items along the same axes.
- Listing key/value pairs with consistent shape (option names, hook signatures, status codes).
- Describing a routing decision ("If you're touching X, read Y").

Default to prose when:

- Explaining a single concept or sequence.
- Walking through a flow that has prerequisites.
- Justifying a decision (tables flatten nuance).

A table with two rows is almost always better as prose. A bullet list with 12 items is almost always better as a table.

---

## Code blocks

- **Always fence with three backticks.** Never use indented code blocks.
- **Always specify the language**: `php`, `js`, `bash`, `yaml`, `apache`, `text`. The `text` fence is for "command output", "subject lines", etc.
- **One language per block.** Don't mix PHP and HTML inside the same block unless the snippet genuinely runs as both (templates, etc.).
- **Show the prompt for shell commands.** Use `$` for unprivileged shells, `#` for root, or leave the prompt off if it's a single command. Be consistent within a doc.
- **Comment with intent, not narration.** `// Stop the SLA timer in PagerDuty.` not `// This line stops the SLA timer.`

---

## Links

- **Use relative links** within the repo: `[security-model.md](security-model.md)`, not the full GitHub URL.
- **Use absolute HTTPS links** for external references (WP Codex, vendor docs, RFCs).
- **Link the first mention of a concept.** Don't link every subsequent mention in the same doc — it's noise.
- **Link policy docs by intent, not by name.** Prefer "see [the planning process](planning-and-release-process.md)" over "see planning-and-release-process.md".

---

## Naming references

- **File paths in `code formatting`** — `simple-wp-helpdesk/includes/helpers.php`.
- **Function / class names in `code formatting`** — `swh_set_ticket_status()`, `SWH\Email\Mailer`.
- **Settings labels in bold** — **Tickets → Settings → Integrations**. Use the en dash (`→`), not the ASCII arrow (`->`).
- **Keyboard combos** — `Cmd+S` / `Ctrl+S`. Bracket multi-key sequences: `Cmd+Shift+P`.
- **Hostnames and URLs in code formatting** — `https://dev-direct.seanmousseau.com`.

---

## Callouts

Three flavors. Use sparingly.

```markdown
> **Important:** This one-liner is critical to know.
```

```markdown
> ⚠️ **Warning:** This action is destructive.
```

```markdown
> **Note:** Side fact worth knowing but not blocking.
```

A doc with five callouts has none. Pick the one or two things that actually warrant interruption.

---

## Lists

- **Numbered lists** for sequences (steps 1, 2, 3).
- **Bulleted lists** for unordered sets.
- **Nested lists** only when the nesting matters. Two levels max.
- **End list items with periods** when the item is a sentence. **Don't** when it's a fragment ("CSS class names", "WP user IDs").

Stay consistent within a doc.

---

## Time, versions, dates

- **Versions** as `vX.Y.Z` (full SemVer) or `v4.x` (a line). Never `4.0` or `4.0.0` without the `v` prefix in body text.
- **"In v4.0.6" or "as of v4.0.6"** when stating a current fact. **"In v4.1"** for forward-looking.
- **Dates** as `YYYY-MM-DD` in ADRs, planning artifacts, and observation logs. Prose may use "May 2026" when precision isn't needed.
- **Never** "the latest version" — that decays. State the version.

---

## Cross-doc consistency

When the same fact appears in multiple docs, **link to the authoritative one**. Don't restate it.

| Fact | Authoritative source |
|---|---|
| Every option's default | `config-reference.md` |
| Every meta key | `data-dictionary.md` |
| Every public hook signature | `api-contract.md` + end-user `docs/hooks.md` |
| Load-bearing invariants | `design-document.md` (linked from CLAUDE.md hot-list) |
| Terminology | `glossary.md` |

If you find yourself restating the same fact in three docs, two of those docs should be links.

---

## When updating

When you touch a doc:

- [ ] Read the doc end-to-end once before editing. Don't make surgical edits inside a doc you haven't reviewed.
- [ ] Update version references if the doc was written for an older version.
- [ ] Check that every cross-link still resolves.
- [ ] If you added a new doc, add it to `internal/README.md` index + routing table.
- [ ] If the change affects the routing table in `CLAUDE.md`, update that too.

---

## When archiving

Outdated docs go to an `archive/` subfolder in their own directory. **Don't delete** — git history is hard to mine for context. Move:

- Shipped per-version planning docs after the release tags.
- Discovery documents after the work they motivated has merged.
- ADRs only when superseded by a newer ADR — and add a status banner pointing to the replacement.

---

## Pitfalls to avoid

- **"This is documented in another doc."** If you're saying that, link to it. Don't make the reader hunt.
- **Code samples that don't compile.** Every PHP / JS / YAML sample should be syntactically valid. Test them.
- **Screenshots without alt text.** All `<img>` tags need `alt=`.
- **Tables of contents in short docs.** Anchors in markdown work fine; a manual ToC for a 150-line doc is noise.
- **Restating the obvious.** "Save the file to commit your changes." — yes, the reader knows.
- **Future-tense narration of past decisions.** "We will use comments for replies." No — we did. State the world as it is.

---

## A note on tone

This is a working codebase, not a marketing site. Be concise, be honest, be specific. The goal is that a smart contributor — or a smart AI agent — can read a doc cold, trust it, and act on it without needing to triangulate against the code.

When a doc fails that bar, fix the doc.
