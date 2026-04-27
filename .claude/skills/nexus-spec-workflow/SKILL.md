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
6. **Commit incrementally.** One topical commit per logical change is fine; many small commits are fine. Commit messages follow the existing repo style (see `git log`). **Do not add a Co-Authored-By trailer** — the repo owner does not want Claude/Anthropic co-author trailers on commits.
7. **Self-review pass before opening the PR.** Invoke the `superpowers:code-reviewer` agent on the diff (`git diff main...HEAD`). Capture its findings. Address anything material (real bugs, security issues, missed acceptance criteria) before the PR; surface stylistic suggestions to the user in the PR body but don't necessarily fix them.
8. **Open the PR** when acceptance criteria are met:
   - Title: `Spec NNN — <spec title>`
   - Body MUST include `Closes #<issue-number>` so GitHub auto-closes the issue with reason **completed** when the PR merges.
   - Body template:
     ```
     Closes #<issue-number>

     Spec: specs/phase-N-<phase>/NNN-<slug>.md

     ## Summary
     <1-3 bullets>

     ## Test plan
     <bulleted checklist matching the acceptance criteria>

     ## Self-review notes
     <bullets from the code-reviewer agent — what was checked, what was changed, any deferred items>
     ```
   - Use `gh pr create` with a HEREDOC body.
9. **CI is the only hard gate.** The `.github/workflows/ci.yml` job runs Pint, `php artisan test`, and `npm run build`. Branch protection on `main` requires it green before merge. Watch the run with `gh pr checks <pr-number> --watch`. If it fails, fix and push — never bypass.
10. **Pause for the user.** Per the user's preference, do not auto-merge. Wait for explicit "merge it" before merging.
11. **Merging** (after explicit user go-ahead): squash-merge so each spec lands on `main` as one commit. Use `gh pr merge <pr-number> --squash --delete-branch`.
12. **Verify the issue closed as completed.** After the merge, run `gh issue view <n> --json state,stateReason`. State should be `CLOSED`, stateReason `COMPLETED`. If for any reason it didn't auto-close (e.g. the `Closes #N` keyword got mangled), close it explicitly: `gh issue close <n> --reason completed`. Never use `--reason "not planned"` for a successfully-merged spec — that reason is reserved for abandoned work.
13. **Local cleanup:** `git checkout main && git pull --ff-only && git branch -d spec/NNN-<slug>`.
14. **Update spec & tracker:** set spec frontmatter `status: done`, update `specs/README.md` and the relevant `phase-N/README.md` to flip the task to 🟢. Commit this directly to `main` as a small `chore:` commit — it's just bookkeeping, not implementation.

## Naming conventions (locked)

- Spec file: `specs/phase-<N>-<phase-slug>/<NNN>-<slug>.md`
- Branch: `spec/<NNN>-<slug>` (slug matches spec filename slug)
- Issue title: `Spec <NNN> — <title>`
- PR title: `Spec <NNN> — <title>`
- Commit messages: free-form. **No Co-Authored-By trailer.**

## Anti-patterns (do not do)

- Opening a separate GitHub issue per task within a spec — the spec markdown's checklist is the single source of truth for sub-task progress.
- Working directly on `main` (spec 001 was committed to main as a one-time bootstrap exception; small workflow/tooling commits like CI changes or skill updates may go on main directly with a `chore:` prefix, but never spec implementation work).
- Pushing `--force` to `main` or any shared branch.
- Auto-merging the PR. Always pause for review unless the user explicitly says "merge it" or has put the auto-merge instruction in CLAUDE.md.
- Skipping the self-review pass. Even when the change is small, running `superpowers:code-reviewer` is cheap and catches things.
- Bypassing CI (e.g. `--admin` merge to ignore failing checks). If CI is red, fix the underlying issue.
- Closing an issue with `--reason "not planned"` after a successful merge. That reason is for abandoned work.
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

## Self-review notes
- <bullets from superpowers:code-reviewer agent>
EOF
)"

# Watch CI on the PR
gh pr checks <pr-number> --watch

# Squash-merge after user approval (delete branch on remote)
gh pr merge <pr-number> --squash --delete-branch

# Verify issue auto-closed as completed
gh issue view <n> --json state,stateReason

# Manual fallback if it didn't auto-close
gh issue close <n> --reason completed
```

## Updating this skill

This file is the source of truth. If the workflow changes, update this file *first* and reflect the change in `specs/README.md`. Do not let the two drift.
