---
name: nexus-spec-workflow
description: |
  Workflow for shipping work on the Nexus Control Center repo. Use whenever starting,
  continuing, or closing out a spec under specs/ — i.e. any feature or task that comes
  from nexus_control_center_roadmap.md. Walks through the issue → branch → PR cycle,
  spec file conventions, and the rule that spec-level (not task-level) issues are opened.
  Trigger phrases: "start spec NNN", "continue spec NNN", "next spec", "open a PR for spec",
  "close out spec NNN", or any reference to a file under specs/phase-N-*/NNN-*.md.
---

# Nexus Spec Workflow

Every spec under `specs/` is shipped as **one GitHub issue + one branch + one PR**. Tasks *within* a spec live as checklists in the spec markdown — they do **not** become separate issues.

Repo: `https://github.com/Copxer/nexus`. Default branch: `main`.

## When to use this skill

- Starting a new spec: "let's start spec 002", "begin spec 4 of phase 1", or whenever the user asks to implement something defined under `specs/`.
- Continuing in-flight work: "continue spec 002", "pick up where we left off".
- Closing out: "merge spec 002", "spec 002 is done".

If the user is just exploring the codebase, debugging, or making a one-off fix that isn't a spec, this skill is **not** needed.

## Starting a spec (the full flow)

1. **Read the spec file.** Confirm `status: not-started`. If it doesn't exist yet, draft it from `specs/_template.md` and confirm scope with the user before opening anything on GitHub.
2. **Open a GitHub issue.**
   - Title: `Spec NNN — <spec title>` (matches the spec markdown's `# NNN — <title>`)
   - Body: 2-3 line summary + a link to the spec file path (e.g. `specs/phase-0-foundation/002-auth-scaffolding.md`) + the acceptance criteria checklist copied from the spec.
   - Labels: `spec` and `phase-N` (where N is the phase number). Create labels lazily with `gh label create` if they don't exist yet.
3. **Create a branch** off the latest `main`:
   ```
   git checkout main && git pull
   git checkout -b spec/NNN-<slug>
   ```
   The slug must match the spec file's slug (e.g. `spec/002-auth-scaffolding`).
4. **Update the spec frontmatter:** `status: in-progress`, bump `updated:` to today's date, add a dated entry in **Work log** noting the branch + issue number.
5. **Do the work.** Use TaskCreate for the task list inside the spec. Update the spec's `Files touched` as you go.
6. **Commit incrementally.** One topical commit per logical change is fine; many small commits are fine. Commit messages follow the existing repo style (see `git log`). Always include the trailer:
   ```
   Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
   ```
7. **Open the PR** when acceptance criteria are met:
   - Title: `Spec NNN — <spec title>`
   - Body:
     ```
     Closes #<issue-number>

     Spec: specs/phase-N-<phase>/NNN-<slug>.md

     ## Summary
     <1-3 bullets>

     ## Test plan
     <bulleted checklist matching the acceptance criteria>
     ```
   - Use `gh pr create` with a HEREDOC body.
8. **Pause for review.** Per the user's preference, do not auto-merge. Wait for explicit "merge" before merging.
9. **After merge:** set spec status to `done`, update `specs/README.md` tracker, and `git checkout main && git pull && git branch -d spec/NNN-<slug>`.

## Naming conventions (locked)

- Spec file: `specs/phase-<N>-<phase-slug>/<NNN>-<slug>.md`
- Branch: `spec/<NNN>-<slug>` (slug matches spec filename slug)
- Issue title: `Spec <NNN> — <title>`
- PR title: `Spec <NNN> — <title>`
- Commit messages: free-form, but one trailer required: `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

## Anti-patterns (do not do)

- Opening a separate GitHub issue per task within a spec — the spec markdown's checklist is the single source of truth for sub-task progress.
- Working directly on `main` (only spec 001 was committed to main as a one-time bootstrap exception).
- Pushing `--force` to `main` or any shared branch.
- Auto-merging the PR. Always pause for review unless the user explicitly says "merge it" or has put the auto-merge instruction in CLAUDE.md.
- Skipping the spec markdown update — the markdown is the durable record; the GitHub issue/PR are the workflow chrome.

## Quick reference: gh commands used

```bash
# Open issue
gh issue create \
  --title "Spec 002 — Auth scaffolding" \
  --body-file <(cat <<'EOF'
Spec file: specs/phase-0-foundation/002-auth-scaffolding.md

<short summary>

## Acceptance criteria
- [ ] ...
EOF
) \
  --label spec --label phase-0

# Create label (only first time per label)
gh label create phase-0 --color "1D76DB" --description "Phase 0 — Foundation" 2>/dev/null || true

# Open PR
gh pr create \
  --title "Spec 002 — Auth scaffolding" \
  --body "$(cat <<'EOF'
Closes #<n>

Spec: specs/phase-0-foundation/002-auth-scaffolding.md

## Summary
- ...

## Test plan
- [ ] ...
EOF
)"
```

## Updating this skill

This file is the source of truth. If the workflow changes, update this file *first* and reflect the change in `specs/README.md`. Do not let the two drift.
