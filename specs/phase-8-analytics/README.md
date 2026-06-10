# Phase 8 — Analytics & Health Scores

Source: [roadmap §Phase 8](../../nexus_control_center_roadmap.md), §8.13
Analytics & Health, §14.2 Health-score weighting formula, §10 dashboard
data sources.

## Phase goal
Make the dashboard reflect real system health. Turn the dormant
`projects.health_score` column into a scheduled + transition-driven
scoring system (per §14.2 weights), ship a `/analytics` page with the
§8.13 chart set powered by the data Phases 4–7 already collect, make
Overview prioritize risky projects ahead of healthy ones, and swap the
activity heatmap from `MOCK_HEATMAP` to real `activity_events`
aggregates. Phase 8 closes when the user can open the app and see — at
a glance — which projects need attention, why, and how recent that
matters.

## Tasks

| # | Task | Status |
|---|------|--------|
| 033 | Health-score scaffolding (calculation + scheduled & transition-driven recompute + broadcast + Overview & project UI) | 🟢 |
| 034 | Analytics dashboard page (`/analytics` route, §8.13 chart set, date-range filter) | 🟢 |
| 035 | Real-data activity heatmap + Overview risky-project prioritization (closes phase 8) | ⬜ |

## Acceptance criteria (phase-level)
- [ ] `projects.health_score` is non-null for every project after the
      first scheduled run; updates in <30s after a transition that
      should move it (active critical alert, website down, host
      offline, etc.).
- [ ] Score 90–100 / 70–89 / 50–69 / 30–49 / 0–29 render as the
      healthy / good / degraded / warning / critical bands per §14.2.
- [ ] `/analytics` route is reachable; sidebar entry enabled; Cmd+K
      `go-analytics` works.
- [ ] Each §8.13 chart with a wired data source (deployment frequency,
      alert frequency, uptime trend, container resource usage,
      average website response time, deployment success rate, mean
      time to recovery) renders against real rows, not `MOCK_*`.
- [ ] Date-range filter on `/analytics` supports 7d / 30d / 90d via
      URL params; the active range survives a refresh.
- [ ] Overview's "Risky projects" panel sorts by `health_score` asc,
      surfacing degraded / warning / critical projects ahead of
      healthy ones.
- [ ] Overview's activity heatmap is driven by real
      `activity_events.occurred_at` aggregates over the last 12 weeks
      (not `MOCK_HEATMAP`).
- [ ] Realtime: a transition that moves a project's score broadcasts
      `HealthScoreUpdated` on `users.{id}.dashboard`; Overview reacts
      without a manual refresh.
- [ ] Pint clean, tests green, build clean. CI green for each spec PR.

## Scope notes
- **Score signals limited to what Phases 4–7 already collect.** Active
  alerts (Phase 7), website down + slow (Phase 5), host offline (Phase
  6), workflow failures on default branch (Phase 4) all count.
  **Deferred to a Phase 4 follow-up:** PR cycle time, stale PR count,
  open issues trend — those need GitHub Issues + PR webhook ingestion
  that doesn't exist yet, and we'd rather not bloat Phase 8's data
  layer with a side-quest.
- **User-tunable score weights** (UI for adjusting the §14.2 numbers)
  are deferred to a polish spec. Phase 8 ships the §14.2 numbers as
  constants.
- **`AlertNotificationService`** (email / Slack / webhook) stays
  deferred from Phase 7.
- **Analytics export / CSV download** is deferred. Phase 8 is
  in-browser viewing only.
- Each spec ships its own CI-green PR; the phase closes when 035
  merges.
