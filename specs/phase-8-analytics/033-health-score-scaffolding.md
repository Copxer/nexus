---
spec: health-score-scaffolding
phase: 8
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-03
updated: 2026-06-04
---

# 033 — Health-score scaffolding

## Goal
Activate Phase 8 by lighting up the dormant `projects.health_score`
column. A `ComputeProjectHealthScoreAction` runs the §14.2 weighted
deduction formula against the data Phases 4–7 already collect; a
scheduled job recomputes every active project every 5 minutes; a
single `ActivityEventCreated` listener queues a per-project recompute
within seconds of any transition that should move the score; a
`HealthScoreUpdated` broadcast on `users.{id}.dashboard` makes
Overview react without a manual reload. After 033, every project
shows a real score with the correct band label; the analytics page
(034) and Overview prioritization (035) consume that score.

Roadmap refs: §Phase 8 acceptance criteria ("Project health score
reflects real system signals"), §14.2 weights + bands, §6.2 Action
class pattern, §10 dashboard data sources.

## Scope

**In scope:**

- **Calculation.**
  - `app/Enums/HealthScoreBand.php` — `healthy | good | degraded |
    warning | critical`, with `::fromScore(int $score): self` mapping
    per §14.2 (90–100 / 70–89 / 50–69 / 30–49 / 0–29) plus `label()`
    + `badgeTone()` for `StatusBadge`/Overview chip consumption
    (mirrors `AlertSeverity::badgeTone()` shape).
  - `app/Domain/Analytics/Actions/ComputeProjectHealthScoreAction.php`
    — `execute(Project $project): int`. Starts at 100, applies §14.2
    deductions, clamps `[0, 100]`. Reads from:
    - `Alert::query()` for active (open + acknowledged) critical /
      warning rows scoped to the project.
    - `WebsiteCheck::query()` joined to `Website` for "response time
      above threshold" (last hour mean) and "down" (latest check is
      `failed`).
    - `Host::query()` for "host offline" (`status = offline`) +
      container join for "container unhealthy".
    - `ActivityEvent::query()` for `workflow.failed` on default
      branch within the last 24h ("failed deployment").
    - `ActivityEvent::query()` for `github.sync.failed` if present
      (silently zero-weighted if the event type doesn't exist yet).
    Order: deduct, clamp, return. No DB write here — the action is
    a pure query + arithmetic; callers persist.
  - §14.2 weights live as named constants on the action so 034 / 035
    / future tuning UI can read them without copy-paste.

- **Persistence + recompute.**
  - `app/Domain/Analytics/Actions/RefreshProjectHealthScoreAction.php`
    — `execute(Project $project): int`. Calls
    `ComputeProjectHealthScoreAction`, writes `health_score` +
    `updated_at` only if the new value differs from the stored one
    (avoid no-op writes + spurious broadcasts), dispatches
    `HealthScoreUpdated::dispatch($project->id, $project->owner_user_id,
    $newScore)` on a change. Returns the new score for the caller's
    logs.
  - `app/Domain/Analytics/Jobs/RecomputeProjectHealthScoresJob.php`
    — `ShouldQueue + ShouldBeUnique`, queued to the default queue,
    `WithoutOverlapping($projectId)` middleware. Constructor takes
    `int $projectId`. Calls
    `RefreshProjectHealthScoreAction::execute()`.
  - `app/Domain/Analytics/Jobs/RecomputeAllProjectHealthScoresJob.php`
    — scheduled wrapper. Iterates active projects (chunked by 100)
    and dispatches one `RecomputeProjectHealthScoresJob` per row.
    "Active" = `last_activity_at > now()->subDays(7)` OR
    `health_score IS NULL` (first-run sweep). Sleeping projects don't
    consume scheduler time.
  - `app/Console/Kernel.php` — schedule
    `RecomputeAllProjectHealthScoresJob` every 5 minutes,
    `withoutOverlapping(10)` (the 10-min lock guards a slow run).

- **Transition hooks.**
  - `app/Listeners/RecomputeProjectHealthOnActivity.php` — subscribes
    to `ActivityEventCreated`. Maps the event's `source` →
    `project_id` (the `metadata.project_id` is set by all relevant
    emitters per spec 030's audit). Whitelists the event types that
    can move the score: `alert.triggered`, `alert.resolved`,
    `website.down`, `website.recovered`, `website.slow`,
    `host.offline`, `host.recovered`, `workflow.failed`,
    `github.sync.failed`. Ignores everything else (e.g. raw
    `host.metrics.recorded` ticks). For whitelisted types, dispatches
    `RecomputeProjectHealthScoresJob($projectId)` — the job's
    `WithoutOverlapping` + `ShouldBeUnique` middleware naturally
    throttles bursts (e.g. a website that flips down then up twice
    in a second collapses to one recompute).
  - `app/Providers/EventServiceProvider.php` — register the listener.

- **Broadcast.**
  - `app/Events/HealthScoreUpdated.php` —
    `ShouldBroadcastNow + ShouldDispatchAfterCommit` on
    `users.{ownerUserId}.dashboard`. Payload:
    `{ project_id, health_score, band }`. `band` is the string
    value of `HealthScoreBand` so the frontend doesn't recompute.
    Mirrors `AlertResolved` line-for-line.
  - `routes/channels.php` — authorize `users.{id}.dashboard` (own-user
    only) next to the existing `activity / monitoring / hosts /
    alerts` entries.

- **Inertia + UI.**
  - `ProjectController::transform()` — already exposes
    `health_score`; add `health_band` (string from
    `HealthScoreBand::fromScore($score)->value`) when score is
    non-null. Null score stays null in both fields (renders as a
    "—" placeholder client-side).
  - `app/Domain/Overview/Queries/GetOverviewDashboardQuery.php` —
    every project row returned in `riskyProjects` / `recentProjects`
    carries `health_score` + `health_band`. Sort key for any
    `health_score`-based panel is handled in spec 035; 033 just
    surfaces the field.
  - `resources/js/Pages/Projects/Show.vue` — render a
    `<HealthScoreBadge>` near the project title. Component lives at
    `resources/js/Components/Project/HealthScoreBadge.vue` —
    `props: { score: number|null, band: string|null }`; renders
    `<StatusBadge :tone="bandToTone(band)" :label="`${score}/100 ${bandLabel}`" />`.
    Null score renders the muted "—" placeholder.
  - `resources/js/Pages/Overview.vue` — subscribe to
    `users.{userId}.dashboard` on mount; `.HealthScoreUpdated` calls
    `router.reload({ only: ['kpis', 'projects'] })`. The "Live
    updates offline" pill pattern from spec 028 applies if the user
    drops Pusher connection.

- **Tests.**
  - **Action-level** (`tests/Unit/Domain/Analytics/`):
    `ComputeProjectHealthScoreActionTest` — base 100 with no
    deductions; one critical alert deducts 30; one warning alert
    deducts 15; combined alerts stack; website down + slow stack
    (-20 + -10 = -30); host offline -15; container unhealthy -10;
    workflow.failed on default branch within 24h -20; clamps to 0
    on a worst-case stack; clamps to 100 baseline; cross-project
    isolation (alerts on project B don't touch project A's score).
  - `RefreshProjectHealthScoreActionTest` — writes the new score;
    does not write if the score is unchanged (no spurious
    `updated_at`); dispatches `HealthScoreUpdated` only on change;
    null → first score dispatches.
  - **Listener** (`tests/Feature/Listeners/`):
    `RecomputeProjectHealthOnActivityTest` — whitelisted event types
    queue the job once; non-whitelisted types do not; missing
    `project_id` in metadata is a silent no-op (defensive).
  - **Job** (`tests/Unit/Domain/Analytics/Jobs/`):
    `RecomputeAllProjectHealthScoresJobTest` — dispatches one
    per-project job per active project; skips projects with no
    `last_activity_at > now-7d` and a non-null `health_score`.
  - **Event** (`tests/Feature/Events/`): `HealthScoreUpdatedTest` —
    implements `ShouldBroadcastNow + ShouldDispatchAfterCommit`;
    `broadcastOn` returns the dashboard channel; `broadcastWith`
    carries `{project_id, health_score, band}`; null owner short-
    circuits to `[]`.
  - **Channel auth**: `tests/Feature/Channels/UsersDashboardChannelTest.php`
    — own user authorized; other user rejected.
  - **Inertia transform**: `ProjectControllerShowTest` — payload
    carries `health_score` + `health_band`; null score → both null.

**Out of scope:**

- `/analytics` page — **034**.
- Real-data activity heatmap on Overview — **035**.
- Overview "Risky projects" sort by `health_score` ascending — **035**
  (033 just exposes the field on every project payload; 035 adds the
  panel).
- User-tunable score weights — deferred (polish spec).
- PR cycle time / stale PR count / open issues trend score signals
  — deferred (need Phase 4 GitHub Issues + PR webhook ingestion that
  doesn't exist; the formula has no weight for them in §14.2 anyway).
- The `dashboard` Reverb channel hosting other broadcasts beyond
  `HealthScoreUpdated` — 035 may add `HeatmapUpdated` on the same
  channel; 034 doesn't need realtime.
- Backfill of pre-existing `last_activity_at`-null projects — the
  scheduled `RecomputeAllProjectHealthScoresJob`'s
  `health_score IS NULL` clause sweeps them on its next tick after
  this spec lands.

## Plan

1. **`HealthScoreBand` enum.** Five cases per §14.2 with `fromScore` +
   `label` + `badgeTone`. Cover with a tiny enum test.

2. **`ComputeProjectHealthScoreAction`.** Pure read-only. Inject
   nothing — just `\Illuminate\Database\ConnectionInterface` if any
   query needs raw SQL (probably not — Eloquent + `whereHas` + a few
   `selectRaw` aggregates are enough). Constants at the top of the
   class:
   ```php
   private const DEDUCT_ALERT_CRITICAL = 30;
   private const DEDUCT_ALERT_WARNING  = 15;
   private const DEDUCT_DEPLOY_FAILED  = 20;
   private const DEDUCT_WEBSITE_SLOW   = 10;
   private const DEDUCT_WEBSITE_DOWN   = 20;
   private const DEDUCT_HOST_OFFLINE   = 15;
   private const DEDUCT_CONTAINER_BAD  = 10;
   private const DEDUCT_GH_SYNC_FAIL   = 5;
   ```
   Each query is one method (`alertDeductions`, `websiteDeductions`,
   etc.); `execute` sums them, clamps, returns. Use
   `Carbon::now()->subDay()` for the 24h windows.

3. **`RefreshProjectHealthScoreAction`.** Wraps the compute action,
   diffs against `$project->health_score`, persists only on change
   (`$project->forceFill(['health_score' => $new])->save()`),
   dispatches `HealthScoreUpdated` only on change. Returns the new
   value.

4. **`HealthScoreUpdated` event.** Constructor:
   `(int $projectId, ?int $ownerUserId, int $score, string $band)`.
   `broadcastWith` returns `['project_id' => $this->projectId,
   'health_score' => $this->score, 'band' => $this->band]`.
   `broadcastAs` returns `'HealthScoreUpdated'`. `broadcastOn` returns
   `$this->ownerUserId === null ? [] : [new PrivateChannel("users.{$this->ownerUserId}.dashboard")]`.

5. **Channel auth.** `routes/channels.php` add:
   ```php
   Broadcast::channel('users.{userId}.dashboard',
       fn (User $user, int $userId) => (int) $user->id === $userId);
   ```

6. **Job + scheduler.**
   - `RecomputeProjectHealthScoresJob(int $projectId)` —
     `WithoutOverlapping($projectId)` + `ShouldBeUnique` with
     `uniqueFor(60)`. Resolves `RefreshProjectHealthScoreAction` from
     the container; calls `execute($project)`; logs the before/after.
   - `RecomputeAllProjectHealthScoresJob` — chunks
     `Project::query()->where(fn ($q) => $q->where('last_activity_at',
     '>', now()->subDays(7))->orWhereNull('health_score'))` by 100;
     dispatches one job per row.
   - `app/Console/Kernel.php`:
     ```php
     $schedule->job(new RecomputeAllProjectHealthScoresJob)
         ->everyFiveMinutes()
         ->withoutOverlapping(10);
     ```

7. **Listener.** `RecomputeProjectHealthOnActivity` —
   `handle(ActivityEventCreated $event)`. Resolves the activity row,
   reads `$event->activityEvent->event_type`, returns early on
   non-whitelisted types. Reads `metadata.project_id`. Dispatches
   `RecomputeProjectHealthScoresJob::dispatch($projectId)`. Register in
   `EventServiceProvider::$listen`.

8. **Inertia transform.** `ProjectController::transform()` — adds
   `health_band` next to `health_score`. Null guard:
   `score === null ? null : HealthScoreBand::fromScore($score)->value`.

9. **UI components.**
   - `HealthScoreBadge.vue` — `score: number | null`, `band: string |
     null`. Maps band → tone (`healthy → success`, `good → info`,
     `degraded → warning`, `warning → warning`, `critical → danger`).
     Reuses `StatusBadge`.
   - `Pages/Projects/Show.vue` — render `<HealthScoreBadge>` next to
     the project title.
   - `Pages/Overview.vue` — Echo subscription on
     `users.{userId}.dashboard` for `.HealthScoreUpdated`; calls
     `router.reload({ only: ['kpis', 'projects'] })`. Hooks alongside
     the existing `users.{id}.activity` subscription (so the
     connection-lost pill applies to both).

10. **Tests.** Per the test list in **In scope**. Use the existing
    `Alert::factory()` / `Website::factory()` / `Host::factory()` /
    `ActivityEvent::factory()` shapes. The compute-action test is the
    heaviest — twelve cases covering each weight + clamps + isolation.

11. **Pint clean, full suite + build pass, self-review with
    `superpowers:code-reviewer`, PR.**

## Acceptance criteria
- [ ] `projects.health_score` is non-null on every active project
      within one scheduler tick of this spec landing.
- [ ] A new critical alert on a project drops that project's score
      by 30 within the next listener-triggered recompute (<5s under
      a warm queue worker).
- [ ] A `website.recovered` transition that should restore the score
      raises it back within the next listener-triggered recompute.
- [ ] `HealthScoreUpdated` broadcasts on `users.{ownerId}.dashboard`
      only when the persisted score actually changes (no spurious
      events on no-op recomputes).
- [ ] Overview re-fetches `kpis` + `projects` props on a
      `.HealthScoreUpdated` pulse; the badge updates without a
      manual reload.
- [ ] Project Show page renders the score badge with the correct
      band label and tone.
- [ ] Sleeping projects (`last_activity_at > 7d` ago AND
      `health_score` non-null) are skipped by the scheduled sweep.
- [ ] Score clamps to `[0, 100]` under the worst-case stack of
      deductions.
- [ ] Pint clean. `php artisan test` green. `npm run build` clean.

## Files touched
List of created/modified files. Fill in as work progresses.

- `app/Enums/HealthScoreBand.php` — created
- `app/Domain/Analytics/Actions/ComputeProjectHealthScoreAction.php` — created
- `app/Domain/Analytics/Actions/RefreshProjectHealthScoreAction.php` — created
- `app/Domain/Analytics/Jobs/RecomputeProjectHealthScoresJob.php` — created
- `app/Domain/Analytics/Jobs/RecomputeAllProjectHealthScoresJob.php` — created
- `app/Listeners/RecomputeProjectHealthOnActivity.php` — created
- `app/Events/HealthScoreUpdated.php` — created
- `app/Providers/EventServiceProvider.php` — register listener
- `app/Console/Kernel.php` — schedule the sweep
- `routes/channels.php` — `users.{id}.dashboard` auth
- `app/Http/Controllers/ProjectController.php` — `health_band` in transform
- `resources/js/Components/Project/HealthScoreBadge.vue` — created
- `resources/js/Pages/Projects/Show.vue` — render the badge
- `resources/js/Pages/Overview.vue` — Echo subscription
- `tests/Unit/Domain/Analytics/ComputeProjectHealthScoreActionTest.php` — created
- `tests/Unit/Domain/Analytics/RefreshProjectHealthScoreActionTest.php` — created
- `tests/Unit/Domain/Analytics/Jobs/RecomputeAllProjectHealthScoresJobTest.php` — created
- `tests/Feature/Listeners/RecomputeProjectHealthOnActivityTest.php` — created
- `tests/Feature/Events/HealthScoreUpdatedTest.php` — created
- `tests/Feature/Channels/UsersDashboardChannelTest.php` — created
- `tests/Feature/Http/Controllers/ProjectControllerShowTest.php` — `health_band` assertion

## Work log
Dated notes as work progresses.

### 2026-06-03
- Drafted from `_template.md`. Phase 8 decomposition + GH-metrics
  deferral + broadcast-in-033 approach confirmed with user before
  drafting.

### 2026-06-04
- Branch `spec/033-health-score-scaffolding` cut off main.
- Tracking issue #97.
- Scope shipped as drafted (no late edits requested).

## Open questions / blockers

- **Container unhealthy signal.** Phase 6 ships `host_metric_snapshots`
  + `container_metric_snapshots` but I haven't verified there's a
  durable per-container "unhealthy" flag (vs. a derived "latest CPU
  > threshold"). If the signal isn't trivially queryable, the impl
  can fall back to "no deduction yet" with a TODO referencing a
  follow-up — the §14.2 formula then under-deducts by 10 in the
  worst case, which is acceptable for shipping the rest.
- **`github.sync.failed` event type.** Confirm whether this exact
  event type lands in `activity_events.event_type`. If not, the
  deduction silently zeros out — same fallback as above.
- **Scheduler cadence.** Every-5-minutes is a guess based on Phase 7's
  alert detection cadence. If transition-driven recomputes prove
  fast and reliable, the sweep can drop to every-15-minutes in a
  follow-up. Not a 033 blocker.
