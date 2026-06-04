---
spec: analytics-dashboard
phase: 8
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-04
updated: 2026-06-04
---

# 034 — Analytics dashboard page

## Goal
Ship the `/analytics` page that turns the data Phases 4–7 already
collect into the §8.13 metric set. Mean time to recovery, deployment
frequency + success rate, alert frequency, website uptime + response
time, container resource usage. Each card pulls real rows — no
`MOCK_*` for any metric whose data source exists.

A small date-range selector (7d / 30d / 90d) lives in the page header
and rides on the URL so the active range survives a refresh. The
sidebar + Cmd+K entries (already scaffolded as `disabled` placeholders)
flip on. After 034, only the heatmap real-data wiring + Overview
risky-projects prioritization remain for 035 to close Phase 8.

Roadmap refs: §Phase 8 deliverables ("Analytics dashboard",
"Deployment frequency chart", "PR cycle time chart" — deferred, see
scope notes, "Alert frequency chart", "Uptime trend chart"), §8.13
analytics metrics, §6.7 Query class pattern.

## Scope

**In scope:**

- **Route + controller.**
  - `routes/web.php` — `Route::get('/analytics', AnalyticsController::class)
    ->middleware(['auth', 'verified'])->name('analytics.index')`. Sits
    after `alerts.index` to match the sidebar order.
  - `app/Http/Controllers/AnalyticsController.php` — single-action
    invokable. Validates the `range` query param against `7d|30d|90d`
    (default `30d`). Resolves to a `Carbon` start date, passes
    through to the four Query classes, renders
    `Inertia::render('Analytics/Index', [...])`.

- **Query classes (`app/Domain/Analytics/Queries/`).** Four classes,
  each owning one source's metrics so the page can grow per-card
  without one giant query:
  - `GetDeploymentMetricsQuery::execute(User $user, Carbon $from): array`
    — daily-bucketed counts of `workflow_runs` joined to
    `repositories` (scoped via `repositories.project_id` →
    `projects.owner_user_id`). Returns
    `{frequency: {total, sparkline}, success_rate: {percent,
    change, status}}`. Success rate = completed runs with
    `conclusion='success'` / completed total over the range.
  - `GetAlertMetricsQuery::execute(User $user, Carbon $from): array`
    — daily count of `alerts.triggered_at` (sparkline + total) and
    MTTR (`AVG(resolved_at - triggered_at)` over resolved alerts in
    the range, scoped via `alerts.project_id` →
    `projects.owner_user_id`). Returns
    `{frequency: {total, sparkline}, mttr: {seconds, label,
    status}}` where `label` is a humanized string like `"4m 12s"`
    and `status` maps `<10min→success`, `<30min→warning`,
    `≥30min→danger`.
  - `GetWebsiteMetricsQuery::execute(User $user, Carbon $from): array`
    — reuses `GetMonitoringUptimeKpiQuery`'s shape for the uptime %
    + sparkline, adds a response-time aggregate
    (`AVG(response_time_ms)` over successful checks per day) returned
    as `{uptime: {percent, change, sparkline, status},
    response_time: {avg_ms, sparkline, status}}`. The uptime path
    can delegate directly to the existing query class for
    consistency.
  - `GetContainerResourceUsageQuery::execute(User $user, Carbon $from): array`
    — daily averages of `cpu_percent` + `memory_percent` from
    `container_metric_snapshots` joined through `containers` →
    `hosts.project_id` → `projects.owner_user_id`. Returns
    `{cpu: {percent, sparkline, status},
    memory: {percent, sparkline, status}}`. Status maps `<60%→success`,
    `<85%→warning`, `≥85%→danger`. Empty-range case (user has no
    hosts yet) returns nulls and the page renders the "no data yet"
    placeholder.

  All four queries:
  - Scope strictly by `User` ownership (mirrors `GetOverviewDashboardQuery`).
  - Use `selectRaw('DATE(...)')` + `groupBy` for the sparklines —
    same Carbon-windowed pattern as `GetMonitoringUptimeKpiQuery`.
  - Default empty days to `null` (chart code distinguishes "no data"
    from "zero").
  - Cap sparkline length to the range's day count
    (`7d → 7`, `30d → 30`, `90d → 90`).

- **Page (`resources/js/Pages/Analytics/Index.vue`).**
  - Header: page title + `DateRangeFilter` (7d / 30d / 90d pill
    group) on the right. The selector mutates the URL via
    `router.visit(route('analytics.index'), { data: { range } },
    { preserveScroll: true })`. Active selection survives a refresh
    because the controller reads from the URL.
  - Grid: six `KpiCard`s wired to the four query payloads
    (deployments → 2 cards, alerts → 2 cards, websites → 2 cards,
    containers → 2 cards = 8 total). Each card reuses the existing
    `KpiCard` + `Sparkline` + `TrendChip` + `StatusBadge`
    primitives — no new chart library, no SVG hacks.
  - Empty state per card: when the underlying source has no rows
    in the range, the card shows `—` for the value and a muted
    "No data in this range" footer. The card stays sized so the
    grid doesn't reflow.

- **Date-range filter component
  (`resources/js/Components/Analytics/DateRangeFilter.vue`).**
  - Three-option pill group: `7d` / `30d` / `90d`. Emits the new
    range on click. Styling mirrors the existing `StatusBadge` pill
    palette so it reads as part of the dashboard chrome.
  - Stays tiny — props `{ value, options? }`, single emit.

- **Sidebar + Cmd+K flip-on.**
  - `resources/js/Components/Sidebar/Sidebar.vue` — the existing
    `{ label: 'Analytics', icon: BarChart3, disabled: true,
    soonLabel: 'Phase 8' }` entry: drop `disabled` + `soonLabel`,
    add `routeName: 'analytics.index'`.
  - `resources/js/lib/commands.ts` — the existing `go-analytics`
    command: drop `disabled` + `soonLabel`, add `run: () =>
    router.visit(route('analytics.index'))`.

- **Tests.**
  - `tests/Feature/Analytics/AnalyticsControllerTest.php` — happy
    path renders for a verified user; default range is 30d when the
    param is absent; invalid range (`'1y'`) is rejected; payload
    carries `metrics` + `filters.range`; guest is redirected to
    login.
  - One Query test per class (`tests/Unit/Domain/Analytics/Queries/`):
    happy path with seeded rows produces the expected shape;
    sparkline length matches the range; cross-user isolation
    (user A's data doesn't leak into user B's payload);
    empty-range returns the expected null / zero shape.

**Out of scope:**

- Real-data activity heatmap on Overview — **035**.
- Overview "Risky projects" panel sorted by `health_score` — **035**.
- `HealthScoreUpdated` broadcast subscriber on `/analytics` —
  intentionally none. Analytics is a deliberate "snapshot" view; the
  user refreshes to see fresh aggregates. Live-pulse on multi-day
  aggregates would churn for no UX value.
- PR cycle time / stale PR count / open issues trend / failed-deploy
  trend chart — deferred (need Phase 4 GitHub Issues + PR webhook
  ingestion that doesn't exist for the first three; failed-deploy
  trend is rolled into deployment success rate's status badge
  instead of a standalone chart).
- Analytics export / CSV download — deferred (polish spec).
- Per-project / per-repository drill-down filters on `/analytics` —
  034 ships the org-wide view only. Per-project filtering lands as a
  polish spec when the user asks for it (project Show already shows
  per-project KPIs courtesy of spec 028 / 029).
- `analytics:` Horizon queue or scheduled pre-aggregation. Four
  queries against indexed columns over a 90d window stay under
  100ms on the dev DB; a cache layer is YAGNI until the chart count
  + concurrent users justify it.

## Plan

1. **Controller + route.**
   ```php
   // app/Http/Controllers/AnalyticsController.php
   public function __invoke(Request $request, /* four queries */): Response
   {
       $validated = $request->validate([
           'range' => 'sometimes|in:7d,30d,90d',
       ]);
       $range = $validated['range'] ?? '30d';
       $from = match ($range) {
           '7d'  => now()->startOfDay()->subDays(6),
           '30d' => now()->startOfDay()->subDays(29),
           '90d' => now()->startOfDay()->subDays(89),
       };
       return Inertia::render('Analytics/Index', [
           'filters' => ['range' => $range],
           'metrics' => [
               'deployments' => $deployments->execute($request->user(), $from),
               'alerts'      => $alerts->execute($request->user(), $from),
               'websites'    => $websites->execute($request->user(), $from),
               'containers'  => $containers->execute($request->user(), $from),
           ],
       ]);
   }
   ```

2. **`GetDeploymentMetricsQuery`.** Join `workflow_runs` → `repositories`
   → ownership. Two passes over the result set: one for the daily
   bucket (sparkline), one for completed-vs-success totals. Both
   collapse to one query if `selectRaw` is used judiciously. Status
   maps `≥95% → success`, `≥85% → warning`, `<85% → danger`.

3. **`GetAlertMetricsQuery`.** Daily-bucketed `triggered_at` for the
   sparkline. MTTR via
   `Alert::query()->whereNotNull('resolved_at')->where('triggered_at',
   '>=', $from)->avg(...)`. The diff is computed with
   `selectRaw('AVG(julianday(resolved_at) - julianday(triggered_at))
   * 86400 as seconds')` for SQLite + a `TIMESTAMPDIFF` equivalent
   for MySQL. Use Laravel's `DB::raw` with a portable formulation —
   `selectRaw('AVG(strftime("%s", resolved_at) -
   strftime("%s", triggered_at))')` works on SQLite; MySQL needs
   `TIME_TO_SEC(TIMEDIFF(...))`. Spec-time decision: ship the
   SQLite-portable form first (it's the test DB), file a follow-up
   for the MySQL variant if production hits issues. **Open
   question** below.

4. **`GetWebsiteMetricsQuery`.** Delegate the uptime piece to the
   existing `GetMonitoringUptimeKpiQuery::execute()` (rename its
   12-day default if needed, or pass the desired window through a
   new optional parameter — pick the latter to keep the existing
   Overview KPI's behavior). Response-time piece is a separate
   `selectRaw('DATE(checked_at) as day,
   AVG(response_time_ms) as avg_ms')` grouped by day, scoped to
   `status = 'up'` to exclude transport errors from skewing the
   mean.

5. **`GetContainerResourceUsageQuery`.** Join
   `container_metric_snapshots` → `containers` →
   `hosts.project_id` → `projects.owner_user_id`. Daily aggregation
   on `recorded_at`. Returns null payload (with explicit
   `cpu.percent: null` / `memory.percent: null`) when the user has
   no hosts — the page renders the "No data in this range"
   placeholder.

6. **Page + filter component.** Build `Analytics/Index.vue` with the
   8-card grid + `DateRangeFilter`. Reuse `KpiCard` directly — no
   need for an analytics-specific wrapper since the existing
   `KpiCard` props already cover label / value / sparkline /
   trend / status / icon.

7. **Sidebar + Cmd+K flip.** Two-line diffs in
   `Sidebar.vue` + `commands.ts`. Both already have the disabled
   entries from earlier specs.

8. **Tests.** Per the test list under **In scope**. Use
   `WorkflowRun::factory()`, `Alert::factory()`, `WebsiteCheck::factory()`,
   `ContainerMetricSnapshot::factory()` shapes. Cross-user
   isolation tests are critical — every query must scope by
   `owner_user_id` or it leaks across tenants.

9. **Pint clean. `php artisan test` green. `npm run build` clean.
   Self-review with `superpowers:code-reviewer`. PR. Watch CI.
   Pause for merge.**

## Acceptance criteria
- [ ] `GET /analytics` returns 200 for a verified user; redirects
      guests to login.
- [ ] Sidebar "Analytics" entry is enabled and routes; Cmd+K
      `go-analytics` works.
- [ ] All eight cards (deployment frequency, deployment success
      rate, alert frequency, MTTR, website uptime, website response
      time, container CPU, container memory) render against real
      rows when seeded, no `MOCK_*`.
- [ ] Date-range selector toggles 7d / 30d / 90d; the active range
      is reflected in the URL (`?range=...`) and survives a
      refresh.
- [ ] Every query scopes by the authenticated user's owned
      projects; user B's rows don't appear in user A's payload.
- [ ] Empty source (eg. user with no hosts) shows the "No data in
      this range" card placeholder without erroring or distorting
      the layout.
- [ ] Pint clean. `php artisan test` green. `npm run build` clean.

## Files touched
List of created/modified files. Fill in as work progresses.

- `routes/web.php` — `analytics.index` route
- `app/Http/Controllers/AnalyticsController.php` — created
- `app/Domain/Analytics/Queries/GetDeploymentMetricsQuery.php` — created
- `app/Domain/Analytics/Queries/GetAlertMetricsQuery.php` — created
- `app/Domain/Analytics/Queries/GetWebsiteMetricsQuery.php` — created
- `app/Domain/Analytics/Queries/GetContainerResourceUsageQuery.php` — created
- `app/Domain/Monitoring/Queries/GetMonitoringUptimeKpiQuery.php` — possibly extend signature for the range param
- `resources/js/Pages/Analytics/Index.vue` — created
- `resources/js/Components/Analytics/DateRangeFilter.vue` — created
- `resources/js/Components/Sidebar/Sidebar.vue` — enable Analytics nav entry
- `resources/js/lib/commands.ts` — enable `go-analytics` command
- `tests/Feature/Analytics/AnalyticsControllerTest.php` — created
- `tests/Unit/Domain/Analytics/Queries/GetDeploymentMetricsQueryTest.php` — created
- `tests/Unit/Domain/Analytics/Queries/GetAlertMetricsQueryTest.php` — created
- `tests/Unit/Domain/Analytics/Queries/GetWebsiteMetricsQueryTest.php` — created
- `tests/Unit/Domain/Analytics/Queries/GetContainerResourceUsageQueryTest.php` — created

## Work log
Dated notes as work progresses.

### 2026-06-04
- Drafted from `_template.md`. Mirrors the spec 030 / 033 style
  (in-scope / out-of-scope / plan / acceptance / files / log /
  questions).
- Branch `spec/034-analytics-dashboard` cut off main.
- Tracking issue #100.
- Scope shipped as drafted (no late edits requested).

## Open questions / blockers

- **MTTR query portability.** SQLite (test DB) needs
  `strftime('%s', ...)` for second-precision deltas; MySQL (likely
  production) uses `TIME_TO_SEC(TIMEDIFF(...))`. Plan ships the
  SQLite-portable form; production verification is a follow-up.
  Acceptable because the test suite is the immediate truth source
  for CI green.
- **`GetMonitoringUptimeKpiQuery` reuse.** That query is currently
  hard-coded to a 12-day window for Overview. Two options: (a) add
  an optional `?Carbon $from = null` parameter that defaults to its
  current 12-day computation; (b) duplicate the uptime math into
  `GetWebsiteMetricsQuery`. Option (a) keeps one source of truth;
  preferred unless impl friction shows up.
- **Container-metric scope.** Today
  `container_metric_snapshots.project_id` is denormalized via
  `containers.host_id → hosts.project_id`. Verify during impl
  whether the snapshots table has its own `project_id` (it would
  cut a join). If not, the join chain stays — fine.
