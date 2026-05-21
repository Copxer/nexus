---
spec: hosts-ui
phase: 6
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-05-21
updated: 2026-05-21
---

# 028 — Hosts UI (telemetry display)

## Goal
Render the telemetry that 027 ingests. Specs 026 + 027 stood up the data layer
(`hosts`, `containers`, `host_metric_snapshots`, `container_metric_snapshots`),
the CRUD pages, and the `POST /agent/telemetry` endpoint — but the host detail
page still shows a dashed "Coming in spec 027 / 028" placeholder and the index
is a metadata-only table. 028 closes that gap: after this spec a user opens a
host and sees its current CPU / memory / disk / load / network, a short
CPU+memory history chart, and the table of containers with per-container
CPU / memory. The index gains an at-a-glance CPU / memory column.

Roadmap refs: §8.7 Docker Hosts ("UX Requirements" — host cards, CPU/memory
bars, status badges, running container count, last seen, drill-down container
list, container stats chart), §19 Phase 6.

## Scope

**In scope:**

- **Host Show page** (`Monitoring/Hosts/Show.vue`) — replace the placeholder
  card (lines ~237–247) with three real sections:
  - *Current metrics panel* — the latest `host_metric_snapshot`: CPU %, a
    memory used/total bar, a disk used/total bar, load average, and network
    rx/tx (humanised bytes). Sits alongside the existing host facts
    (`cpu_count`, `os`, `docker_version`).
  - *Host metrics chart* — a CPU % and memory % `Sparkline` (reuse
    `Components/Dashboard/Sparkline.vue` from spec 025) over the last 50
    `host_metric_snapshots`, oldest→newest.
  - *Container table* — every `Container` for the host: name, `image:tag`,
    status / state, health badge, CPU %, memory usage/limit (+ percent).
    Reuse `StatusBadge`.
- **Host Index page** (`Monitoring/Hosts/Index.vue`) — add a compact CPU /
  memory column per row, sourced from each host's latest snapshot. Keep the
  table layout (consistent with `Monitoring/Websites/Index.vue`; the roadmap's
  "host cards" is a visual nicety, not worth diverging the established index
  pattern for).
- **Empty / waiting states:**
  - Host that has never reported telemetry (no snapshot rows, `status =
    pending`) → "Waiting for first telemetry" panel pointing at the agent
    token panel below.
  - Host online but zero containers → "No containers reported" row in the
    container table.
  - `<2` snapshots → the chart renders a "not enough data yet" placeholder
    (mirrors spec 025's Sparkline guard).
- **Backend:**
  - `Host::latestMetricSnapshot()` — a `hasOne()->latestOfMany('recorded_at')`
    relation so the index can eager-load one snapshot per host without an N+1.
  - `app/Domain/Docker/Queries/GetHostTelemetryQuery.php` — for Show: returns
    the latest host snapshot, the last 50 snapshots (chart series), and the
    container list with each container's latest stats. One query class so the
    controller stays thin.
  - `HostController@index` eager-loads `latestMetricSnapshot`; `@show` calls
    the query. `transform()` (or a sibling shape method) gains the metric
    fields. Container memory_percent is already computed at ingest time
    (027's `SyncContainerSnapshotsAction`) — read it, don't recompute.
- **Components:**
  - `resources/js/Components/Hosts/HostMetricsPanel.vue` — the current-metrics
    panel (bars + load + network).
  - `resources/js/Components/Hosts/ContainerTable.vue` — the container table.
- **Realtime (Reverb).** The Host Show page updates without a reload when fresh
  telemetry lands, mirroring spec 025's `WebsiteCheckRecorded` pattern:
  - `app/Events/HostTelemetryRecorded.php` — a `ShouldBroadcastNow` event on a
    private `users.{id}.hosts` channel, lightweight `{host_id}` payload. The
    recipient `user_id` is pre-resolved (host → project → `owner_user_id`) so
    `broadcastOn()` does no query.
  - Dispatched from 027's `IngestHostTelemetryAction` **after** the DB
    transaction commits (`DB::afterCommit` / `ShouldDispatchAfterCommit`) so
    the browser never partial-reloads ahead of the write.
  - `routes/channels.php` — authorize `users.{id}.hosts` (own-user only),
    matching the existing `users.{id}.{activity,deployments,monitoring}`
    entries.
  - `Show.vue` subscribes via Echo, filters client-side by `host_id`, and
    partial-reloads the `host` + `telemetry` props on each pulse. A small
    "Live updates offline" pill shows when the socket is down (same treatment
    as the activity rail). Index stays page-load only — realtime is the detail
    page's concern, consistent with spec 025 (website *show* only).
- **Tests:** feature tests for Show (renders metrics + container rows; the
  waiting state when no snapshot exists; the no-containers state) and Index
  (renders the CPU/memory column from the latest snapshot; host with no
  telemetry shows blank/placeholder, not a crash); a broadcast test that
  `HostTelemetryRecorded` fires on telemetry ingest and resolves the host
  owner's channel. Sibling-isolation already covered by 026's
  `HostControllerTest`.

**Out of scope:**

- Host offline detection, the `pending|online|offline` heartbeat-timeout
  transition, and `host.offline` / `host.recovered` activity events — **029**.
- The Overview "Hosts" KPI (still proxied off `Repository::count()`) — **029**.
- Host event log (roadmap §8.7 UX) — it's the per-host slice of the activity
  feed, and depends on 029's host activity events.
- Per-container drill-down page and per-container metric history charts. 028
  shows the container table with *current* stats only.
- Container removal / stale-row sweep — deferred (027 open question).
- Project "Hosts" tab — already wired in fix #82; 028 touches it only if the
  shared `projectHosts` prop shape changes (it should not).

## Plan

1. **`Host::latestMetricSnapshot` relation.** In `app/Models/Host.php`:
   ```php
   public function latestMetricSnapshot(): HasOne
   {
       return $this->hasOne(HostMetricSnapshot::class)->latestOfMany('recorded_at');
   }
   ```
   Confirm `hostMetricSnapshots()` (hasMany) and `containers()` already exist
   from 026; add whichever is missing.

2. **`GetHostTelemetryQuery`.** New `app/Domain/Docker/Queries/GetHostTelemetryQuery.php`.
   `execute(Host $host): array` returns:
   - `current` — the latest `host_metric_snapshot` as an array (or `null`).
   - `series` — last 50 snapshots `recorded_at` ASC, each `{cpu_percent,
     memory_percent, recorded_at}`. `memory_percent` = `memory_used_mb /
     memory_total_mb * 100` when both present, else `null` (leading-null skip
     + carry-forward is the Sparkline's job, per spec 025).
   - `containers` — the host's `containers` ordered by `name`, each with
     `{container_id, name, image, image_tag, status, state, health_status,
     cpu_percent, memory_usage_mb, memory_limit_mb, memory_percent,
     last_seen_at}`.

3. **`HostController`.**
   - `index` — add `latestMetricSnapshot` to the `with([...])`; extend
     `transform()` with `cpu_percent` + `memory_percent` pulled off the
     relation (nullable when the host has never reported).
   - `show` — call `GetHostTelemetryQuery` and pass a `telemetry` prop
     (`{current, series, containers}`) alongside the existing `host` prop.
     Keep the auth + `canUpdate/canDelete/canManageTokens` props as-is.

4. **`HostMetricsPanel.vue`.** Props: `current` (the latest snapshot or null).
   Renders CPU % , memory used/total bar, disk used/total bar, load average,
   network rx/tx (humanised). When `current` is null, render nothing — the
   page-level waiting state owns the empty case.

5. **`ContainerTable.vue`.** Props: `containers` (array). Renders a table;
   per-row health/state via `StatusBadge` tone. Empty array → a single
   "No containers reported" row.

6. **`Show.vue`.** Replace the placeholder block. Order: host facts +
   `HostMetricsPanel` → metrics `Sparkline` card → `ContainerTable` → existing
   `AgentTokenPanel`. When `telemetry.current` is null show the "Waiting for
   first telemetry" panel instead of the metrics panel + chart.

7. **`Index.vue`.** Add a CPU / memory column — small inline bars or `NN%`
   text from `host.cpu_percent` / `host.memory_percent`. Dash when null.

8. **`hostStyles.ts`.** Reuse the existing `hostStatusTone()`. Add a
   `containerHealthTone()` map if container `health_status` needs its own
   tone set (`healthy|unhealthy|starting|none`); otherwise reuse status tones.

9. **Realtime event.** New `app/Events/HostTelemetryRecorded.php` —
   `ShouldBroadcastNow`, constructor takes `(int $hostId, int $ownerUserId)`,
   `broadcastOn()` returns `new PrivateChannel("users.{$this->ownerUserId}.hosts")`,
   `broadcastWith()` returns `['host_id' => $this->hostId]`. In 027's
   `IngestHostTelemetryAction`, resolve the owner once
   (`$host->project->owner_user_id`) and dispatch the event via
   `DB::afterCommit(...)` (or implement `ShouldDispatchAfterCommit`) so it
   never races the write. Authorize `users.{id}.hosts` in
   `routes/channels.php` — `(int) $user->id === (int) $id`, copying the
   shape of the existing `users.{id}.monitoring` entry.

10. **Echo subscription.** In `Show.vue`, subscribe to
    `users.{ownerId}.hosts` via Echo (the owner id rides in as a prop or is
    read from the shared auth prop), filter pulses by `host_id`, and on a
    match call `router.reload({ only: ['host', 'telemetry'] })`. Track socket
    connectivity and render a "Live updates offline" pill when down — reuse
    the pattern in `lib/useActivityFeed.ts` / the activity rail. Tear the
    subscription down on unmount.

11. **Tests.** `tests/Feature/Monitoring/HostControllerTest.php` (extend) or a
    new `HostTelemetryViewTest.php`:
    - Show renders `telemetry.current` + container rows when snapshots exist.
    - Show renders the waiting state when the host has no snapshots.
    - Show renders the no-containers state when snapshots exist but no
      containers.
    - Index `transform` exposes `cpu_percent` / `memory_percent` from the
      latest snapshot, and `null` for a host that never reported.
    - `GetHostTelemetryQuery` unit test: `series` ordering + `memory_percent`
      computation + 50-row cap.
    - Broadcast test (`Event::fake()`): a telemetry ingest dispatches
      `HostTelemetryRecorded` on the host owner's `users.{id}.hosts` channel.

## Acceptance criteria
- [ ] Host Show page renders current CPU / memory / disk / load / network from
      the latest snapshot, a CPU+memory history sparkline, and a container
      table — no placeholder card remains.
- [ ] A host that has never reported telemetry shows a "Waiting for first
      telemetry" state, not an error or empty crash.
- [ ] A host with telemetry but no containers shows a "No containers reported"
      state.
- [ ] Host Index shows a CPU / memory glance per row; hosts with no telemetry
      render a dash, not a crash.
- [ ] No N+1 on the index (latest snapshot eager-loaded via `latestOfMany`).
- [ ] A telemetry ingest broadcasts `HostTelemetryRecorded` on the host
      owner's `users.{id}.hosts` channel; the Show page partial-reloads
      `host` + `telemetry` on the pulse without a manual refresh.
- [ ] Show page shows a "Live updates offline" pill when the socket is down;
      page-load reads still surface the latest telemetry.
- [ ] Sidebar `Hosts` and the project `Hosts` tab continue to work (regression
      check — both were wired pre-028).
- [ ] Pint clean, `php artisan test` green (new tests added), `npm run build`
      clean.

## Files touched
Fill in as work progresses.

- `app/Models/Host.php` — add `latestMetricSnapshot` relation
- `app/Domain/Docker/Queries/GetHostTelemetryQuery.php` — new
- `app/Events/HostTelemetryRecorded.php` — new (ShouldBroadcastNow)
- `app/Domain/Docker/Actions/IngestHostTelemetryAction.php` — dispatch `HostTelemetryRecorded` after commit
- `routes/channels.php` — authorize `users.{id}.hosts`
- `app/Http/Controllers/Monitoring/HostController.php` — index eager-load + show telemetry prop + transform fields
- `resources/js/Pages/Monitoring/Hosts/Show.vue` — replace placeholder with metrics + chart + container table + Echo subscription
- `resources/js/Pages/Monitoring/Hosts/Index.vue` — CPU/memory column
- `resources/js/Components/Hosts/HostMetricsPanel.vue` — new
- `resources/js/Components/Hosts/ContainerTable.vue` — new
- `resources/js/lib/hostStyles.ts` — add `containerHealthTone()` if needed
- `tests/Feature/Monitoring/HostControllerTest.php` — extend (or new `HostTelemetryViewTest.php`)
- `tests/Unit/Domain/Docker/GetHostTelemetryQueryTest.php` — new
- `tests/Feature/Monitoring/HostTelemetryBroadcastTest.php` — new

## Work log

### 2026-05-21
- Spec drafted for review.
- Realtime (Reverb live updates) pulled into scope per review.
- Issue [#85](https://github.com/Copxer/nexus/issues/85) opened, branch `spec/028-hosts-ui` cut off `main`.

## Open questions / blockers
- **Realtime — resolved (in scope).** Pulled into 028 per review: the Host
  Show page subscribes to a `users.{id}.hosts` Reverb channel and
  partial-reloads on each telemetry pulse, mirroring spec 025's
  `WebsiteCheckRecorded`. The Index stays page-load only.
- **Index: table vs cards.** Roadmap §8.7 UX says "host cards". 028 keeps the
  table for consistency with the websites index. If we want cards, that's a
  visual-reference call worth making explicitly.
- **Container table depth.** 028 shows current per-container stats only. Per
  the roadmap a "container stats chart" exists — deferred to a later spec
  since `container_metric_snapshots` history is already being persisted.
