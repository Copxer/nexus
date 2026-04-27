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
| 0 | Foundation (auth, layout, static overview) | ⬜ | — |
| 1 | Projects & Repositories | ⬜ | — |
| 2 | GitHub Integration MVP | ⬜ | — |
| 3 | GitHub Webhooks & Activity Feed | ⬜ | — |
| 4 | Deployments & CI/CD | ⬜ | — |
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

1. Before starting work, create or update the spec file for the task.
2. Set status to `in-progress` and add a dated entry in **Work log**.
3. As files are created/modified, append them to **Files touched**.
4. When acceptance criteria are met, set status to `done` and update this README's tracker.
5. If blocked, set status to `blocked` and note the blocker in **Work log**.
