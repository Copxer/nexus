# Nexus Control Center — Specs

This folder tracks every spec, feature, and task for the Nexus Control Center project.

Source of truth for product scope: [`../nexus_control_center_roadmap.md`](../nexus_control_center_roadmap.md)
Visual target for the UI: [`visual-reference.md`](visual-reference.md) (reads from `../nexus-dashboard.png`)

## How this folder is organized

```
specs/
    README.md              ← this file (master tracker)
    _template.md           ← copy this when starting a new spec
    phase-0-foundation/    ← one folder per phase
        README.md          ← phase summary + task list
        001-laravel-scaffold.md
        002-app-layout.md
        ...
    phase-1-projects/
    ...
```

Each individual spec file documents:
- **Goal** — what we're building and why
- **Scope** — what's in / what's out
- **Plan** — step-by-step approach
- **Acceptance criteria** — how we know it's done
- **Status** — `not-started` / `in-progress` / `blocked` / `done`
- **Work log** — dated notes as work progresses
- **Files touched** — list of created/modified files (filled in as we go)

## Phase tracker

Status legend: ⬜ not started · 🟡 in progress · 🟢 done · 🔴 blocked

| # | Phase | Status | Notes |
|---|-------|--------|-------|
| 0 | Foundation (auth, layout, static overview) | 🟢 | 9/9 specs done (001–009). Phase complete. |
| 1 | Projects & Repositories | 🟢 | 3/3 specs done (010–012). Phase complete. |
| 2 | GitHub Integration MVP | 🟢 | 4/4 specs done (013–016). Phase complete. |
| 3 | GitHub Webhooks & Activity Feed | 🟢 | 3/3 specs done (017–019). Phase complete. |
| 4 | Deployments & CI/CD | 🟡 | 1/3 specs done (020). 021–022 next. |
| 5 | Website Monitoring | ⬜ | — |
| 6 | Docker Host Agent MVP | ⬜ | — |
| 7 | Alerts Engine | ⬜ | — |
| 8 | Analytics & Health Scores | ⬜ | — |
| 9 | Polish & Production Readiness | ⬜ | — |
| 10 | Future Innovation | ⬜ | — |

## MVP scope (target for v1)

Per roadmap §20:
1. Auth
2. Futuristic dashboard layout
3. Projects
4. Repositories
5. GitHub issues and PR sync
6. Activity feed
7. Website monitoring
8. Basic alerts

## Workflow

Every spec is shipped as a GitHub issue + branch + PR. The detailed flow lives in the [`nexus-spec-workflow` skill](../.claude/skills/nexus-spec-workflow/SKILL.md). Short version:

1. **Draft the spec file** under the appropriate phase folder.
2. **Open a GitHub issue** mirroring the spec: title `Spec NNN — <title>`, labels `spec` + `phase-N`.
3. **Branch** `spec/NNN-<slug>` off the latest `main`. Flip spec status to `in-progress` and log the issue/branch in the Work log.
4. **Implement.** Update `Files touched` in the spec as you go. Run tests locally.
5. **Self-review.** Run the `superpowers:code-reviewer` agent on `git diff main...HEAD`. Address material findings; surface stylistic ones in the PR body.
6. **Open the PR.** Title `Spec NNN — <title>`. Body must include `Closes #<issue>` so GitHub auto-closes the issue with reason `completed` on merge.
7. **CI must be green.** `.github/workflows/ci.yml` runs Pint, `php artisan test`, and `npm run build`. Branch protection on `main` requires it.
8. **Wait for the user.** No auto-merge. After explicit go-ahead, squash-merge with `gh pr merge --squash --delete-branch`.
9. **Verify the issue closed as completed** (`gh issue view <n> --json state,stateReason`). Manual fallback: `gh issue close <n> --reason completed`.
10. **Spec & tracker bookkeeping** ride inside the same spec PR — flip spec frontmatter to `done` and update tracker tables before opening the PR, so when it merges, `main` is consistent in one shot.

### Notes
- Spec 001 (initial scaffold) was committed directly to `main` to bootstrap the repo. From spec 002 onward every change flows through a PR.
- Tasks *within* a spec are tracked as a checklist in the spec file (and in the local `TaskCreate` list during the active session). They do **not** get their own GitHub issues — that would be too noisy.
- Non-spec changes (tooling, CI tweaks, skill updates, hotfixes, doc-only edits) also go through a PR. Branch naming: `<type>/<slug>` matching the conventional-commit prefix (`chore/`, `fix/`, `docs/`, `refactor/`, `ci/`). PR title uses the same prefix.
- The "Protect main" ruleset enforces this: pushes to `main` are rejected unless they carry a green `ci` check, which only PRs can produce.
