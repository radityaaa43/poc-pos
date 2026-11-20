# Domain docs

How the engineering skills consume this repo's domain documentation.

## Before exploring, read these

- **`GLOSSARY.md`** at the repo root (or `GLOSSARY-MAP.md` if it exists: it points at one
  `GLOSSARY.md` per context; read each one relevant to the topic).
- **`docs/adr/`**: ADRs that touch the area you're about to work in.

If a file doesn't exist, proceed silently; `/domain-modeling` (via `/grill-with-docs`) creates them
when terms or decisions get resolved.

## Use them

- Use glossary terms in code, docs, issues and output; don't invent synonyms.
- If your change contradicts an accepted ADR, say so explicitly and propose a superseding ADR;
  don't silently diverge.
