---
spec: alerts-scaffolding
phase: 7
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-05-26
updated: 2026-05-27
---

# 030 — Alerts scaffolding + transition-based promotion

## Goal
Lay the data + promotion layer for Phase 7. Adds the `alerts` table, the
`Alert` model + policy, three enums, and two actions (`TriggerAlertAction`,
`ResolveAlertAction`). Then wires the existing transition emitters
(`website.down` / `website.up`, `host.offline` / `host.recovered`,
`workflow.failed`) to also promote into `alerts` rows. No UI yet — that
lands in 031. After 030, every transition that would have produced an
activity row also produces a durable, acknowledgeable alert that future
specs can render and act on.

Roadmap refs: §Phase 7 acceptance criteria ("Website down / Failed
deployment / Host offline creates alert"), §8.12 Alerts fields + sources +
statuses + severities, §6.2 Action class pattern.

## Scope

**In scope:**

- **Data layer.**
  - Migration `create_alerts_table` with the fields per roadmap §8.12,
    minus `team_id` (phase-1 is single-tenant, no `teams` table exists
    yet). Composite indexes on `(status, source, source_id)` (idempotency
    lookups) and `(project_id, status)` (index-page filtering).
  - `app/Models/Alert.php` — belongsTo Project, casts (status, severity,
    source enums; `triggered_at`/`acknowledged_at`/`resolved_at`/`last_seen_at`
    datetime; `metadata` array).
  - `database/factories/AlertFactory.php`.
  - `app/Policies/AlertPolicy.php` — `viewAny` / `view` / `update`
    (acknowledge / resolve / mute) / `delete` tied to project ownership,
    mirrors `HostPolicy` / `WebsitePolicy`.
  - Register in `AppServiceProvider::boot()` next to the existing
    `HostPolicy` registration.

- **Enums.**
  - `app/Enums/AlertStatus.php` — `open | acknowledged | resolved | muted`.
  - `app/Enums/AlertSeverity.php` — `info | warning | critical`.
    Includes `badgeTone()` returning `'info' | 'warning' | 'danger'` for
    `StatusBadge` consumption (critical → danger).
  - `app/Enums/AlertSource.php` — `website | docker | deployment | github
    | manual | system`. Subset of roadmap §8.12; `github` reserved for
    future repo-scoped alerts not promoted in this spec.

- **Actions.**
  - `app/Domain/Alerts/Actions/TriggerAlertAction.php` — idempotent:
    queries for an existing `Alert` matching `(source, source_id, type,
    status IN [open, acknowledged])`. If found, bumps `last_seen_at` and
    returns the same row (no duplicate, no second activity event). If
    not, inserts a new `Alert` with `status: open`, then emits an
    `alert.triggered` activity event via `CreateActivityEventAction`.
  - `app/Domain/Alerts/Actions/ResolveAlertAction.php` — closes every
    open or acknowledged `Alert` matching `(source, source_id[, type])`:
    sets `status: resolved`, stamps `resolved_at`, bumps `last_seen_at`.
    For each closed row, emits an `alert.resolved` activity event. Returns
    the count for the caller's logs.

- **Activity routing.** Extend `ActivityEventCreated::broadcastOn()` with
  a fourth branch resolving `source: alerts` events via
  `metadata.alert_id → alert → project → owner_user_id`. Mirrors the
  spec-029 hosts branch line-for-line.

- **Promotion call sites.** Four existing places gain a Trigger / Resolve
  call alongside the activity event they already emit:
  - **`RecordWebsiteCheckAction`** (spec 024 — healthy→failed transition):
    after the existing `website.down` activity event, call
    `TriggerAlertAction(source: website, source_id: website.id, project_id:
    website.project_id, type: 'website.down', severity: critical, title:
    "{name} went down", description: failure summary, metadata: {url,
    http_status_code, error_message})`. On the failed→healthy transition,
    after the `website.up` activity event, call
    `ResolveAlertAction(source: website, source_id: website.id)`.
  - **`DetectOfflineHostsAction`** (spec 029): after the `host.offline`
    activity event, call `TriggerAlertAction(source: docker, source_id:
    host.id, project_id: host.project_id, type: 'host.offline', severity:
    critical, title: "{name} went offline", description: "No telemetry in
    {N}s", metadata: {last_seen_at, threshold_seconds})`.
  - **`IngestHostTelemetryAction`** (spec 029): after the
    `host.recovered` activity event (the explicit offline→online branch),
    call `ResolveAlertAction(source: docker, source_id: host.id)`.
  - **`WorkflowRunWebhookHandler`** (spec 019/020): on a `workflow.failed`
    handler, **only when `run.head_branch === run.repository.default_branch`**,
    call `TriggerAlertAction(source: deployment, source_id: run.id,
    project_id: run.repository.project_id, type: 'workflow.failed',
    severity: warning, title: "Workflow failed on {branch}", description:
    workflow name + actor, metadata: {run_id, branch, actor, html_url})`.
    No auto-resolve — each failed run is its own alert, the user marks
    them resolved manually (031).

- **Tests.**
  - **Action-level** (`tests/Unit/Domain/Alerts/`): TriggerAlert happy
    path inserts row + emits activity; idempotency (second identical
    call returns same row, bumps `last_seen_at`, doesn't emit a second
    activity); ResolveAlert closes open and acknowledged rows; ResolveAlert
    is a no-op when nothing open matches (no spurious activity event);
    source/type discrimination (two alerts with the same source_id but
    different types coexist).
  - **Integration** (extend existing test files): `RecordWebsiteCheckActionTest`
    proves website-down creates an Alert and website-up auto-resolves it;
    `DetectOfflineHostsActionTest` proves the offline flip creates an
    Alert; `IngestHostTelemetryActionTest` proves the recovery transition
    resolves the open host Alert; `WorkflowRunWebhookHandlerTest` proves a
    failed main-branch run creates an Alert and a failed feature-branch
    run does not.
  - **Broadcast routing** (extend `ActivityEventCreatedBroadcastTest`):
    `source: alerts` row with `metadata.alert_id` resolves to
    `users.{owner}.activity`; orphan alert short-circuits to no channels.

**Out of scope:**

- The `/alerts` page UI, filters, severity badges, the "link to affected
  entity" CTA — **031**.
- Acknowledge / resolve / mute controllers + buttons — **031**.
- A dedicated `AlertTriggered` / `AlertResolved` broadcast event +
  `users.{id}.alerts` Reverb channel — **032**. Realtime visibility in
  this spec rides on the existing activity rail (the `alert.triggered` /
  `alert.resolved` activity events broadcast on `users.{id}.activity`
  through the new `source: alerts` branch).
- Overview Alerts KPI wiring — **032** (still on `MOCK_KPIS.alerts`
  after this spec).
- User-defined `AlertRule` model + `EvaluateAlertRulesJob` against raw
  metrics — deferred (roadmap §6.8, Phase 7 closes phase 8/9 polish).
- Outbound notifications (`AlertNotificationService`) — deferred.
- The `github` alert source (repo-scoped alerts like "stale PR",
  "merge conflict") — column accepted, no emitters in this spec.
- Mute-by-source / mute-by-project — only per-alert mute exists in 031.
- `team_id` column — phase-1 has no `teams` table; revisit with the
  multi-tenant migration.

## Plan

1. **Migration.** `create_alerts_table`:
   ```php
   Schema::create('alerts', function (Blueprint $table) {
       $table->id();
       $table->foreignId('project_id')->constrained()->cascadeOnDelete();
       $table->string('source', 32);          // AlertSource enum
       $table->unsignedBigInteger('source_id')->nullable();
       $table->string('type', 64);            // 'website.down', 'host.offline', ...
       $table->string('severity', 16);        // AlertSeverity enum
       $table->string('status', 16)->default('open'); // AlertStatus enum
       $table->string('title');
       $table->text('description')->nullable();
       $table->timestamp('triggered_at');
       $table->timestamp('acknowledged_at')->nullable();
       $table->timestamp('resolved_at')->nullable();
       $table->timestamp('last_seen_at');
       $table->json('metadata')->nullable();
       $table->timestamps();

       $table->index(['status', 'source', 'source_id']); // idempotency lookups
       $table->index(['project_id', 'status']);          // index-page filters
   });
   ```

2. **Enums.** Three string-backed enums; `AlertSeverity::badgeTone()`
   matches `WebsiteStatus::badgeTone()` shape: `critical → 'danger'`,
   `warning → 'warning'`, `info → 'info'`.

3. **Model.** `Alert` with the seven enum / datetime casts and
   `belongsTo(Project::class)`. `metadata` cast `array`.

4. **Factory.** `AlertFactory::definition()` returns a sensible default
   open critical website-down alert; states for `acknowledged()`,
   `resolved()`, `muted()`, `forHost()`, `forWorkflowRun()`.

5. **Policy.** `AlertPolicy` mirrors `WebsitePolicy` shape: `viewAny`
   permits any verified user (rows scoped by project ownership at query
   level); `view` / `update` (the verb used for ack / resolve / mute) /
   `delete` require `$alert->project->owner_user_id === $user->id`.

6. **`TriggerAlertAction`.** Constructor injects
   `CreateActivityEventAction`. `execute(array $attrs): Alert`:
   ```php
   $existing = Alert::query()
       ->where('source', $attrs['source'])
       ->where('source_id', $attrs['source_id'])
       ->where('type', $attrs['type'])
       ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value])
       ->first();

   if ($existing) {
       $existing->forceFill(['last_seen_at' => now()])->save();
       return $existing;
   }

   $alert = Alert::query()->create([
       /* …project_id, source, source_id, type, severity, status: open,
            title, description, triggered_at: now, last_seen_at: now,
            metadata… */
   ]);

   $this->createActivity->execute([
       'event_type' => 'alert.triggered',
       'severity'   => $alert->severity->toActivitySeverity(),
       'title'      => $alert->title,
       'description' => $alert->description,
       'occurred_at' => $alert->triggered_at,
       'source'     => 'alerts',
       'metadata'   => [
           'alert_id'        => $alert->id,
           'alert_source'    => $alert->source->value,
           'alert_source_id' => $alert->source_id,
           'alert_type'      => $alert->type,
       ],
   ]);

   return $alert;
   ```
   `AlertSeverity::toActivitySeverity()` returns the matching
   `ActivitySeverity` case: `critical → Danger`, `warning → Warning`,
   `info → Info`. Cheap method on the enum.

7. **`ResolveAlertAction`.** `execute(array $criteria): int`:
   ```php
   $query = Alert::query()
       ->where('source', $criteria['source'])
       ->where('source_id', $criteria['source_id'])
       ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value]);

   if (isset($criteria['type'])) {
       $query->where('type', $criteria['type']);
   }

   $resolving = $query->get();
   $now = now();

   foreach ($resolving as $alert) {
       $alert->forceFill([
           'status'        => AlertStatus::Resolved->value,
           'resolved_at'   => $now,
           'last_seen_at'  => $now,
       ])->save();

       $this->createActivity->execute([
           'event_type'  => 'alert.resolved',
           'severity'    => ActivitySeverity::Success,
           'title'       => "{$alert->title} resolved",
           'occurred_at' => $now,
           'source'      => 'alerts',
           'metadata'    => [
               'alert_id'        => $alert->id,
               'alert_source'    => $alert->source->value,
               'alert_source_id' => $alert->source_id,
           ],
       ]);
   }

   return $resolving->count();
   ```
   No-op when the result set is empty — neither row update nor activity
   event fires.

8. **`ActivityEventCreated::broadcastOn` extension.** Add a fourth branch:
   ```php
   if ($this->activityEvent->source === 'alerts') {
       $alertId = $metadata['alert_id'] ?? null;
       if ($alertId === null) return null;
       $alert = Alert::query()->find($alertId);
       return $alert?->project?->owner_user_id;
   }
   ```

9. **Promotion call sites — extend four existing files.**
   - `app/Domain/Monitoring/Actions/RecordWebsiteCheckAction.php` — inject
     `TriggerAlertAction` + `ResolveAlertAction`. In the failed branch
     (after the existing `website.down` activity event), call
     `$this->trigger->execute(['source' => 'website', 'source_id' =>
     $website->id, 'project_id' => $website->project_id, 'type' =>
     'website.down', 'severity' => AlertSeverity::Critical, …])`. In the
     recovery branch, `$this->resolve->execute(['source' => 'website',
     'source_id' => $website->id])`.
   - `app/Domain/Docker/Actions/DetectOfflineHostsAction.php` — inject
     `TriggerAlertAction`. After the `host.offline` activity event call,
     trigger with `source: docker, source_id: host.id`.
   - `app/Domain/Docker/Actions/IngestHostTelemetryAction.php` — inject
     `ResolveAlertAction`. In the existing `if ($wasOffline)` branch,
     after the recovery activity event, call resolve with `source:
     docker, source_id: host.id`.
   - `app/Domain/GitHub/WebhookHandlers/WorkflowRunWebhookHandler.php` —
     inject `TriggerAlertAction`. After persisting the `workflow.failed`
     row, if `$run->head_branch === $run->repository->default_branch`,
     trigger with `source: deployment, source_id: run.id`.

10. **Tests.** Files touched + new files listed below. Use the
    spec-024 / spec-029 conventions: assert on `alerts` table rows
    directly, use `Event::fake([ActivityEventCreated::class])` only when
    the test specifically cares about broadcast dispatch (the routing
    test in `ActivityEventCreatedBroadcastTest`).

## Acceptance criteria
- [ ] `alerts` migration applies cleanly on a fresh DB and rolls back.
- [ ] `Alert` factory + policy work in tinker / tests.
- [ ] A website's healthy → failed transition creates an open `Alert` with
      `severity: critical`, `source: website`, `source_id: website.id`,
      `type: website.down`.
- [ ] The matching failed → healthy transition auto-resolves the open
      Alert and emits an `alert.resolved` activity event.
- [ ] A host going offline (`DetectOfflineHostsAction` flip) creates one
      open Alert with `severity: critical`, `source: docker`.
- [ ] First telemetry after offline (`IngestHostTelemetryAction` recovery
      branch) auto-resolves the open host Alert.
- [ ] A `workflow.failed` webhook for a run on the default branch creates
      an open Alert with `severity: warning`, `source: deployment`. The
      same handler does NOT create an Alert for a feature-branch failure.
- [ ] A second identical `Trigger` call within the open window bumps
      `last_seen_at` and returns the same row — no duplicate Alert, no
      second activity event.
- [ ] `ResolveAlertAction` is a no-op when no matching open Alert exists.
- [ ] `ActivityEventCreated` routes `source: alerts` rows to the alert's
      project owner's `users.{id}.activity` channel; unknown alert_id
      short-circuits to no channels.
- [ ] Pint clean, `php artisan test` green (new tests added), `npm run
      build` clean.

## Files touched
Fill in as work progresses.

- `database/migrations/{ts}_create_alerts_table.php` — new
- `app/Models/Alert.php` — new
- `app/Enums/AlertStatus.php` — new
- `app/Enums/AlertSeverity.php` — new
- `app/Enums/AlertSource.php` — new
- `database/factories/AlertFactory.php` — new
- `app/Policies/AlertPolicy.php` — new
- `app/Providers/AppServiceProvider.php` — register `AlertPolicy`
- `app/Domain/Alerts/Actions/TriggerAlertAction.php` — new
- `app/Domain/Alerts/Actions/ResolveAlertAction.php` — new
- `app/Events/ActivityEventCreated.php` — `source: alerts` branch
- `app/Domain/Monitoring/Actions/RecordWebsiteCheckAction.php` — Trigger / Resolve
- `app/Domain/Docker/Actions/DetectOfflineHostsAction.php` — Trigger
- `app/Domain/Docker/Actions/IngestHostTelemetryAction.php` — Resolve
- `app/Domain/GitHub/WebhookHandlers/WorkflowRunWebhookHandler.php` — Trigger (default-branch only)
- `tests/Unit/Domain/Alerts/TriggerAlertActionTest.php` — new
- `tests/Unit/Domain/Alerts/ResolveAlertActionTest.php` — new
- `tests/Feature/Monitoring/RecordWebsiteCheckActionTest.php` — extend
- `tests/Unit/Domain/Docker/DetectOfflineHostsActionTest.php` — extend
- `tests/Unit/Domain/Docker/IngestHostTelemetryActionTest.php` — extend
- `tests/Feature/GitHub/WorkflowRunWebhookHandlerTest.php` — extend (or new file if absent)
- `tests/Feature/Activity/ActivityEventCreatedBroadcastTest.php` — extend (alerts source)

## Work log

### 2026-05-26
- Spec drafted for review.

### 2026-05-27
- Shipping as drafted (all three open-question choices stay: workflow.failed at `warning`, default-branch-only filter, ack→resolve auto-promotion on recovery).
- Issue [#89](https://github.com/Copxer/nexus/issues/89) opened, branch `spec/030-alerts-scaffolding` cut off `main`. Phase 7 folder created.

## Open questions / blockers
- **`workflow.failed` severity.** 030 ships `warning`. Some teams treat a
  broken default branch as `critical` (especially when prod auto-deploys
  off main). If you'd rather start at `critical`, swap the constant —
  no schema change. The current call lets the user mark it `resolved` or
  `muted` from the UI in 031 either way.
- **Default-branch filter on workflow failures.** 030 only triggers
  alerts for failed runs whose `head_branch` matches the repo's
  `default_branch`. Branches like `staging` / `release/*` won't fire.
  A future polish spec could add a per-repo "monitored branches"
  allowlist; in MVP the default-branch filter keeps the rail signal-vs-noise
  ratio reasonable.
- **Acknowledged ≠ resolved.** `ResolveAlertAction` closes both `open`
  and `acknowledged` rows (an acknowledged outage that recovers is still
  resolved). If a user wants ack to "stick" through recovery, they can
  mute instead. Decision: auto-resolution wins over manual ack — matches
  the user's mental model of "this is fixed."
- **`Alert.team_id`.** Roadmap §8.12 lists it; phase-1 has no `teams`
  table. Column omitted — re-add as part of the eventual multi-tenant
  migration.
