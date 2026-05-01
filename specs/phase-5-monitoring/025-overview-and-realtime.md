---
spec: overview-and-realtime
phase: 5-monitoring
status: done
owner: yoany
created: 2026-04-30
updated: 2026-04-30
issue: https://github.com/Copxer/nexus/issues/75
branch: spec/025-overview-and-realtime
---

# 025 — Overview integration + Reverb live updates + perf charts

## Goal
Close out phase 5 by surfacing website monitoring on Overview (replacing the long-standing `MOCK_KPIS['uptime']` placeholder with a real cross-website aggregate), wiring Reverb broadcasts on every persisted check so the Show page reflects live data, and rendering a response-time line chart on the Show page using the existing `Sparkline` component. After this spec, Phase 5 ships end-to-end: data layer (023) → automation (024) → user-facing dashboard surfaces (025).

Roadmap reference: §8.8 Website Performance Monitoring (Overview Widget bullets), §19 Phase 5 acceptance ("Performance widget uses real data").

## Scope
**In scope:**

- **`App\Domain\Monitoring\Queries\GetMonitoringUptimeKpiQuery`** — cross-website aggregate driving the Overview Uptime KPI card.
    - `execute(): array` returns:
        ```
        [
            'overall' => float|null,   // 24h volume-weighted uptime % across all checks
            'change' => float,         // delta vs prior 24h; 0 if either window empty
            'sparkline' => array<int, float>,  // length 12, daily uptime % oldest-first
            'status' => 'success'|'warning'|'danger'|'muted',
        ]
        ```
    - **`overall`** — `successful_checks / total_checks * 100` over **all** websites in the last 24h, rounded to 2 decimals. Volume-weighted (a busy website with one failure matters more than a quiet website with one success). `null` when no checks landed in the window.
    - **`change`** — overall − previous-24h overall, rounded to 2 decimals. `0.0` when either window is empty (no signal to compare).
    - **`sparkline`** — daily uptime % for each of the last 12 days, oldest-first. Days with no checks at all default to `100.0` ("no news is good news") so a fresh account doesn't render as a 0-percent flatline. Document the limitation; future polish can switch to null + `Sparkline` gap rendering.
    - **`status`** — `muted` when `overall` is null; `success` when ≥ 99.0; `warning` when ≥ 95.0; `danger` otherwise.
    - **Phase-1 single-tenant scoping** — query handle takes no `User` arg; matches the rest of `GetOverviewDashboardQuery`'s slices. Multi-tenant scoping arrives uniformly when teams ship.
    - Returned shape mirrors the existing `MOCK_KPIS['uptime']` exactly so `Overview.vue`'s `KpiCard` wiring needs no other changes.

- **Wire it in.** `GetOverviewDashboardQuery::handle()` calls `app(GetMonitoringUptimeKpiQuery::class)->execute()` for the `'uptime'` slice. Drop the `'uptime'` entry from `MOCK_KPIS`. Update the class doc-comment so `dashboard.uptime` graduates from "still mock" to "real today".

- **`App\Events\WebsiteCheckRecorded`** — `ShouldBroadcastNow` Reverb event on every persisted check.
    - Constructor: `(int $checkId, int $websiteId, int $ownerUserId)` — pre-resolved ints (matches spec 021's `WorkflowRunUpserted` pattern: don't lazy-load relations during broadcast).
    - `broadcastOn()` returns `[new PrivateChannel("users.{$ownerUserId}.monitoring")]`. Empty when `ownerUserId` is null (orphan website).
    - `broadcastWith()` returns `{ check_id, website_id }` — light-weight pulse. Client uses it as a trigger, not as a source of truth.
    - `broadcastAs()` returns `'WebsiteCheckRecorded'` — stable dotted name for Echo subscribers.

- **Channel authorization** in `routes/channels.php`:
    ```php
    Broadcast::channel('users.{userId}.monitoring', function ($user, $userId) {
        return (int) $user->id === (int) $userId;
    });
    ```
    Mirrors specs 019 (activity) and 021 (deployments) exactly.

- **`RecordWebsiteCheckAction` extension.** After the existing persistence + transition-event paths, dispatch `WebsiteCheckRecorded` with the pre-resolved owner id (via `$website->project->owner_user_id`, loaded eagerly to avoid the N+1 some broadcast pipelines hit). Skip the dispatch when the owner can't be resolved (orphan project).

- **Show page realtime subscription.**
    - `Pages/Monitoring/Websites/Show.vue` subscribes via `window.Echo.private("users.{auth.user.id}.monitoring")` on mount, listens for `.WebsiteCheckRecorded`, and on each pulse calls `router.reload({ only: ['summary', 'checks', 'website'] })` so the uptime stats, recent-checks list, and the parent website's `last_*` timestamps all refresh atomically. Filter client-side by the pulse's `website_id` matching the page's loaded website id — other websites' pulses don't trigger a reload on this page.
    - Tracks `realtimeConnected: Ref<boolean | null>` and renders an offline pill when the connection drops, mirroring `useActivityFeed.ts`'s pattern.
    - Tear down the subscription in `onBeforeUnmount`.

- **Response-time line chart on the Show page.**
    - Render the last 50 checks' `response_time_ms` as a `Sparkline` (already a `KpiCard` building block; reuse standalone). Header strip in the existing Recent-checks card gains the chart inline above the list.
    - Skip checks with null `response_time_ms` (Error rows where the probe didn't get a response time) — they'd render as gaps. Carry-forward the previous value when null is encountered to keep the line continuous.
    - Compact `Sparkline` props: `accent="cyan"`, `height={48}`, no labels.
    - When fewer than 2 data points exist, render a small "Not enough data yet" placeholder instead of the chart.

- **Tests:**
    - `GetMonitoringUptimeKpiQueryTest` — empty state returns null `overall` + muted; mixed checks compute volume-weighted percentage; 12-entry sparkline ordering; days with no checks default to 100; status thresholds at 99 / 95 boundaries.
    - `WebsiteCheckRecordedTest` — implements `ShouldBroadcastNow`, `broadcastOn()` returns the right channel for the supplied owner id, payload + dotted name match.
    - `RecordWebsiteCheckActionTest` — extend with `Event::fake([WebsiteCheckRecorded::class])` to assert the event dispatches on every check (incident, recovery, AND steady-state runs that don't emit transition events).
    - `GetOverviewDashboardQueryTest` — extend the existing payload-shape test to include the new `uptime.overall` real-data assertion (no longer pinned to the mock 99.98).

**Out of scope:**

- Per-website status-page generator → roadmap §8.8 Future.
- SLA target configuration → polish.
- Per-region probes / Lighthouse / DNS / TLS / TTFB timings → §8.8 Future.
- Hourly aggregated response-time chart with 24h / 7d / 30d toggle (option B from the scope check) — phase-1 keeps the simple line of last-50-checks; revisit if real users find the raw points too noisy.
- Show page incident timeline (correlated transitions list) → polish.
- Channel auth integration test — same brittle `/broadcasting/auth` env-CSRF baseline that affected spec 021's drop; channel callback correctness is already covered by the event-level "broadcasts on the right channel" assertion.

## Plan
1. **`GetMonitoringUptimeKpiQuery`** + tests — pure aggregate, no model dependencies beyond `WebsiteCheck`.
2. **Wire it into `GetOverviewDashboardQuery`** + drop `MOCK_KPIS['uptime']`. Update class docblock.
3. **`WebsiteCheckRecorded` event** + tests — pre-resolved owner pattern from spec 021.
4. **`routes/channels.php`** authorize the new channel.
5. **`RecordWebsiteCheckAction`** dispatches the event after persistence. Tests — `Event::fake` assertion on every kind of run.
6. **`Show.vue` subscribes via Echo** and partial-reloads on the right pulse. Add `realtimeConnected` offline pill.
7. **Response-time `Sparkline`** in the Recent-checks card header. Carry-forward null handling.
8. **Self-review pass via `superpowers:code-reviewer`**.
9. **Open the PR**.

## Acceptance criteria
- [ ] `GetMonitoringUptimeKpiQuery` returns the documented shape; volume-weighted across all websites; null `overall` + muted status on empty windows.
- [ ] `GetOverviewDashboardQuery::handle()` exposes the real `dashboard.uptime` slice; `MOCK_KPIS['uptime']` removed; class docblock updated.
- [ ] `WebsiteCheckRecorded` implements `ShouldBroadcastNow`, broadcasts on `users.{ownerUserId}.monitoring` with payload `{ check_id, website_id }`.
- [ ] `routes/channels.php` rejects users other than the website's project owner.
- [ ] `RecordWebsiteCheckAction` dispatches `WebsiteCheckRecorded` on every persisted check (independent of transition emission).
- [ ] `Pages/Monitoring/Websites/Show.vue` subscribes via Echo on mount, partial-reloads on each pulse matching the page's website id, leaves on unmount.
- [ ] Show page renders a response-time `Sparkline` of the last 50 checks; null response times carry forward; <2 data points renders the placeholder.
- [ ] Pint + `php artisan test` (full suite) + `npm run build` clean. CI green.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before opening the PR.

## Files touched
- `app/Domain/Monitoring/Queries/GetMonitoringUptimeKpiQuery.php` — new.
- `app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php` — call the new query; drop `MOCK_KPIS['uptime']`; update docblock.
- `app/Events/WebsiteCheckRecorded.php` — new (broadcast event).
- `routes/channels.php` — `users.{userId}.monitoring` per-user auth.
- `app/Domain/Monitoring/Actions/RecordWebsiteCheckAction.php` — dispatch the event after persistence.
- `resources/js/Pages/Monitoring/Websites/Show.vue` — Echo subscription + offline pill + response-time Sparkline.
- `tests/Feature/Monitoring/GetMonitoringUptimeKpiQueryTest.php` — new.
- `tests/Feature/Events/WebsiteCheckRecordedTest.php` — new.
- `tests/Feature/Monitoring/RecordWebsiteCheckActionTest.php` — extend with broadcast-event assertion.
- `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` — extend `mock_kpis_remain_consistent_with_phase_0_values` to drop the uptime line + add a real-uptime test.

## Work log
Dated notes as work progresses.

### 2026-04-30
- Spec drafted.
- Opened issue [#75](https://github.com/Copxer/nexus/issues/75) and branch `spec/025-overview-and-realtime` off `main`.
- Implementation complete. New `GetMonitoringUptimeKpiQuery` (volume-weighted 24h uptime + change vs prior 24h + 12-day daily sparkline + status thresholds at 99 / 95). Wired into `GetOverviewDashboardQuery`; `MOCK_KPIS['uptime']` removed; class docblock graduates uptime to "real today". New `WebsiteCheckRecorded` `ShouldBroadcastNow` event with pre-resolved owner id, broadcasts on `users.{ownerUserId}.monitoring` with light pulse `{ check_id, website_id }`. `routes/channels.php` authorizes the new channel. `RecordWebsiteCheckAction` dispatches the event after every persisted check (steady-state runs included; transition events stay separate per spec 024). `Show.vue` subscribes via Echo, filters client-side by `website_id`, partial-reloads on matching pulses; offline pill via `realtimeConnected` ref; response-time `Sparkline` of last 50 checks with leading-null skip and carry-forward fill.
- 18 net new passing tests across 3 new files + 2 extended; full suite 357 passed (was 339).
- Self-review pass via `superpowers:code-reviewer` flagged 3 recommendations, all addressed: hoisted `usePage()` to setup top-level (idiomatic Inertia), skipped leading-null Error checks in the sparkline so the line doesn't anchor at the 0ms floor, and added a docblock note on `WebsiteCheckRecorded::broadcastOn()` documenting the per-user-channel-vs-per-website-channel trade-off for when monitor counts cross ~1k.
- **Phase 5 complete (3/3 specs done).** Phase-pending tabs and stubs related to website monitoring are all wired; the only remaining `MOCK_KPIS` slices are `services` (phase 6) and `alerts` (phase 7).

## Decisions (locked 2026-04-30)
- **Volume-weighted uptime (option B).** `successful_checks / total_checks` across all websites — truest "system-wide uptime" measure.
- **Sparkline of last-50 response times (option A).** Reuses the existing component; matches the recent-checks data the user is already looking at. Hourly aggregation is a future polish.
- **Broadcast every persisted check (option A).** Mirrors spec 021's `WorkflowRunUpserted`; the right rail still gets transition events for free from spec 024.
- **Days with no checks → 100% on the sparkline.** A flat 100 line for a fresh account reads as "no failures observed" rather than "everything was down." Document the limitation.
- **Pre-resolved owner id in event constructor.** Avoids broadcast-time relation walking — matches spec 021's `WorkflowRunUpserted` decision.

## Open questions / blockers
- **`null` response times in Sparkline.** `Sparkline.vue` accepts `number[]`. Carry-forward the previous value when a null is encountered; if the first point is null fall back to 0. Confirm during implementation that the rendered line stays readable.
- **Scaling read of `WebsiteCheck::query()->count()` over 24h.** At phase-1 row counts (≤100 monitors × 1440 minutes / interval) under 50k rows; cheap. Cache only if a slow-query log flags it.
