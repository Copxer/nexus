# Phase 7 — Alerts Engine

Source: [roadmap §Phase 7](../../nexus_control_center_roadmap.md), §8.12 Alerts, §6.8 Specification Pattern for Alerts.

## Phase goal
Durable, acknowledgeable alerts on top of the activity feed. The existing
transition events (`website.down`, `host.offline`, `workflow.failed`) get
promoted into `alerts` rows that the user can acknowledge / resolve / mute
from a dedicated `/alerts` page. Recovery transitions auto-close the
matching alert. Overview's Alerts KPI swaps from the long-standing
`MOCK_KPIS.alerts` placeholder to real counts.

## Tasks

| # | Task | Status |
|---|------|--------|
| 030 | Alerts scaffolding + transition-based promotion (events → Alert rows) | 🟢 |
| 031 | Alerts UI + acknowledge / resolve / mute actions | ⬜ |
| 032 | Realtime updates + Overview Alerts KPI wiring (closes phase 7) | ⬜ |

## Acceptance criteria (phase-level)
- [ ] `website.down`, `host.offline`, and `workflow.failed` (on a default
      branch) each create an Alert row with the right severity and source.
- [ ] `website.recovered` / `host.recovered` auto-resolve the matching open alert;
      workflow failures stay open until the user acts.
- [ ] Same source + source_id firing twice within an open-alert window
      bumps `last_seen_at` instead of duplicating (idempotency).
- [ ] Sidebar `Alerts` entry is enabled and routed; Cmd+K `go-alerts` works.
- [ ] User can acknowledge / resolve / mute an alert from `/alerts`.
- [ ] Overview Alerts KPI reflects real active + critical counts (no
      `MOCK_KPIS`).
- [ ] Realtime updates land on `/alerts` (dedicated `users.{id}.alerts`
      channel) and on the right-rail activity feed.
- [ ] Pint clean, tests green, build clean. CI green for each spec PR.

## Scope notes
- **Transition-based MVP only.** Alerts in this phase are created by the
  existing activity transitions. User-defined `AlertRule` rows + an
  `EvaluateAlertRulesJob` against raw metrics (roadmap §6.8) are deferred
  to a future polish spec — Phase 7's acceptance is purely event-driven.
- **Out of scope here:** outbound notifications (email / Slack / webhook —
  the roadmap's `AlertNotificationService`), mute-by-source / mute-by-project,
  and alert grouping beyond the per-`(source, source_id, type)` idempotency.
- The activity-rail vocabulary already includes `alert.triggered` and
  `alert.resolved` (registered in `Components/Activity/ActivityFeedItem.vue`
  during spec 026 / 027). Phase 7 fills in the events that emit them.
