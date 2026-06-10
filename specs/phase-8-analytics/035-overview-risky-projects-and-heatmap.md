---
spec: overview-risky-projects-and-heatmap
phase: 8
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-10
updated: 2026-06-10
---

# 035 — Overview risky-projects panel + heatmap polish (closes Phase 8)

## Goal
Close Phase 8 by surfacing the `health_score` work spec 033 lit up.
Build a new "Risky projects" panel on Overview that sorts the
authenticated user's projects ascending by `health_score`, putting
degraded / warning / critical projects at the top of the page so the
user opens the app and sees what needs attention.

The Echo subscription on `users.{id}.dashboard` that spec 033 pre-
wired becomes visible: each `HealthScoreUpdated` pulse partial-
reloads the `dashboard` prop, the panel re-renders without a manual
refresh.

Polish item: realign the existing real-data activity heatmap from
its current 90-day window to the acceptance-criterion's "last 12
weeks" (84 days). The heatmap is already wired to real
`activity_events.occurred_at` aggregates — the work `MOCK_HEATMAP`
language in the Phase 8 README pointed at no longer exists (verified
during impl research). One-line shift.

Roadmap refs: §Phase 8 acceptance criteria ("Risky projects panel
sorts by health_score asc", "activity heatmap is driven by real
activity_events.occurred_at"), §14.2 health-score bands.

## Scope

**In scope:**

- **Backend.**
  - `app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php` —
    add a `riskyProjects(User $user)` private method that returns
    up to 6 projects owned by the user, ordered `health_score ASC`
    with `health_score IS NULL` placed last (unscored projects
    shouldn't displace genuinely-risky ones), then
    `last_activity_at DESC` as a stable tiebreaker. Each row carries
    the project payload shape that already exists for project Show:
    `{id, slug, name, color, icon, health_score, health_band,
    last_activity_at}` — just enough for the panel to render.
  - Adopt a `User` parameter on the existing `handle()` method
    surface for the riskyProjects slice (the rest of the dashboard
    stays single-tenant for now; tenancy migration is a separate
    spec). The controller passes `$request->user()`.
  - Window the heatmap to **last 12 weeks** by switching
    `now()->subDays(90)` → `now()->subWeeks(12)` to match the Phase
    8 README's literal acceptance text. The grid shape (7×6) and
    bucketing rule (dayOfWeek × intdiv(hour, 4)) stay unchanged.

- **Frontend.**
  - `resources/js/Components/Dashboard/RiskyProjects.vue` — new
    panel component. Renders the (up to 6) project rows: project
    icon + color chip, name, `HealthScoreBadge` (reuse from spec
    033), and a small "last activity" line. Empty state: when the
    user has no projects OR every project sits at score ≥ 70,
    render a quiet "All projects healthy" placeholder. The panel
    title is "Risky projects". Each row is a `<Link>` to the
    project's Show page.
  - `resources/js/Pages/Overview.vue` — slot the new panel into the
    page near the top of the right column (above the existing
    services / activity heatmap card). Pass `dashboard.riskyProjects`
    down. The existing Echo `router.reload({ only: ['dashboard'] })`
    already covers the realtime refresh — the panel just becomes
    visible the moment the payload starts carrying data.
  - `resources/js/types/index.d.ts` — extend `DashboardPayload`
    with `riskyProjects: RiskyProjectRow[]` and add the
    `RiskyProjectRow` type matching the backend shape.

- **Tests.**
  - `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` —
    extend with riskyProjects cases: returns owned projects only
    (cross-user isolation); ordering is `health_score ASC, nulls
    last`; cap at 6; empty when user has no projects; empty when
    all projects sit at score ≥ 70 (defines the "all healthy"
    placeholder boundary).
  - `tests/Feature/OverviewControllerTest.php` — assert the new
    `dashboard.riskyProjects` key surfaces in the Inertia payload
    with the expected per-row shape.
  - Heatmap window check: extend the existing heatmap test to
    confirm a 91-day-old event does NOT contribute and an 83-day-
    old event DOES (the 84-day boundary).

**Out of scope:**

- Multi-tenant scoping of the OTHER dashboard slices (services /
  deployments / alerts / uptime / topRepositories). Those stay
  single-tenant until a dedicated tenancy spec.
- `HeatmapUpdated` broadcast (heatmap recomputes per pageload — the
  12-week window doesn't shift second-by-second).
- User-tunable "risky" threshold. The default ascending-health-score
  cut at score < 70 is taken from §14.2's "good" band floor.
- Per-project drill-down filters on Overview. Project Show already
  carries the per-project score chip (spec 033).
- PR cycle time / stale PR count / open issues trend — still
  deferred to a Phase-4 follow-up per 033 / 034.
- Sparkline per risky-project (would compound the per-row payload
  for no UX win; the badge tone + score is enough).

## Plan

1. **Backend — `riskyProjects(User $user)`.**
   ```php
   private function riskyProjects(User $user): array
   {
       return Project::query()
           ->where('owner_user_id', $user->id)
           ->orderByRaw('health_score IS NULL') // nulls last (SQLite + MySQL portable)
           ->orderBy('health_score', 'asc')
           ->orderBy('last_activity_at', 'desc')
           ->limit(6)
           ->get()
           ->map(fn (Project $p) => [
               'id' => $p->id,
               'slug' => $p->slug,
               'name' => $p->name,
               'color' => $p->color,
               'icon' => $p->icon,
               'health_score' => $p->health_score,
               'health_band' => $p->health_score === null
                   ? null
                   : HealthScoreBand::fromScore($p->health_score)->value,
               'last_activity_at' => $p->last_activity_at?->diffForHumans(),
           ])
           ->all();
   }
   ```

2. **Backend — `handle()` signature.** Accept a `User $user`
   parameter and forward to `riskyProjects()`. Update the
   `OverviewController` (or whichever caller exists) to pass
   `$request->user()`.

3. **Backend — heatmap window.** Change `now()->subDays(90)` to
   `now()->subWeeks(12)` in the existing `activityHeatmap()`
   method. Update its inline comment.

4. **Frontend — `RiskyProjects.vue`.** New component under
   `resources/js/Components/Dashboard/`. Mirrors the existing
   `KpiCard` glass-card chrome (gradient ring, panel-hover bg).
   Empty state has a soft `ShieldCheck` icon + "All projects
   healthy" text.

5. **Frontend — `Overview.vue` slot.** Drop the panel above the
   activity heatmap card. No new Echo logic needed; the existing
   `router.reload({ only: ['dashboard'] })` covers the refresh.

6. **Frontend — types.** Add `RiskyProjectRow` and extend
   `DashboardPayload`.

7. **Tests.** Per the test list under **In scope**. Use existing
   `Project::factory()->create([...])` patterns.

8. **Pint clean. `php artisan test` green. `npm run build` clean.
   Self-review with `superpowers:code-reviewer`. PR. Watch CI.
   Pause for merge.**

## Acceptance criteria
- [ ] `dashboard.riskyProjects` carries up to 6 owned projects,
      ordered by `health_score` ascending with nulls last.
- [ ] Cross-user isolation: user B's projects don't appear in user
      A's payload.
- [ ] Overview "Risky projects" panel renders the rows with the
      `HealthScoreBadge` + last-activity line; empty state shows the
      "All projects healthy" placeholder.
- [ ] A `HealthScoreUpdated` pulse moves a project's row position in
      the panel without a manual refresh (the spec 033 Echo
      subscription becomes visibly load-bearing).
- [ ] Activity heatmap window is exactly 12 weeks (84 days) — an
      83-day-old event contributes; a 91-day-old event does not.
- [ ] Pint clean. `php artisan test` green. `npm run build` clean.

## Files touched

- `app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php` —
  `riskyProjects` slice, `handle()` accepts `User`, heatmap window
  shifted to 12 weeks.
- `app/Http/Controllers/OverviewController.php` — pass
  `$request->user()` to the query.
- `resources/js/Components/Dashboard/RiskyProjects.vue` — created.
- `resources/js/Pages/Overview.vue` — slot the panel + extend
  `DashboardPayload` consumption.
- `resources/js/types/index.d.ts` — `RiskyProjectRow` +
  `DashboardPayload.riskyProjects`.
- `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` —
  extended with riskyProjects + heatmap window cases.
- `tests/Feature/OverviewControllerTest.php` — payload assertion.

## Work log
Dated notes as work progresses.

### 2026-06-10
- Drafted from `_template.md`. Research surfaced that the activity
  heatmap is already real-data wired (no `MOCK_HEATMAP` constant
  exists) — Phase 8 README's literal text was outdated. 035 trims
  to the actually-remaining work: riskyProjects panel + 12-week
  window realignment.
- Branch `spec/035-overview-risky-projects-and-heatmap` cut off main.
- Tracking issue #103.
- Scope shipped as drafted (no late edits requested).

## Open questions / blockers

- **`orderByRaw('health_score IS NULL')` portability.** SQLite
  evaluates this as 0 / 1, sorting nulls last when ASC. MySQL agrees.
  Postgres requires `NULLS LAST` syntax. The Nexus production target
  is currently MySQL-class, so portable. Worth noting if Postgres
  ever lands.
- **"All projects healthy" threshold.** Empty-state triggers when
  every owned project sits at `health_score >= 70` (the §14.2 "good"
  floor). Tunable in a future polish spec if users complain that the
  panel disappears too eagerly.
- **Panel cap of 6.** Chosen to mirror the existing dashboard's
  6-card row rhythm. If users have more than 6 truly-risky projects
  the panel doesn't paginate — they'd surface the next batch via
  `/projects` filtered by score, which is its own future polish.
