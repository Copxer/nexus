---
spec: alerts-realtime-and-overview-kpi
phase: 7
status: done   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-01
updated: 2026-06-01
---

# 032 — Realtime alert updates + Overview Alerts KPI wiring

## Goal
Close out Phase 7. Spec 031's `/alerts` page is page-load today — refresh
to see fresh fires. Spec 032 adds a dedicated `users.{id}.alerts`
Reverb channel: the `/alerts` page partial-reloads on each new trigger
or resolve; the TopBar notifications bell lights up with a live
"active alerts" count; the Overview Alerts KPI swaps its long-standing
`MOCK_KPIS.alerts` placeholder for real `Alert` counts.

Roadmap refs: §Phase 7 Deliverables ("Alert real-time updates"), §8.12
Alerts UX Requirements, §10 dashboard data sources.

## Scope

**In scope:**

- **Broadcast events.**
  - `app/Events/AlertTriggered.php` — `ShouldBroadcastNow +
    ShouldDispatchAfterCommit` on private `users.{ownerUserId}.alerts`.
    Lightweight `{alert_id}` payload, pre-resolved owner id, mirrors
    spec 028's `HostTelemetryRecorded` line-for-line.
  - `app/Events/AlertResolved.php` — same shape.
  - `routes/channels.php` — authorize `users.{id}.alerts` (own-user
    only), next to the existing `users.{id}.{activity,deployments,
    monitoring,hosts}` entries.
- **Dispatch sites.**
  - `TriggerAlertAction` — on a fresh insert (the non-idempotent
    branch, after the existing `alert.triggered` activity event)
    dispatch `AlertTriggered::dispatch($alert->id, $ownerUserId)`.
    The steady-state `last_seen_at` bump path does **not** broadcast
    (the rail already has the original `alert.triggered`; a re-fire
    would just spam the toast).
  - `ResolveAlertAction` — inside the foreach, after the existing
    per-row `alert.resolved` activity event, dispatch
    `AlertResolved::dispatch($alert->id, $ownerUserId)` per closed
    row. Spec 030's `(source, source_id, type)` idempotency means
    there's at most one open+acknowledged row per tuple — so almost
    always one broadcast per call — but the loop stays correct if a
    future caller ever resolves N at once.
  - Owner resolution: both actions can reach the project via
    `$alert->project->owner_user_id` (the relation is already loaded
    by spec 030's existing code paths; if not, lazy-load is cheap).
- **Per-page Echo subscriptions.**
  - `Pages/Alerts/Index.vue` — subscribes to `users.{userId}.alerts`
    on mount; listens for `.AlertTriggered` and `.AlertResolved`;
    each pulse calls `router.reload({ only: ['alerts', 'filters',
    'filterOptions'] })`. Server-side query logic re-applies, so the
    sort + filter set stay correct. Adds a "Live updates offline"
    pill in the header when the Pusher connection drops (mirrors the
    Hosts Show + Websites Show pattern from specs 025 + 028).
  - **TopBar bell** (`resources/js/Components/TopBar/TopBar.vue`) —
    drops `cursor-not-allowed` + `aria-disabled`. Wraps the bell in
    a `<Link>` to `route('alerts.index')`. Subscribes to the same
    alerts channel; on either event triggers `router.reload({ only:
    ['activeAlertsCount'] })`. The badge count comes from a new
    shared Inertia prop populated by `HandleInertiaRequests::share()`.
- **Shared Inertia prop: `activeAlertsCount`.**
  - `app/Http/Middleware/HandleInertiaRequests.php` — adds an
    `alerts.activeCount` shared prop (a single int) — count of the
    current user's `open + acknowledged` alerts. Scoped by
    `whereHas('project', owner_user_id = $user->id)`. Reuses the
    same pattern that surfaces the `activity.recent` rail prop
    (specs 018 / 019). `null` for guests.
- **Overview Alerts KPI.**
  - `GetOverviewDashboardQuery::alerts()` — new private method
    replacing the `MOCK_KPIS.alerts` constant entry. Returns:
    - `active` — open + acknowledged count.
    - `critical` — open + acknowledged where severity = critical.
    - `sparkline` — `dailyCounts(Alert::class, SPARKLINE_DAYS)` of
      `triggered_at` over the last 12 days.
    - `status` — `success` when `active === 0`, `danger` when
      `critical > 0`, otherwise `warning`. No `muted` — alerts is
      never "no signal."
  - Drop the `'alerts' => [...]` entry from the `MOCK_KPIS` constant
    (the constant still carries `services` — that's a future polish
    spec's concern).
  - The TS `DashboardPayload['alerts']` shape (`active`, `critical`,
    `sparkline`, `status`) already matches; no `index.d.ts` change.
- **Tests.**
  - `tests/Feature/Events/AlertTriggeredTest.php` — `ShouldBroadcastNow`
    + `ShouldDispatchAfterCommit` interfaces, owner channel routing,
    null-owner short-circuit, `broadcastWith` payload, `broadcastAs`
    name. Mirrors spec 028's `HostTelemetryRecordedTest`.
  - `tests/Feature/Events/AlertResolvedTest.php` — same.
  - Broadcast-from-action tests: extend `TriggerAlertActionTest` to
    assert a fresh trigger dispatches `AlertTriggered` once with the
    right owner; assert an idempotent re-trigger dispatches nothing.
    Extend `ResolveAlertActionTest` to assert each closed row
    dispatches `AlertResolved`.
  - `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` —
    extend with: empty fleet zero state + success tone, mixed
    open/critical/acknowledged counts, sparkline shape, status
    thresholds (success / warning / danger).
  - `tests/Feature/Middleware/HandleInertiaRequestsTest.php` (if it
    exists; create otherwise) — `alerts.activeCount` reflects only
    the auth user's open + acknowledged alerts; sibling user's count
    doesn't leak; guests get `null`.

**Out of scope:**

- **`AlertRule` model + `EvaluateAlertRulesJob`** — Roadmap §6.8 +
  §Phase 7 Deliverables list "Alert rules" / "Alert evaluation jobs."
  030 documented this as deferred; 031 + 032 don't change the
  decision. A future spec adds rule-based alerts (CPU > 80% for 5m).
- **Outbound notifications** (`AlertNotificationService` — email /
  Slack / webhook). Roadmap §6.3; deferred.
- **Bulk acknowledge / resolve / mute** — 031 documented; defer.
- **Alert detail drawer / page** — 031 documented; defer.
- **TopBar dropdown preview** — clicking the bell just navigates to
  `/alerts`. A dropdown preview of the top N alerts is nicer UX but
  doubles the surface area; clicking-through is enough for MVP.
- **Workflow.failed alert idempotency model** — the phase 0–6 audit
  flagged that each failing main-branch run creates a fresh Alert
  (idempotency key is `(source, source_id, type)` with `source_id =
  workflow_run.id`). Pre-existing; intentionally not in scope.
- **`ResolveAlertAction` race window** — spec 031's self-review
  surfaced this. Concurrent user-resolve + background recovery on
  the same `(source, source_id, type)` could double-emit. A SELECT
  ... FOR UPDATE inside the loop would close it. Material now that
  032 also dispatches a broadcast event per close — but reviewer
  accepted as a follow-up. Calling out for visibility.

## Plan

1. **Events.** Two new event classes under `app/Events/`:
   ```php
   // app/Events/AlertTriggered.php
   class AlertTriggered implements ShouldBroadcastNow, ShouldDispatchAfterCommit
   {
       use Dispatchable, InteractsWithSockets, SerializesModels;

       public function __construct(
           public readonly int $alertId,
           public readonly ?int $ownerUserId,
       ) {}

       public function broadcastOn(): array
       {
           if ($this->ownerUserId === null) return [];
           return [new PrivateChannel("users.{$this->ownerUserId}.alerts")];
       }

       public function broadcastWith(): array
       {
           return ['alert_id' => $this->alertId];
       }

       public function broadcastAs(): string
       {
           return 'AlertTriggered';
       }
   }
   ```
   `AlertResolved` is the same with `'AlertResolved'` as the event
   name. Mirrors spec 028's `HostTelemetryRecorded` byte-for-byte.

2. **Channel auth.** Append to `routes/channels.php`:
   ```php
   // Spec 032 — per-user alerts channel. AlertTriggered /
   // AlertResolved broadcast here on every fresh trigger / resolve
   // so the /alerts page + the TopBar bell can react in realtime.
   Broadcast::channel('users.{userId}.alerts', function ($user, $userId) {
       return (int) $user->id === (int) $userId;
   });
   ```

3. **TriggerAlertAction dispatch site.** Inside the `if ($existing !== null) return $existing` branch — no broadcast (steady state).
   After the `$this->createActivity->execute([...])` call on the
   fresh-insert path:
   ```php
   $ownerUserId = $alert->project?->owner_user_id;
   AlertTriggered::dispatch($alert->id, $ownerUserId);
   ```
   The `$alert->project` relation is lazy-loaded (single keyed query);
   acceptable in a write path that already touched the row.

4. **ResolveAlertAction dispatch site.** Inside the foreach loop,
   after the per-row `$this->createActivity->execute([...])`:
   ```php
   $ownerUserId = $alert->project?->owner_user_id;
   AlertResolved::dispatch($alert->id, $ownerUserId);
   ```
   Per-row matches the per-row activity event already there.

5. **`HandleInertiaRequests` shared prop.** Add `'alerts'` to the
   `share()` array, mirroring the existing `'activity'` block:
   ```php
   'alerts' => fn () => $request->user() !== null
       ? ['activeCount' => $this->countActiveAlertsForUser($request->user())]
       : null,
   ```
   Helper queries `Alert::whereHas('project', owner_user_id =
   $user->id)->whereIn('status', ['open', 'acknowledged'])->count()`.

6. **`Pages/Alerts/Index.vue` Echo subscription.** Mirror the
   Websites Show / Hosts Show pattern verbatim. Inside `onMounted`:
   subscribe to `users.{auth.user.id}.alerts`, listen for
   `.AlertTriggered` + `.AlertResolved`, on either: `router.reload({
   only: ['alerts', 'filters', 'filterOptions'] })`. Pusher
   connection-state binding feeds `realtimeConnected`; the existing
   header gets a `<WifiOff>`-prefixed yellow pill when the socket is
   down.

7. **`TopBar.vue` bell.** Remove `cursor-not-allowed`,
   `aria-disabled`, the stale `title=` tooltip. Wrap the icon in
   `<Link :href="route('alerts.index')">`. Read the badge count from
   `usePage().props.alerts?.activeCount ?? 0`. On the same Echo
   subscription as Alerts Index (a parallel mount under AppLayout),
   trigger `router.reload({ only: ['alerts'] })` on each pulse to
   refresh the badge across all pages — but **only when the user
   hasn't navigated to /alerts itself** (otherwise the Alerts page's
   own reload already covers it; double-reloading would be wasteful).
   Practically: just `router.reload({ only: ['alerts'] })` on both
   events — Inertia's reload is cheap on a single shared prop and
   the page's own listener will reload the heavier `alerts` prop
   independently.

8. **Overview KPI rewrite.** `GetOverviewDashboardQuery::alerts()`
   replaces the `MOCK_KPIS.alerts` slice:
   ```php
   private function alerts(): array
   {
       $active = Alert::query()
           ->whereIn('status', ['open', 'acknowledged'])
           ->count();
       $critical = Alert::query()
           ->whereIn('status', ['open', 'acknowledged'])
           ->where('severity', 'critical')
           ->count();
       $sparkline = $this->dailyCountsByColumn(
           Alert::class, 'triggered_at', self::SPARKLINE_DAYS,
       );
       $status = match (true) {
           $critical > 0 => 'danger',
           $active > 0 => 'warning',
           default => 'success',
       };

       return compact('active', 'critical', 'sparkline', 'status');
   }
   ```
   Note: `dailyCounts()` keys on `created_at`. Alerts care about
   `triggered_at` (real fire time, not insert time — usually the same
   but explicit is better). Either (a) extend `dailyCounts` to take a
   column name, or (b) add a small `triggeredCountSparkline()` method
   modeled on the existing `workflowRunSparkline()`. Plan: option (b)
   — keeps `dailyCounts` simple, mirrors how spec 022 added a
   dedicated `workflowRunSparkline()` for `run_completed_at`.
   Drop the `'alerts' => [...]` entry from the `MOCK_KPIS` constant
   and update the class docblock to move alerts from "Still mock" to
   "Real today."

9. **Tests.**
   - Event-surface tests for both events (mirror
     `HostTelemetryRecordedTest` line-for-line).
   - Extend `TriggerAlertActionTest`: fresh trigger dispatches
     `AlertTriggered` once + owner_user_id correct; idempotent
     re-trigger dispatches nothing.
   - Extend `ResolveAlertActionTest`: each closed alert dispatches
     `AlertResolved`; orphan project (null owner) still dispatches
     (event's `broadcastOn()` returns empty, no harm).
   - Extend `GetOverviewDashboardQueryTest`: zero state → `success`,
     active without critical → `warning`, any critical → `danger`,
     mixed-status counts honor `acknowledged` in `active`, sparkline
     keys on `triggered_at` not `created_at`.
   - New `HandleInertiaRequestsTest::test_alerts_active_count_*` (or
     extend the existing file) — guest gets null; user gets count of
     own open+acknowledged; sibling user's alerts don't count;
     resolved/muted don't count.

## Acceptance criteria
- [x] A fresh `TriggerAlertAction` insert dispatches `AlertTriggered`
      on `users.{ownerUserId}.alerts`; idempotent re-trigger
      dispatches nothing.
- [x] Each `ResolveAlertAction` close dispatches `AlertResolved` on
      the same channel.
- [x] Both events implement `ShouldBroadcastNow` + `ShouldDispatchAfterCommit`.
- [x] `/alerts` page partial-reloads its `alerts` + `filters` +
      `filterOptions` props on each pulse; shows "Live updates
      offline" when the socket is down.
- [x] TopBar bell is no longer disabled; clicking it navigates to
      `/alerts`; the badge shows the user's open + acknowledged
      count; the badge auto-updates on `AlertTriggered` /
      `AlertResolved` without a page reload.
- [x] Overview Alerts KPI shows real `active` / `critical` /
      `sparkline` / `status` — no `MOCK_KPIS.alerts`.
- [x] Overview Alerts KPI status thresholds: `critical > 0` →
      `danger`; `active > 0` → `warning`; otherwise `success`.
- [x] Sibling user's alerts don't appear in another user's KPI,
      bell count, or partial-reload payload.
- [x] Pint clean, `php artisan test` green (new tests added),
      `npm run build` clean.

## Files touched
Fill in as work progresses.

- `app/Events/AlertTriggered.php` — new
- `app/Events/AlertResolved.php` — new
- `app/Domain/Alerts/Actions/TriggerAlertAction.php` — dispatch on fresh insert
- `app/Domain/Alerts/Actions/ResolveAlertAction.php` — dispatch per closed row
- `app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php` — real `alerts()` + drop `MOCK_KPIS.alerts`
- `app/Http/Middleware/HandleInertiaRequests.php` — share `alerts.activeCount`
- `routes/channels.php` — authorize `users.{id}.alerts`
- `resources/js/Pages/Alerts/Index.vue` — Echo subscription + Live offline pill
- `resources/js/Components/TopBar/TopBar.vue` — enable bell + Link + Echo subscription for count
- `tests/Feature/Events/AlertTriggeredTest.php` — new
- `tests/Feature/Events/AlertResolvedTest.php` — new
- `tests/Unit/Domain/Alerts/TriggerAlertActionTest.php` — extend (broadcast dispatch)
- `tests/Unit/Domain/Alerts/ResolveAlertActionTest.php` — extend
- `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` — extend (real alerts KPI)
- `tests/Feature/Middleware/HandleInertiaRequestsTest.php` — new (or extend)

## Work log

### 2026-06-01
- Spec drafted for review.
- Shipping as drafted (bell = navigate-only; alerts KPI global + bell count user-scoped; sparkline keys on `triggered_at`; idempotent re-triggers stay silent; `ResolveAlertAction` race deferred to a follow-up `fix/` PR after 032 lands).
- Issue [#94](https://github.com/Copxer/nexus/issues/94) opened, branch `spec/032-alerts-realtime-and-overview-kpi` cut off `main`.
- Backend: `AlertTriggered` + `AlertResolved` events + channel auth + dispatch from both actions; `HandleInertiaRequests::share()` adds `alerts.activeCount`; `GetOverviewDashboardQuery::alerts()` replaces `MOCK_KPIS.alerts` with real counts + `triggeredCountSparkline()`.
- Frontend: `Pages/Alerts/Index.vue` Echo subscription + Live offline pill; `TopBar.vue` bell becomes a `<Link>` with a reactive badge; `types/index.d.ts` PageProps gains the `alerts?` shape.
- Tests: 24 new (6+6 event surface, 2+2 action broadcast, 5 Overview KPI, 3 shared prop). Full suite 572→596 green, Pint clean, build clean.
- Self-review via `superpowers:code-reviewer` — assessed ready to PR with no critical / important issues. Minors all polish suggestions, none folded in this PR.

## Open questions / blockers
- **Bell click behavior.** Ships as a plain `<Link>` to
  `/alerts`. A dropdown preview ("top 3 active alerts") would be
  richer but would basically rebuild a mini-Alerts-Index inside the
  top bar. Out of scope; revisit if the user clicks the bell often
  and wants a preview without leaving the current page.
- **Alerts KPI: user-scoped or global?** 032 ships **global** to
  match the existing `GetOverviewDashboardQuery` convention (the
  docblock explicitly calls out single-tenant phase-1). The bell
  count IS user-scoped because it's per-user surface. Different
  conventions are deliberate here — when multi-tenant lands, the
  dashboard query gets the user parameter and the asymmetry resolves
  itself.
- **Sparkline keying.** `triggered_at` is used (the real fire moment)
  rather than `created_at` (the insert moment). Almost always
  identical, but explicit is better. The new dedicated
  `triggeredCountSparkline()` helper makes the difference visible.
- **`ResolveAlertAction` race** (called out in 031's self-review).
  Concurrent user-resolve + background recovery on the same
  `(source, source_id, type)` can both flip the row and both emit
  the activity event. With 032 they also both emit a
  `AlertResolved` broadcast. Not introduced by 032 — but materially
  more reachable. Fix is `lockForUpdate` on the SELECT inside the
  action's loop. Plan: defer, file a `fix/` PR after 032 lands.
