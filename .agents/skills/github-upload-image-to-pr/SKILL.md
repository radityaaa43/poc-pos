---
name: github-upload-image-to-pr
description: >-
  Attach local screenshots or videos to a GitHub PR or issue with `gh --attach`. Use after the
  manual test of a UI change, to put its before/after screenshots in the PR (AGENTS.md → UI work),
  and whenever someone asks to attach, add, embed or show an image, screenshot or recording in a PR,
  issue or comment.
---

# Attach screenshots to a PR

Recent `gh` releases upload images and videos with `--attach` on `gh pr create`/`edit`/`comment`
and `gh issue create`/`edit`/`comment`. That is the only route here: no browser, no install.

## Before you upload

1. **Check `gh`:** `gh pr create --help | grep -- --attach` must print the flag. If not, stop and ask
   the user to upgrade `gh`. Don't install anything yourself or upload through a browser.
2. **Look at every image.** Uploading publishes it to everyone who can see the repo. Dummy data
   only: no personal data, secrets or tokens (`AGENTS.md` → Never).
3. **Keep the files out of the repo.** Save them in a gitignored folder or a
   temp dir. `--attach` reads any path, so never copy them into the repo.

## Attach

Take the before shot on the default branch and the after shot on your branch, both as the affected
role. Put them in a table when you open the PR; `gh` swaps each local path in the body for the
uploaded URL. The path in the body must point at the same file as the `--attach` argument:

```bash
gh pr create --title '...' --body-file pr.md --attach shots/before.png --attach shots/after.png
# pr.md contains, under Manual test:
# | Before | After |
# |---|---|
# | ![before](shots/before.png) | ![after](shots/after.png) |
```

Other cases:

```bash
gh pr edit <n> --attach 'shots/after.png#Sidebar after the fix'   # append to the PR body
gh pr comment <n> --attach shots/run.webm                          # a later screenshot or recording
```

- `--attach` without `--body` appends to the existing body. `--body`/`--body-file` replaces the whole
  body, so build it from `gh pr view <n> --json body -q .body` first.
- Re-running appends duplicates. To replace an image, rewrite the body without the old URL in the
  same command that attaches the new one.
- A `#` in a filename is read as the alt-text separator; copy the file to a plain name first.
- Needs your own `gh auth login` with push access; the Actions token is refused.

## Check

```bash
gh pr view <n> --json body -q .body | grep -c user-attachments/assets
```

The count must match the number of files you attached.
