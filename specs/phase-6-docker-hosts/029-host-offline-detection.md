---
spec: host-offline-detection
phase: 6
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-05-26
updated: 2026-05-26
---

# 029 — Host offline detection + activity events + Overview KPI wiring

## Goal
Close out phase 6. A host that stops reporting telemetry past its heartbeat
timeout flips to `offline` and emits a `host.offline` activity event; the
first telemetry tick after that flips it back to `online` and emits
`host.recovered`. The Overview Hosts KPI swaps its long-standing
`Repository::count()` proxy for real host data, and the Overview "Container
Hosts" detail card replaces its hard-coded `stubHosts` array with the user's
actual hosts.

Roadmap refs: §8.7 Docker Hosts ("Host event log", "Warning state"), §19
Phase 6 acceptance criteria ("Host offline triggers alert after timeout";
phase-level "Host offline emits `host.offline`, recovery emits
`host.recovered`; Overview Hosts KPI reflects real online/offline counts").

## Scope

**In scope:**

- **Offline detection (scheduled).**
  - `app/Domain/Docker/Actions/DetectOfflineHostsAction.php` — finds hosts in
    `status: online` whose `last_seen_at` is older than the configured
    heartbeat timeout, transactionally flips each to `offline`, and emits one
    `host.offline` activity event per host. Pending and archived hosts are
    ignored (they can't go *offline* — they were never online).
  - `app/Domain/Docker/Jobs/DetectOfflineHostsJob.php` — thin queueable that
    calls the action. Mirrors spec 024's `DispatchDueWebsiteChecksJob`.
  - `routes/console.php` — schedule `everyMinute()->withoutOverlapping()`
    next to the existing website-check dispatcher.
  - **Heartbeat timeout: 120 seconds, global default**, via
    `config/hosts.php`'s `'heartbeat_timeout_seconds'` (default 120 = 4× the
    agent's default 30s tick). Per-host configurability is an explicit
    follow-up (see Open questions).
- **Recovery transition.**
  - `IngestHostTelemetryAction::updateHost` already flips any non-online,
    non-archived status to `online`. Extend it to detect the **specific
    offline → online transition** and emit a `host.recovered` activity
    event. Pending → online is *not* a recovery (it's first activation —
    silent, mirroring spec 024's pending → healthy rule).
- **Activity event broadcasting.**
  - `ActivityEventCreated::broadcastOn()` gains a third branch for
    `source: hosts` rows: `metadata.host_id → host → project →
    owner_user_id`, mirroring exactly the `source: monitoring` branch.
- **Overview Hosts KPI (`GetOverviewDashboardQuery::hosts()`).**
  - Drop the `Repository::query()->count()` proxy. Replace with:
    - `online` — `Host::query()->where('status', 'online')` scoped to
      `whereHas('project', owner_user_id = $user->id)`. Same scoping the
      rest of the dashboard query already uses.
    - `offline` (new) — same query with `status: offline`.
    - `new` — `Host::where('created_at', '>=', now()->subWeek())` (user-scoped).
    - `sparkline` — daily `host_metric_snapshots` count over 12 days
      (telemetry volume, parallels the deployments KPI's daily completed-run
      count).
    - `status` — `success` when `offline === 0`, `warning` when `0 <
      offline < online`, `danger` when `online === 0 && offline > 0`,
      `muted` when there are no hosts at all.
  - Add a `cards` array (up to 6 hosts ordered by status priority then name —
    offline first so they're surfaced) with `{id, name, status, cpu_percent,
    memory_percent, last_seen_at}` so the Overview "Container Hosts" card
    can render real data instead of `stubHosts`.
- **Overview frontend (`resources/js/Pages/Overview.vue`).**
  - Delete `stubHosts` and the "4 hosts · mock" header.
  - "Container Hosts" card iterates `dashboard.hosts.cards`. Empty state when
    the user has no hosts: a CTA link to `/monitoring/hosts/create`.
  - KPI card surfaces `online` / `offline` (the `online` line stays as the
    headline; `offline > 0` adds a secondary "N offline" line in danger tone).
- **Activity rail labelling.**
  - `resources/js/types/index.d.ts` + the rail's icon/label maps gain
    entries for `host.offline` (Server icon, danger tone) and
    `host.recovered` (Server icon, success tone). Mirrors how spec 024 added
    `website.down` / `website.up`.
- **Tests:**
  - `DetectOfflineHostsActionTest` (unit) — online host past timeout flips
    + emits `host.offline`; within timeout unchanged; pending unchanged;
    archived unchanged; one event per flipped host; tx integrity (host state
    + event both write or neither).
  - `IngestHostTelemetryActionTest` (extend) — offline → online emits
    `host.recovered`; pending → online emits nothing; online → online emits
    nothing.
  - `ActivityEventCreatedTest` (extend or new) — `source: hosts` row with
    `metadata.host_id` resolves to `users.{owner}.activity` channel; orphan
    host short-circuits to no channels.
  - `GetOverviewDashboardQueryTest` (extend) — `hosts.online/offline/new`
    reflect real Host counts (not Repository); `hosts.cards` ordered by
    status priority then name; sibling-user hosts don't leak.

**Out of scope:**

- Promoting `host.offline` to a row in the `alerts` table or to an
  acknowledge/resolve workflow — Phase 7.
- Per-host heartbeat-timeout configurability (a `heartbeat_timeout_seconds`
  column on `hosts` + a form field on Edit). Open follow-up; phase-1 uses
  the global default.
- Realtime push of the Overview "Container Hosts" card. The card refreshes
  on page load; the activity rail's existing Reverb stream already surfaces
  the user-visible transition. (Overview KPI realtime in general — spec 022
  / 025 left this page-load — so 029 stays consistent.)
- Per-host metric-history charts on Overview. The Overview shows the
  current-snapshot glance; deeper telemetry lives on `Monitoring/Hosts/Show`
  (delivered by spec 028).
- A `degraded` status transition (the enum exists but no spec uses it).
  Reserved for a future "host is reporting but unhealthy" rule.

## Plan

1. **Config.** New `config/hosts.php`:
   ```php
   return [
       'heartbeat_timeout_seconds' => (int) env('HOSTS_HEARTBEAT_TIMEOUT_SECONDS', 120),
   ];
   ```
   Add `HOSTS_HEARTBEAT_TIMEOUT_SECONDS=` to `.env.example` with a comment
   noting the 4× agent-interval rationale.

2. **`DetectOfflineHostsAction`.** New
   `app/Domain/Docker/Actions/DetectOfflineHostsAction.php`:
   - `execute(): int` returns the number of hosts flipped (useful for the
     job's log line and the test).
   - Query: `Host::query()->with('project:id,owner_user_id')
     ->where('status', HostStatus::Online->value)
     ->where('last_seen_at', '<', now()->subSeconds(config('hosts.heartbeat_timeout_seconds')))
     ->get()`.
   - Iterate; for each: open a transaction → `forceFill(['status' =>
     HostStatus::Offline->value])->save()` → call `CreateActivityEventAction`
     with `event_type: host.offline`, `severity: Danger`, `title: "{name}
     went offline"`, `description: "No telemetry in {N} seconds"`, `source:
     hosts`, `metadata: {host_id, last_seen_at, threshold_seconds}`. The
     activity event implicitly fans out to the project owner's
     `users.{id}.activity` channel via the `ActivityEventCreated` change in
     step 4.
   - Late-arriving telemetry is fine: it'll flip status back to online and
     emit `host.recovered` (step 3).

3. **`IngestHostTelemetryAction` extension.** In `updateHost`, capture
   `$wasOffline = $host->status === HostStatus::Offline` *before* the
   forceFill. After the DB::transaction returns (next to the existing
   `HostTelemetryRecorded::dispatch(...)` line), if `$wasOffline`, call
   `CreateActivityEventAction` with `event_type: host.recovered`, `severity:
   Success`, `title: "{name} recovered"`, `description: "Telemetry resumed
   at {ISO recorded_at}"`, `source: hosts`, `metadata: {host_id, recorded_at}`.
   No event for pending → online (matches spec 024's silent first-probe rule).

4. **`ActivityEventCreated` recipient resolution.** Extend the
   `broadcastOn()` switch with a third branch:
   ```php
   if ($this->activityEvent->source === 'hosts') {
       $hostId = $metadata['host_id'] ?? null;
       if ($hostId === null) return [];
       $host = Host::query()->with('project:id,owner_user_id')->find($hostId);
       return $host?->project?->owner_user_id;
   }
   ```
   (Wrapped in the same return shape the other branches use.) The Vue rail
   already renders activity events; no JS change needed beyond the icon /
   label map in step 7.

5. **`DetectOfflineHostsJob`.** Single-job action wrapper, queue `default`,
   `ShouldQueue`. Mirrors `DispatchDueWebsiteChecksJob` shape.

6. **Scheduler.** In `routes/console.php`, beside the existing
   `DispatchDueWebsiteChecksJob` schedule:
   ```php
   Schedule::job(new DetectOfflineHostsJob)
       ->everyMinute()
       ->name('hosts.detect-offline')
       ->withoutOverlapping();
   ```

7. **Activity-rail labels.** `resources/js/types/index.d.ts` (or wherever
   the activity event union lives) adds `'host.offline'` and
   `'host.recovered'`. The rail's icon / label maps gain entries: Server
   icon, danger tone for `host.offline`, success tone for `host.recovered`.

8. **`GetOverviewDashboardQuery::hosts()` rewrite.** Delete the Repository
   proxy. Build the new `online / offline / new / sparkline / status` from
   real Host data. Add a `cards` array: hosts sorted by `(status === 'offline'
   ? 0 : status === 'online' ? 1 : 2, name asc)`, limit 6, fields
   `{id, name, status, cpu_percent, memory_percent, last_seen_at}` (the same
   fields the Hosts Index transform already exposes — eager-load
   `latestMetricSnapshot`).

9. **`Overview.vue`.** Delete the `stubHosts` array. Iterate
   `dashboard.hosts.cards` in the "Container Hosts" card. Empty-state with a
   "Connect your first host" CTA pointing at `monitoring.hosts.create`.
   Update the headline KPI card to show `online` / `offline` from the new
   shape; drop the "· mock" header.

10. **Tests** as enumerated in Scope. Use `Event::fake([ActivityEventCreated::class])`
    or assert on the `activity_events` table rows directly (the codebase's
    spec-024 tests do the latter — mirror).

## Acceptance criteria
- [ ] A host whose `last_seen_at` is older than the configured timeout flips
      from `online` to `offline` within ≤ 60 s of the threshold and a
      `host.offline` activity event lands.
- [ ] The first telemetry tick after an offline host posts flips it to
      `online` and emits exactly one `host.recovered` activity event.
- [ ] Pending → online and online → online ingest paths emit no
      `host.recovered` (silent first activation / steady-state).
- [ ] `host.offline` and `host.recovered` rows broadcast on the project
      owner's `users.{id}.activity` channel; the right-rail picks them up.
- [ ] Overview Hosts KPI displays real online + offline counts (no
      `Repository::count` proxy, no mocks); sibling-user hosts don't leak.
- [ ] Overview "Container Hosts" card renders real hosts from
      `dashboard.hosts.cards` (no `stubHosts`, no "· mock" header); empty
      state CTA points at `monitoring.hosts.create`.
- [ ] Pint clean, `php artisan test` green (new tests added), `npm run build`
      clean.

## Files touched
Fill in as work progresses.

- `config/hosts.php` — new (heartbeat timeout)
- `.env.example` — add `HOSTS_HEARTBEAT_TIMEOUT_SECONDS`
- `app/Domain/Docker/Actions/DetectOfflineHostsAction.php` — new
- `app/Domain/Docker/Jobs/DetectOfflineHostsJob.php` — new
- `app/Domain/Docker/Actions/IngestHostTelemetryAction.php` — emit `host.recovered` on offline→online
- `app/Events/ActivityEventCreated.php` — resolve recipients for `source: hosts`
- `app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php` — rewrite `hosts()`, add `cards`
- `resources/js/Pages/Overview.vue` — drop `stubHosts`, iterate `dashboard.hosts.cards`, KPI shape
- `resources/js/types/index.d.ts` — add `host.offline` / `host.recovered` to the activity event union
- `resources/js/Components/Activity/ActivityFeedItem.vue` (or wherever the icon/label map lives) — register the two new types
- `routes/console.php` — schedule `DetectOfflineHostsJob`
- `tests/Unit/Domain/Docker/DetectOfflineHostsActionTest.php` — new
- `tests/Unit/Domain/Docker/IngestHostTelemetryActionTest.php` — extend (recovery + silent paths)
- `tests/Feature/Activity/ActivityEventCreatedTest.php` — extend (hosts source resolution)
- `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` — extend (real host KPI + cards)
- `tests/Feature/Console/DetectOfflineHostsScheduleTest.php` — new (asserts the schedule is registered)

## Work log

### 2026-05-26
- Spec drafted for review.
- Issue [#87](https://github.com/Copxer/nexus/issues/87) opened, branch `spec/029-host-offline-detection` cut off `main`.

## Open questions / blockers
- **Heartbeat timeout: global vs per-host.** 029 ships a global 120s default
  via config. A per-host `heartbeat_timeout_seconds` column + Edit-form field
  is a small follow-up if anyone wants to tune chatty vs sparse fleets
  individually. Default chosen as 4× the agent's default 30s interval —
  tight enough to catch silent failures within ~2 minutes, loose enough to
  ride out a single missed tick + network blip.
- **Overview "Container Hosts" card depth.** 029 shows up to 6 hosts with
  current CPU / memory glance. The roadmap's full vision (per-host card
  with real-time bars) lives behind `/monitoring/hosts` (delivered by 028);
  Overview stays a summary surface.
- **Sparkline metric.** Daily `host_metric_snapshots` count = telemetry
  volume, parallel to the deployments KPI's daily completed-run count. An
  alternative ("hours each host spent online over the last 12 days") is
  more meaningful but expensive — defer unless it actually proves useful in
  practice.
- **Realtime KPI refresh.** Out of scope per the Overview pattern set by
  specs 022 / 025. The activity rail's existing realtime stream already
  surfaces the user-visible transitions (`host.offline` / `host.recovered`)
  — the KPI numbers themselves wait for the next page load.
