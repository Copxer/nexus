# Phase 5 — Website Monitoring

Source: [roadmap §19 Phase 5](../../nexus_control_center_roadmap.md), §8.8 Website Performance Monitoring.

## Phase goal
Stand up website uptime + response-time monitoring end-to-end. By the end of phase 5, a user can add a website URL to a project, get a manual probe with timing on demand, watch a scheduled check run every configured interval, see uptime % over rolling windows, and have the Overview KPI driven by real data instead of the phase-0 `MOCK_KPIS['uptime']` placeholder.

## Tasks

| # | Task | Status |
|---|------|--------|
| 023 | Website monitor MVP (CRUD + manual probe + check history) | 🟢 |
| 024 | Scheduled checks + uptime calc + activity events | 🟢 |
| 025 | Overview integration + Reverb live updates + perf charts | ⬜ |

## Acceptance criteria (phase-level)
- [ ] User can add / edit / delete a website monitor under a project.
- [ ] Manual "Probe now" runs an HTTP probe and records the result.
- [ ] Background scheduler runs each website's probe every `check_interval_seconds`.
- [ ] Uptime % is calculated over 24h / 7d / 30d windows.
- [ ] Slow / down transitions create activity events on the right rail.
- [ ] Overview's uptime KPI card is fed by real `website_checks` aggregates (no mocks).
- [ ] Sidebar `Monitoring` entry is enabled and routes to the websites listing.
- [ ] Pint + tests + build clean. CI green for every spec PR.
