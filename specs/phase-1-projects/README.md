# Phase 1 — Projects & Repositories

Source: [roadmap §19 Phase 1](../../nexus_control_center_roadmap.md), §8.2 Projects, §8.3 Repositories.
Visual target: [`../visual-reference.md`](../visual-reference.md) → [`../../nexus-dashboard.png`](../../nexus-dashboard.png).

## Phase goal
Add real project and repository records to Nexus. Replace the mock dashboard data from Phase 0 with database-driven values for the slices we can actually populate (Projects KPI count, Repositories KPI count, Top Repositories widget). The rest of the Overview stays mock until the integrations land in later phases.

## Tasks

| # | Task | Status |
|---|------|--------|
| 010 | Projects (model + migration + factory + policy + CRUD pages + sidebar/palette activation) | ⬜ |
| 011 | Repositories (model + migration + manual link + index/show pages + sidebar/palette activation) | ⬜ |
| 012 | Wire Overview KPIs and Top Repositories widget to the database | ⬜ |

## Acceptance criteria (phase-level)
- [ ] User can create, edit, and delete a project from the UI (`/projects` index, create form, detail page).
- [ ] User can manually link a GitHub repository (URL/full-name) to a project.
- [ ] Project detail page shows linked repositories count.
- [ ] Overview KPI row's `Projects` and `Hosts` cards (the latter via repository count proxy until Phase 6) read live counts from the database.
- [ ] Top Repositories widget on Overview reads real linked repositories.
- [ ] Sidebar `Projects` and `Repositories` nav items become active links (not "Soon" placeholders).
- [ ] Command palette `Go to Projects`, `Go to Repositories`, `Create project` commands are real (no "Soon" pills).
- [ ] No regressions to the static stub widgets that haven't been activated yet (Container Hosts, Service Health, Visualizations placeholder).
