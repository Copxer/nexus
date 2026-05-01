---
spec: scheduled-checks-and-uptime
phase: 5-monitoring
status: in-progress
owner: yoany
created: 2026-04-30
updated: 2026-04-30
issue: https://github.com/Copxer/nexus/issues/73
branch: spec/024-scheduled-checks-and-uptime
---

# 024 — Scheduled checks + uptime calc + activity events

## Goal
Automate the website probes shipped in spec 023, calculate count-based uptime % over 24h / 7d / 30d windows, and surface incident / recovery transitions on the activity feed. After this spec a website monitor created via the UI will probe itself every `check_interval_seconds` without user intervention, and a `down → up` flip will land on the right rail with `severity: success`.

This is the **automation half** of phase 5. Spec 023 shipped CRUD + manual probes; spec 025 wires the Overview KPI + Reverb live updates on top of this data.

Roadmap reference: §8.8 Website Performance Monitoring (scheduler example, performance summary query, status field), §19 Phase 5 acceptance criteria ("System checks URL every configured interval", "Uptime % is calculated", "Slow/down site triggers alert").

## Scope
**In scope:**

- **`App\Domain\Monitoring\Jobs\DispatchDueWebsiteChecksJob`** — scheduler-bound dispatcher.
    - Loads all websites (capped to 500 as a sanity bound; revisit when a real account approaches it). For each, computes "is due now" in PHP — `last_checked_at === null OR last_checked_at + check_interval_seconds <= now()`.
    - **Why filter in PHP**, not SQL: the dynamic `last_checked_at + check_interval_seconds` predicate would need raw SQL that diverges across MySQL (`DATE_ADD`) and SQLite (`datetime(...)`). At phase-1 row counts (≤500 websites × the every-minute cadence) the in-PHP loop is sub-millisecond.
    - For each due website, dispatches `RunWebsiteCheckJob::dispatch($website->id)`.
    - `tries = 1` (mirrors the existing GitHub sync jobs). A failed dispatcher run just retries on the next minute tick.
    - Doesn't itself perform HTTP probes — that's the per-website job's responsibility, so a slow probe doesn't block the dispatcher.

- **`App\Domain\Monitoring\Jobs\RunWebsiteCheckJob`** — per-website async wrapper.
    - Constructor: `int $websiteId`. Loads the row inside `handle()` (skip if deleted between dispatch and run).
    - Resolves `RunWebsiteProbeAction` + `RecordWebsiteCheckAction` from the container, calls them in sequence.
    - The probe's HTTP request is the slow part; running async means hundreds of monitors can probe in parallel without blocking the dispatcher.
    - `tries = 1`. On failure the next every-minute dispatcher run will retry naturally.

- **Laravel scheduler binding** in `routes/console.php`:
    ```php
    Schedule::job(new DispatchDueWebsiteChecksJob)->everyMinute()
        ->name('monitoring:dispatch-due-website-checks')
        ->withoutOverlapping();
    ```
    `withoutOverlapping()` guards against a slow dispatcher tick stacking onto the next minute's run. Production needs `php artisan schedule:work` (or cron) running.

- **Activity event emission on status transitions.** Extend `RecordWebsiteCheckAction` to:
    - Capture `$previousStatus = $website->status` before the update (it's an enum at this point — `Pending|Up|Down|Slow|Error`).
    - Apply the existing persistence path.
    - Compare *categories* (Healthy = `Up|Slow`, Failed = `Down|Error`, Pending = first-ever probe state) and emit an activity event on **category transitions only**:
        - `Healthy → Failed` OR `Pending → Failed` → `event_type: website.down`, `severity: danger`, title `"{name} went down"`, description `"HTTP {code}"` or the captured error message.
        - `Failed → Healthy` → `event_type: website.up`, `severity: success`, title `"{name} recovered"`, description `"Up in {response_time_ms}ms"`.
        - Steady-state checks (Healthy → Healthy, Failed → Failed, Pending → Healthy) emit nothing — keeps the feed signal-dense.
    - Reuses the existing `CreateActivityEventAction` (spec 017) so the event automatically broadcasts via `ActivityEventCreated` (spec 019). Free realtime.
    - `source: 'monitoring'` (new value alongside the existing `'github'`). Stored as a free string per spec 017's schema; no migration needed.
    - `metadata` carries `{ website_id, url, http_status_code, error_message }` for future drill-down.
    - Activity events reference the website indirectly — `repository_id` is null because monitoring isn't repo-scoped. `RecentActivityForUserQuery` (spec 018) currently filters by `whereHas('repository.project')`, so monitoring events would be filtered OUT today. **Extend that query** to also include events whose source is `'monitoring'` AND whose `metadata->website_id` resolves to a website under one of the user's projects.

- **`App\Domain\Monitoring\Queries\GetWebsitePerformanceSummaryQuery`** — count-based uptime aggregate.
    - `execute(Website $website): array` returns `{ uptime_24h, uptime_7d, uptime_30d, last_incident_at }`.
    - Each `uptime_*` is a float 0–100 (rounded to 2 decimals) or `null` when no checks landed in the window.
    - **Definition**: `successful_checks / total_checks * 100`, where `successful = status IN ('up', 'slow')`. Slow counts as up — a successful response that was slow is still uptime.
    - **Window**: `checked_at >= now() - 24h / 7d / 30d`.
    - `last_incident_at` is the `checked_at` of the most recent `down` or `error` check, or `null` if the monitor has never failed.
    - Three count queries per call (24h / 7d / 30d totals + a single "successful" lookup per window) plus the last-incident lookup. Cheap at phase-1 scale; cache later if needed.

- **Show page integration.**
    - `WebsiteController::show` injects `GetWebsitePerformanceSummaryQuery` and adds `summary` to the Inertia payload.
    - `Pages/Monitoring/Websites/Show.vue` renders a stats strip in the header dl: 24h / 7d / 30d uptime % + "Last incident X ago". `null` rates render as `—%`.

- **Tests** (Pest/PHPUnit):
    - `DispatchDueWebsiteChecksJobTest` — Queue::fake'd; due websites dispatch a per-website job, undue ones don't.
    - `RunWebsiteCheckJobTest` — Http::fake'd; persists a check + updates the website's `last_*` fields.
    - `RecordWebsiteCheckActionTest` — extend with: emits incident event on healthy→failed, emits recovery event on failed→healthy, emits incident on pending→failed, emits NOTHING on steady-state transitions.
    - `GetWebsitePerformanceSummaryQueryTest` — empty windows return null, mixed checks compute correctly per window, slow counts as success, last-incident pinpoints the most-recent failed check.
    - `WebsiteControllerTest::test_show_returns_summary` — extends the existing show test with a `has('summary')` assertion.
    - `RecentActivityForUserQueryTest` — extend with a monitoring-source event whose `metadata.website_id` resolves to the user's project; assert it surfaces alongside GitHub events.

**Out of scope:**

- Reverb broadcast for *check completion* → spec 025 covers the live dashboard updates. Activity events already broadcast via spec 019; that's the only realtime in this spec.
- Overview KPI integration (replacing `MOCK_KPIS['uptime']` with real website uptime data) → spec 025.
- Per-website "incident timeline" with correlated events → future polish.
- Configurable transition rules (e.g. "notify only on down lasting > 5 minutes") → debounce / SLA work for a future spec.
- Slow-as-incident classification — `slow` stays a soft signal, doesn't generate activity events.
- Per-region probes / scheduled probes from multiple geos → roadmap §8.8 "Later".
- DNS / TLS / TTFB timing fields on `WebsiteCheck` → roadmap §8.8 "Later".

## Plan

1. **`DispatchDueWebsiteChecksJob`** + tests — pure dispatcher, no HTTP.
2. **`RunWebsiteCheckJob`** + tests — async wrapper, reuses spec-023 actions.
3. **Schedule binding** — `routes/console.php`.
4. **Extend `RecordWebsiteCheckAction`** to detect category transitions and emit activity events via `CreateActivityEventAction`. Tests for each transition kind.
5. **`GetWebsitePerformanceSummaryQuery`** + tests (count-based; null on empty windows).
6. **Extend `WebsiteController::show`** payload with `summary`. Update controller test.
7. **Update `Pages/Monitoring/Websites/Show.vue`** — uptime stats + last-incident line in the header dl.
8. **Extend `RecentActivityForUserQuery`** to include monitoring-source events whose `metadata.website_id` resolves to a website under the user's projects.
9. **Self-review pass via `superpowers:code-reviewer`**.
10. **Open the PR**.

## Acceptance criteria
- [ ] `DispatchDueWebsiteChecksJob` runs on the every-minute schedule via `Schedule::job(...)->everyMinute()->withoutOverlapping()`.
- [ ] The dispatcher only dispatches per-website jobs for websites whose `last_checked_at + check_interval_seconds <= now()` (or `last_checked_at` is null).
- [ ] `RunWebsiteCheckJob` persists a `WebsiteCheck` row and updates the parent `Website.{status,last_checked_at,last_success_at,last_failure_at}` per the spec-023 actions.
- [ ] `RecordWebsiteCheckAction` emits an `ActivityEvent` only on healthy↔failed category transitions; steady-state runs emit nothing.
- [ ] Incident events: `event_type: website.down`, `severity: danger`. Recovery events: `event_type: website.up`, `severity: success`. Both carry `source: monitoring` + `metadata.website_id`.
- [ ] `GetWebsitePerformanceSummaryQuery` returns `{uptime_24h, uptime_7d, uptime_30d, last_incident_at}`; rate is null when no checks in the window; slow counts as up.
- [ ] `WebsiteController::show` Inertia payload includes the summary; `Show.vue` renders 24h / 7d / 30d % + "Last incident" line.
- [ ] `RecentActivityForUserQuery` includes monitoring-source events for the authenticated user's websites alongside the existing GitHub events.
- [ ] Pint + `php artisan test` (full suite) + `npm run build` clean. CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before opening the PR.

## Files touched
- `app/Domain/Monitoring/Jobs/DispatchDueWebsiteChecksJob.php` — new.
- `app/Domain/Monitoring/Jobs/RunWebsiteCheckJob.php` — new.
- `app/Domain/Monitoring/Actions/RecordWebsiteCheckAction.php` — extend with transition-detection + activity event emission.
- `app/Domain/Monitoring/Queries/GetWebsitePerformanceSummaryQuery.php` — new.
- `app/Domain/Activity/Queries/RecentActivityForUserQuery.php` — extend to include monitoring-source events.
- `app/Http/Controllers/Monitoring/WebsiteController.php` — extend `show` payload with `summary`.
- `routes/console.php` — `Schedule::job(new DispatchDueWebsiteChecksJob)->everyMinute()->withoutOverlapping()`.
- `resources/js/Pages/Monitoring/Websites/Show.vue` — render uptime stats + last-incident line.
- `tests/Feature/Monitoring/DispatchDueWebsiteChecksJobTest.php` — new.
- `tests/Feature/Monitoring/RunWebsiteCheckJobTest.php` — new.
- `tests/Feature/Monitoring/RecordWebsiteCheckActionTest.php` — extend with transition-event assertions.
- `tests/Feature/Monitoring/GetWebsitePerformanceSummaryQueryTest.php` — new.
- `tests/Feature/Monitoring/WebsiteControllerTest.php` — extend `show` test.
- `tests/Feature/Activity/RecentActivityForUserQueryTest.php` — extend with monitoring-source event assertion.

## Work log
Dated notes as work progresses.

### 2026-04-30
- Spec drafted.
- Opened issue [#73](https://github.com/Copxer/nexus/issues/73) and branch `spec/024-scheduled-checks-and-uptime` off `main`.

## Decisions (locked 2026-04-30)
- **Count-based uptime (option A).** `successful / total` per window. Phase-1 simple; switches to duration-based if real users find the count-based number misleading on long check intervals.
- **Transition activity events for incident + recovery only (option A).** Slow is a soft signal — surfaced on the Show page directly but doesn't generate activity events.
- **Fresh on every Show page load (option A).** No caching; three count queries are cheap. Revisit when slow-query logs flag it.
- **Filter due websites in PHP, not SQL.** Dynamic `last_checked_at + check_interval_seconds` predicate is cross-DB awkward (MySQL `DATE_ADD` vs SQLite `datetime(...)`); the in-PHP loop is sub-millisecond at phase-1 scale.
- **Activity event source field `monitoring`.** Free-string column; no migration needed.
- **`RecentActivityForUserQuery` extended (not branched).** One query that handles both repo-scoped and monitoring-scoped events; cheaper than two queries + merge.

## Open questions / blockers
- **`metadata->website_id` query syntax.** SQLite + MySQL both support `JSON_EXTRACT`; Laravel's query builder has `->where('metadata->website_id', ...)` shorthand. Confirm both DB drivers honor it during implementation; fall back to a raw clause if not.
- **Activity event title localisation.** Hard-coded English for phase-1; matches spec 019's existing pattern. i18n is a phase-9 polish.
