---
spec: alerts-ui
phase: 7
status: done   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-05-27
updated: 2026-05-29
---

# 031 — Alerts UI + acknowledge / resolve / mute actions

## Goal
Give the user a place to actually see and act on the alerts spec 030
started persisting. After 031 a user opens `/alerts`, sees the open
alerts that affect their projects (sorted by severity, filterable by
severity / source / project / status), clicks "Acknowledge" / "Resolve"
/ "Mute" per row, and drills through to the affected entity. Sidebar
`Alerts` link and Cmd+K `go-alerts` both light up.

No realtime list updates yet — that's 032. Index is page-load with the
existing activity-rail Reverb stream surfacing new `alert.triggered` /
`alert.resolved` events in the meantime.

Roadmap refs: §Phase 7 acceptance ("User can acknowledge alert"), §8.12
Alerts UX Requirements (filters by severity / source / project, alert
timeline, ack / resolve / mute buttons, link to affected entity).

## Scope

**In scope:**

- **`AlertController@index`** — single method on a thin controller
  (mirrors `DeploymentController` shape, not the website resource
  controller — Alerts only have `index` + state-mutation actions, no
  create / edit / destroy here).
  - URL-bound filters: `severity`, `source`, `status`, `project_id`,
    all `sometimes|nullable`, all validated against the respective
    enums + a user-owns-project rule for `project_id`.
  - Default filters: `status: 'open'` so the page lands on the
    actionable set (matches what most ops dashboards do; resolved /
    muted reachable via the dropdown).
  - Order: severity (critical → warning → info), then `triggered_at
    desc`, then `id desc` for tie-break. Sort in PHP off a small
    severity-priority map so the order is cross-DB.
  - User scoping: `whereHas('project', owner_user_id = $user->id)`,
    same convention as the rest of the dashboard queries.
  - `transform()` per row exposes the fields the Vue needs +
    `affected_entity_url` (server-resolved so the page stays dumb).
- **State-mutation actions + controllers** — three single-action
  invokable controllers (mirrors `RepositorySyncController` shape):
  - `AlertAcknowledgeController` (`POST /alerts/{alert}/acknowledge`,
    `alerts.acknowledge`) → calls `AcknowledgeAlertAction`. Flips
    `status: open → acknowledged`, stamps `acknowledged_at`. Idempotent
    on a row already acknowledged. Returns `back()->with('status',
    "Alert acknowledged.")`. Silent — no activity event.
  - `AlertResolveController` (`POST /alerts/{alert}/resolve`,
    `alerts.resolve`) → calls the existing `ResolveAlertAction` with
    the alert's own `(source, source_id, type)` as criteria. Because
    spec 030's idempotency guarantees at most one open / acknowledged
    row per `(source, source_id, type)`, this closes exactly the
    clicked alert. The action already emits an `alert.resolved`
    activity event — symmetry with auto-resolve preserved.
  - `AlertMuteController` (`POST /alerts/{alert}/mute`, `alerts.mute`)
    → calls `MuteAlertAction`. Flips status to `muted`. Idempotent on a
    row already muted. Silent.
  - All three: `$this->authorize('update', $alert)` (the existing
    spec-030 `AlertPolicy::update` gate already maps to "ack / resolve
    / mute" — see the policy's docblock).
- **Domain actions.**
  - `AcknowledgeAlertAction` — single Alert input. No-op when the row
    is already acknowledged / resolved / muted (idempotent + can't ack
    a closed alert). Stamps `acknowledged_at = now()` and
    `last_seen_at = now()`.
  - `MuteAlertAction` — single Alert input. No-op when already muted /
    resolved.
  - `ResolveAlertAction` — **reused as-is** from spec 030. The
    controller passes `{source, source_id, type}` and the action's
    existing idempotency contract takes care of the rest.
- **Routes.** Append below the `monitoring.hosts.*` block in
  `routes/web.php`, inside the `['auth', 'verified']` group.
- **`Pages/Alerts/Index.vue`.**
  - Header with title + counts ("N open, N critical").
  - Filter bar — four `<select>` dropdowns (severity / source / status
    / project) modeled on the Deployments index filters. URL-bound via
    `router.get(route('alerts.index'), {...}, { preserveScroll: true,
    preserveState: true, only: ['alerts', 'filters', 'filterOptions'] })`.
  - List: per row shows severity `StatusBadge`, source label, title,
    description (truncated), triggered-at relative + last-seen-at
    relative, three action buttons (ack / resolve / mute) shown
    conditionally by status, and an external-link icon to
    `affected_entity_url`.
  - Empty states: (a) no alerts at all → "All clear" success state;
    (b) no alerts match the filter → "No alerts match this filter" +
    "Clear filter" CTA (mirrors the Websites index pattern).
  - Per-row action buttons use `router.post` with `preserveScroll:
    true` so the page refreshes the list inline.
- **Sidebar + Cmd+K.**
  - `Sidebar.vue` — drop `disabled: true` + `soonLabel` from the
    `Alerts` item; wire `href: route('alerts.index')`.
  - `commands.ts` — `go-alerts` gains `run: () => router.visit(route(
    'alerts.index'))` + keywords; loses `disabled` / `soonLabel`.
- **Affected-entity resolution (`AlertController::transform`).** Server
  side mapping so the Vue doesn't repeat itself:
  - `website` → `route('monitoring.websites.show', source_id)`
  - `docker` → `route('monitoring.hosts.show', source_id)`
  - `deployment` → `metadata.html_url` (the GitHub Actions run URL we
    stash on trigger; there's no per-run page in-app yet)
  - others → `null` (rendered as a disabled icon)
- **Tests.**
  - `AlertControllerTest` (feature) — index renders default-open list,
    severity / source / status / project filters narrow correctly,
    sibling-user alerts don't leak, sort order is critical-first then
    newest, invalid filter values 422, `affected_entity_url` shapes for
    all three sources.
  - `AlertAcknowledgeControllerTest` — happy path flips + stamps; ack
    on already-ack row is a no-op (no double-stamp); ack on a
    resolved / muted row is a no-op; sibling-user 403.
  - `AlertResolveControllerTest` — happy path flips + emits
    `alert.resolved` activity; resolve on a row that's already
    resolved is a no-op; sibling-user 403.
  - `AlertMuteControllerTest` — happy path flips; mute on already-muted
    is a no-op; mute on a resolved row is a no-op (resolved is
    terminal); sibling-user 403.
  - Unit tests for `AcknowledgeAlertAction` + `MuteAlertAction`
    parallel to spec 030's pattern.

**Out of scope:**

- **Realtime list updates** — 032 adds a `users.{id}.alerts` Reverb
  channel + Echo subscription. In 031 the user refreshes / re-navigates
  to see new alerts. The activity rail (spec 030's `alert.triggered`
  events on `users.{id}.activity`) already pings on every new alert.
- **Overview Alerts KPI** — still on `MOCK_KPIS.alerts` until 032.
- **TopBar notifications bell** — wires to the Alerts channel in 032
  per the bell's tooltip.
- **Bulk actions** (ack-all-by-source / resolve-all) — single-row only
  for MVP. Easy to add later.
- **Alert detail drawer / page** — the index row + the "link to
  affected entity" CTA give the user the context they need. A
  dedicated drawer is polish for a follow-up spec.
- **`alert.acknowledged` / `alert.muted` activity events** — silent on
  purpose. Acknowledge / mute are UI-state, not project-state
  transitions. Trigger and Resolve stay noisy (real signal changes).
- **AlertRule model + `EvaluateAlertRulesJob`** — see spec 030's
  Out of Scope.
- **Outbound notifications** (`AlertNotificationService`, email /
  Slack) — deferred.

## Plan

1. **Actions.**
   ```php
   // AcknowledgeAlertAction
   public function execute(Alert $alert): Alert
   {
       if ($alert->status !== AlertStatus::Open) {
           return $alert; // idempotent: only fresh opens can be ack'd
       }
       $alert->forceFill([
           'status' => AlertStatus::Acknowledged->value,
           'acknowledged_at' => now(),
           'last_seen_at' => now(),
       ])->save();
       return $alert;
   }
   ```
   `MuteAlertAction` follows the same shape but only refuses `resolved`
   as the terminal state — muting an already-ack'd alert is a sensible
   "I'm not going to act on this right now, stop highlighting."

2. **Single-action controllers** — three files matching the shape of
   `RepositorySyncController`:
   ```php
   class AlertAcknowledgeController extends Controller
   {
       public function __invoke(Alert $alert, AcknowledgeAlertAction $ack): RedirectResponse
       {
           $this->authorize('update', $alert);
           $ack->execute($alert);
           return back()->with('status', 'Alert acknowledged.');
       }
   }
   ```
   `AlertMuteController` mirrors it. `AlertResolveController` injects
   `ResolveAlertAction` (the existing one from spec 030) and calls:
   ```php
   $resolveAlert->execute([
       'source' => $alert->source,
       'source_id' => $alert->source_id,
       'type' => $alert->type,
   ]);
   ```

3. **`AlertController@index`.** Inject the request only; pull filter
   options from the relevant enums; run the scoped query inline (no
   separate query class — the shape is simple enough to live in the
   controller, matching `DeploymentController`).
   ```php
   $validated = $request->validate([
       'severity' => 'sometimes|nullable|in:'.implode(',', AlertSeverity::values()),
       'source' => 'sometimes|nullable|in:'.implode(',', AlertSource::values()),
       'status' => 'sometimes|nullable|in:'.implode(',', AlertStatus::values()),
       'project_id' => [
           'sometimes', 'nullable', 'integer',
           Rule::exists('projects', 'id')->where('owner_user_id', $user->id),
       ],
   ]);
   $status = $validated['status'] ?? AlertStatus::Open->value;
   ```
   Severity priority sort happens in PHP after `->get()` since
   `CASE WHEN severity` cross-DB is ugly. Phase-1 alert counts are well
   below any size where SQL-side sort would matter.

4. **`AlertController::transform`** + `affectedEntityUrl()` private
   helper — server-resolved URL keyed off `AlertSource`. Phase-7 maps:
   `website` / `docker` / `deployment`. New sources added in future
   specs fall through to `null` (UI renders the icon disabled).

5. **Routes** (`routes/web.php`, inside `['auth', 'verified']`):
   ```php
   Route::get('/alerts', [AlertController::class, 'index'])
       ->name('alerts.index');
   Route::post('/alerts/{alert}/acknowledge', AlertAcknowledgeController::class)
       ->name('alerts.acknowledge');
   Route::post('/alerts/{alert}/resolve', AlertResolveController::class)
       ->name('alerts.resolve');
   Route::post('/alerts/{alert}/mute', AlertMuteController::class)
       ->name('alerts.mute');
   ```

6. **Vue page (`Pages/Alerts/Index.vue`).** Mirror Deployments'
   filter-bar visual + Websites' list-row visual. Reuse `StatusBadge`
   for severity (tone from `AlertSeverity::badgeTone()` via the
   transform). One inline `<select>` per filter; one `<form>` with
   three submit buttons per row for ack / resolve / mute. The forms
   POST through `router.post(..., { preserveScroll: true })` so the
   page partial-reloads in place.

7. **Sidebar.** `Sidebar.vue` — the `alerts` entry today reads
   `{ label: 'Alerts', soonLabel: 'Phase 7', disabled: true }`. Update
   to `{ label: 'Alerts', href: route('alerts.index') }`. Keep the icon
   (Bell) — already correct.

8. **Cmd+K.** `commands.ts` — the `go-alerts` entry today is
   `disabled: true, soonLabel: 'Phase 7'`. Update to a real `run` +
   keywords (e.g. `['incidents', 'open alerts', 'acks']`).

9. **Tests.** Action-level unit tests for the two new actions (parallel
   to spec 030's `TriggerAlertActionTest` shape). Feature tests for the
   four controllers — happy path, idempotency, policy, filtering,
   sorting. The existing `AlertPolicy` from spec 030 covers the
   "sibling user gets 403" path with no changes.

## Acceptance criteria
- [x] `/alerts` page renders the user's open alerts by default, sorted
      critical-first then newest.
- [x] Severity / source / status / project filters all narrow the list
      correctly; URL-bound; reload preserves the filter set.
- [x] Acknowledge button flips an open alert to `acknowledged` and
      stamps `acknowledged_at`; idempotent on already-ack'd rows.
- [x] Resolve button flips an open / acknowledged alert to `resolved`,
      stamps `resolved_at`, emits one `alert.resolved` activity event.
- [x] Mute button flips a non-terminal alert to `muted`; idempotent on
      already-muted rows.
- [x] None of ack / mute emit activity events (silent UI-state changes).
- [x] Sidebar `Alerts` entry routes to `/alerts`; Cmd+K `go-alerts`
      does the same.
- [x] Affected-entity icon links to the right page for website / docker
      alerts and to the GitHub run URL for deployment alerts.
- [x] Sibling user gets 403 on any of the three state-mutation routes.
- [x] Sibling user's alerts do not appear in another user's list.
- [x] Empty list (no alerts) shows an "All clear" state; empty result
      under a filter shows a "Clear filter" CTA.
- [x] Pint clean, `php artisan test` green (new tests added),
      `npm run build` clean.

## Files touched
Fill in as work progresses.

- `app/Domain/Alerts/Actions/AcknowledgeAlertAction.php` — new
- `app/Domain/Alerts/Actions/MuteAlertAction.php` — new
- `app/Http/Controllers/AlertController.php` — new (`index` only)
- `app/Http/Controllers/AlertAcknowledgeController.php` — new
- `app/Http/Controllers/AlertResolveController.php` — new
- `app/Http/Controllers/AlertMuteController.php` — new
- `routes/web.php` — register the four routes
- `resources/js/Pages/Alerts/Index.vue` — new
- `resources/js/Components/Sidebar/Sidebar.vue` — enable Alerts entry
- `resources/js/lib/commands.ts` — enable `go-alerts`
- `resources/js/types/index.d.ts` — add an `AlertRow` interface for the page prop
- `tests/Unit/Domain/Alerts/AcknowledgeAlertActionTest.php` — new
- `tests/Unit/Domain/Alerts/MuteAlertActionTest.php` — new
- `tests/Feature/Alerts/AlertControllerTest.php` — new (index)
- `tests/Feature/Alerts/AlertAcknowledgeControllerTest.php` — new
- `tests/Feature/Alerts/AlertResolveControllerTest.php` — new
- `tests/Feature/Alerts/AlertMuteControllerTest.php` — new

## Work log

### 2026-05-27
- Spec drafted for review.

### 2026-05-29
- Shipping as drafted (silent ack / mute, GitHub Actions URL for deployment, no detail drawer, default landing filter `status: open`, no idempotency change for workflow.failed in this spec).
- Issue [#92](https://github.com/Copxer/nexus/issues/92) opened, branch `spec/031-alerts-ui` cut off `main`.
- Backend: `AcknowledgeAlertAction` + `MuteAlertAction` + `AlertController@index` + three single-action lifecycle controllers; routes registered.
- Frontend: `Pages/Alerts/Index.vue` with the filter bar, per-row severity-toned card, three action buttons gated by `can_*` flags, two empty states; Sidebar Alerts entry enabled, Cmd+K `go-alerts` wired.
- Tests: 34 new (8 action unit + 14 controller index + 5 resolve + 4 mute + 4 ack). Full suite 537→572 green, Pint clean, build clean.
- Self-review via `superpowers:code-reviewer`. Addressed: (1) "Any status" dropdown was a silent duplicate of "Open" — added `'all'` URL sentinel + matching controller branch so the user can reach a "show every status" view; (2) `AlertResolveController` short-circuits on a muted alert with a clear error flash (the action's existing whereIn already skipped muted, but the controller was flashing "Alert resolved." misleadingly); (3) tightened the resolve activity-event assertions (`alert_source` / `alert_source_id` metadata + `acknowledged_at` preservation); (4) added a same-severity newest-first sort tie-break test; (5) dropped an unused `Link` import in the Vue page.
- Deferred: spec 030's `ResolveAlertAction` race window (concurrent user-resolve + background recovery could double-emit). Pre-existing; reviewer accepted as a follow-up.

## Open questions / blockers
- **Acknowledge / mute silent vs noisy.** Spec ships them silent (no
  activity event). Trigger + Resolve stay noisy because they're project-
  state transitions. Ack / mute are UI-state. If you want a "Yoany
  acknowledged the prod-frankfurt-01 outage" rail entry — say so and
  the actions gain an emission. Default is silent.
- **Deployment alert "affected entity" target.** Phase 7 ships
  `metadata.html_url` (the GitHub Actions run page). An in-app
  per-workflow-run detail page doesn't exist; building one would be a
  separate spec. Acceptable for MVP?
- **No alert detail drawer.** The row's truncated description + the
  affected-entity link cover the common case ("see what's wrong, go
  fix it"). A drawer that shows full metadata + a timeline of
  ack / resolve actions is nice polish; doesn't block 031.
- **Bulk actions.** Roadmap §8.12 doesn't list them. Single-row only
  for MVP; revisit if the user ends up clicking ack 20× per outage.
- **Default landing filter.** 031 ships `status: open` as the default.
  Some teams want "active" = open + acknowledged (still firing,
  someone's on it). Adjustable via the dropdown either way; calling
  out the default-pick.
