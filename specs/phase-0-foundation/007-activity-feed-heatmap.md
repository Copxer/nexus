---
spec: activity-feed-heatmap
phase: 0-foundation
status: in-progress
owner: yoany
created: 2026-04-27
updated: 2026-04-27
issue: https://github.com/Copxer/nexus/issues/15
branch: spec/007-activity-feed-heatmap
---

# 007 — ActivityFeed + ActivityHeatmap components (mock data)

## Goal
Replace the right-rail empty state from spec 004 with a populated `ActivityFeed` and add an `ActivityHeatmap` widget to the Overview page — both fed by hardcoded mock data from the existing `OverviewController`. After this spec, the Overview screen visually matches `nexus-dashboard.png` for two more sections: the right rail streams events (icons + severity colors + timestamps), and the dashboard surface gains a 7×6 grid heatmap showing relative activity intensity by day-of-week × time-of-day.

Roadmap reference: §8.10 Activity Feed, §8.11 Heatmap Activity (MVP heatmap = 7 days × 6 time buckets per the §8.11 example rows: 12 AM / 4 AM / 8 AM / 12 PM / 4 PM / 8 PM).
Visual target: [`../visual-reference.md`](../visual-reference.md) → [`../../nexus-dashboard.png`](../../nexus-dashboard.png) — right-rail event list and the heatmap grid (purple → magenta → pink intensity ramp).

## Scope
**In scope:**
- New `resources/js/Components/Activity/ActivityFeed.vue` — composes a list of events plus a small "Recent · All" tab pill (visual-only — actual filtering arrives with real integrations). Renders inside the existing `RightActivityRail` (replacing the empty state shipped in spec 004).
- New `resources/js/Components/Activity/ActivityFeedItem.vue` — single event row: severity-tinted icon + two-line content (title + source/time meta) + optional inline metadata pill (e.g. repo name). Click is inert (`aria-disabled`) for now — target detail views ship with their respective phases.
- New `resources/js/Components/Activity/ActivityHeatmap.vue` — 7-column × 6-row grid of small rounded squares. Cell intensity maps `count` to a 5-step ramp (background-panel → accent-purple → accent-magenta — matches visual-reference spec §11). Native `<title>` tooltip + `aria-label` per cell ("Wed 12 PM — 3 events"). Header axis: `S M T W T F S` columns; row labels: `12 AM / 4 AM / 8 AM / 12 PM / 4 PM / 8 PM`. A small legend strip ("Less / More") below the grid.
- Update `RightActivityRail.vue` to drop the empty-state block and render `<ActivityFeed :events="…" />` instead. The header's filter button stays placeholder-disabled (its real behavior depends on the real feed). Drop the now-stale "spec 007" hint title text and replace with phase-language consistent with sidebar/palette.
- Extend `app/Http/Controllers/OverviewController.php` with two new top-level Inertia props:
    - `recentActivity: ActivityEvent[]` — 8–10 hardcoded events covering the §8.10 event-type vocabulary (a deployment success, a workflow failure, a pull-request merge, a website-down + recovery pair, an alert trigger, etc.).
    - `activityHeatmap: number[][]` — 7×6 array of small non-negative integers (event counts per day-of-week × time-bucket).
- Type both new shapes in `resources/js/types/index.d.ts` (`ActivityEvent`, `ActivityEventType`, `ActivityHeatmapPayload`). Extend the `Overview.vue` page-prop type to receive them.
- Add the `ActivityHeatmap` to the Overview page as its own glass-card row between the existing "Service Health" / "Container Hosts" row and the "Visualizations" placeholder card. Drop the now-redundant "ActivityFeed + Heatmap render in spec 007" footer text from the Visualizations card (it's no longer pending here).
- Pass the rail's events from `Overview.vue` down to `AppLayout.vue` via a new optional slot or prop on the layout. Since the rail is rendered by the layout (twice — column + drawer variants), the cleanest plumbing is: `AppLayout` accepts a `defineProps<{ activityEvents?: ActivityEvent[] }>()` and forwards it to both `RightActivityRail` instances. Pages without an activity feed (Profile/Edit) keep the empty-state behavior; the rail accepts an optional `events` prop and falls back to its current empty-state when omitted.
- Extend `tests/Feature/SmokeTest.php` with one more `assertInertia` block confirming `recentActivity` is an array (count ≥ 1) and `activityHeatmap` is a 7×6 array.

**Out of scope:**
- Real event ingestion / Domain layer / broadcasting (`ActivityEventCreated` event from §8.10's instructions). Comes when integrations land in phases 2/3/4.
- Real backend filtering on `Filter` button — visual-only this spec, same treatment as the time-range pill in TopBar.
- Click-to-detail navigation on individual events — inert (`aria-disabled`) until target sections exist.
- Heatmap clicking-cell-filters-feed wiring — inert this spec.
- Real-time updates / WebSocket subscription. Reverb is configured (per `.env`), but the wire-up is deferred to phase 9 polish.
- Accessibility beyond keyboard tab + visible focus ring + tooltips. No ARIA-grid pattern for the heatmap (it's decorative-with-tooltips; semantic table would be overkill for a 42-cell mock).

## Plan
1. Build `ActivityFeedItem.vue` first — the smallest leaf. Props: a single `ActivityEvent`. Map event-type → lucide icon + severity tone via small lookup tables.
2. Build `ActivityFeed.vue` — composes a list, takes `events: ActivityEvent[]`. Optional "Recent / All" pill is visual-only.
3. Build `ActivityHeatmap.vue` — props: `data: number[][]` (7×6). Intensity bucket function (`count → 0..4`). CSS classes per bucket use `bg-background-panel-hover`, `bg-accent-purple/15`, `bg-accent-purple/40`, `bg-accent-magenta/55`, `bg-accent-magenta/80`. Each cell has `<title>` + `aria-label`.
4. Wire feed into `RightActivityRail.vue`:
    - Add an optional `events?: ActivityEvent[]` prop.
    - Render `<ActivityFeed :events="events" />` when `events?.length > 0`, otherwise fall back to the existing empty-state block.
    - Update the filter button's tooltip from spec-number to phase-language.
5. Wire layout-level forwarding:
    - `AppLayout.vue` adds an optional `activityEvents?: ActivityEvent[]` prop and passes it down to both `<RightActivityRail>` instances (column + drawer).
6. Backend:
    - Extend `OverviewController::__invoke()` to add `recentActivity` (8–10 hardcoded events with realistic `occurred_at` timestamps relative to now) and `activityHeatmap` (7×6 mock counts with a believable rhythm — quieter overnight/weekend, busier weekdays mid-day).
7. Types:
    - Add `ActivityEventType`, `ActivityEvent`, `ActivityHeatmapPayload` to `resources/js/types/index.d.ts`. Re-export from there.
8. `Overview.vue`:
    - Receive the two new props, pass `activityEvents` to `<AppLayout>`, render `<ActivityHeatmap :data="activityHeatmap" />` in a new section between the existing rows and the Visualizations placeholder.
    - Remove the now-redundant "ActivityFeed + Heatmap render in spec 007" line from the Visualizations footer.
9. SmokeTest: add `recentActivity` and `activityHeatmap` shape assertions.
10. Run dev server, manually verify in Playwright at desktop / tablet / mobile. Capture issues + fixes in the work log. Side-by-side compare with `nexus-dashboard.png`.
11. Pipeline (vue-tsc, Pint, build, tests).
12. Self-review with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] The right rail on `/overview` shows the populated `ActivityFeed` (8–10 mock events) instead of the empty-state block.
- [ ] Each event row shows: severity-tinted icon (lucide), title, source + relative time meta, optional metadata pill.
- [ ] The "Filter" button in the rail header stays disabled (visual only) and its tooltip references the phase that ships real filtering, not "spec 007".
- [ ] Pages without activity events (Profile/Edit) still render the existing empty-state in the rail.
- [ ] The Overview page now renders an `ActivityHeatmap` section between the Service Health row and the Visualizations card. Grid is 7 cols × 6 rows with column labels `S M T W T F S` and row labels `12 AM / 4 AM / 8 AM / 12 PM / 4 PM / 8 PM`.
- [ ] Heatmap cells use a 5-step intensity ramp (panel-hover → purple → magenta) and have native `<title>` tooltips + `aria-label` strings of the form "Wed 12 PM — 3 events".
- [ ] Heatmap legend strip ("Less / More" with 5 swatches) renders below the grid.
- [ ] `OverviewController` Inertia response now carries `recentActivity` (array, length ≥ 1) and `activityHeatmap` (7×6 array of integers) in addition to the existing `dashboard` payload.
- [ ] `DashboardPayload` is unchanged (still the §8.1.1 shape); the new shapes ride as separate top-level props.
- [ ] No real integrations / API calls / DB queries — mock data only.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes — design tokens only.
- [ ] No regressions: existing tests still pass; SmokeTest gets a third case asserting the new prop shapes.
- [ ] Pint clean, vue-tsc clean, `npm run build` green, CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
- `app/Http/Controllers/OverviewController.php` — extend Inertia render with `recentActivity` + `activityHeatmap` mock arrays.
- `resources/js/Components/Activity/ActivityFeed.vue` — new list component.
- `resources/js/Components/Activity/ActivityFeedItem.vue` — new event-row component.
- `resources/js/Components/Activity/ActivityHeatmap.vue` — new 7×6 grid component.
- `resources/js/Components/Activity/RightActivityRail.vue` — accept optional `events` prop; render feed when present, empty-state otherwise; update tooltip language.
- `resources/js/Layouts/AppLayout.vue` — accept optional `activityEvents` prop and forward to both rail instances.
- `resources/js/Pages/Overview.vue` — pass `activityEvents` to layout; render `<ActivityHeatmap>` section; trim redundant footer line on the Visualizations card.
- `resources/js/types/index.d.ts` — add `ActivityEventType`, `ActivityEvent`, `ActivityHeatmapPayload` exports.
- `tests/Feature/SmokeTest.php` — assert the new prop shapes.

## Work log
Dated notes as work progresses.

### 2026-04-27
- Spec drafted; scope confirmed (4 decisions locked: 7×6 heatmap, 8–10 events, visual-only filter/cell-click, layout-prop plumbing).
- Opened issue [#15](https://github.com/Copxer/nexus/issues/15) and branch `spec/007-activity-feed-heatmap` off `main`.

### 2026-04-28
- Implemented `ActivityFeedItem.vue` (event-type → lucide icon map covering all §8.10 types; severity-tinted icon + title + source/time meta + optional metadata pill), `ActivityFeed.vue` (visual-only Recent/All tab pill + scrollable list), and `ActivityHeatmap.vue` (7×6 grid, 5-step purple→magenta intensity ramp, native `<title>` + `aria-label` per cell, Less/More legend).
- Wired the feed into `RightActivityRail.vue` — accepts optional `events` prop; renders `<ActivityFeed>` when populated, falls back to the existing empty-state otherwise (so Profile/Edit and other non-feed pages keep their current behavior). Updated the filter button tooltip from spec-language to phase-language.
- `AppLayout.vue` now accepts an optional `activityEvents` prop and forwards it to both rail instances (column + drawer). `Overview.vue` passes `recentActivity` through; other pages omit it and inherit the empty-state.
- `OverviewController` extended with two new top-level Inertia props: `recentActivity` (9 mock events with realistic relative `occurred_at` strings + a §8.10-type-mix: deployment, PR merge, alert, workflow fail, review request, website recovery, container OOM, issue, host recovery) and `activityHeatmap` (7×6 with a believable rhythm — quieter overnight + weekends, peak Wed 12 PM = 10 events).
- `Pages/Overview.vue` renders an `ActivityHeatmap` section between Service Health and the Visualizations card. Trimmed the now-redundant "ActivityFeed + Heatmap render in spec 007" line from the Visualizations footer.
- New types in `resources/js/types/index.d.ts`: `ActivityEventType` (union of §8.10 vocabulary), `ActivityEvent`, `ActivityHeatmapPayload`.
- `SmokeTest` adds `test_overview_carries_activity_feed_and_heatmap_payloads` — verifies `recentActivity` length is 9 with the expected per-event fields, and `activityHeatmap` is a 7×6 array.
- Manual verification at four breakpoints (Playwright Chrome): 1600 / 1024 (drawer) / 768 / 390:
    - Right rail shows the populated feed at desktop and the drawer variant on tablet, with the full 9-event list, severity-tinted icons, metadata pills (`US-EAST`, `CRITICAL`).
    - Heatmap reads as a compact grid in all viewports — peak (Wed 12 PM) is the brightest pink/magenta; weekend and overnight cells are darker.
    - Found and fixed: `auto` first column in the heatmap grid greedily absorbed leftover container width (~612px) and pushed all data cells to the right of the card. Switched to `min-content` for the label column + fixed-width day columns + `w-fit` on the figure so the heatmap stays compact and left-aligned regardless of card width.
- Pipeline (local): vue-tsc clean, Pint clean, `npm run build` green. 3 SmokeTest cases pass with 48 assertions.

## Decisions (locked 2026-04-27)
- **Heatmap shape — 7 cols × 6 rows.** Day-of-week × 4-hour bucket per roadmap §8.11. 7×24 is too dense at dashboard size.
- **Feed length — 8–10 mock events.** Fits the rail without scrolling on desktop; bigger sets feel padded.
- **Filter / cell-click — visual-only.** Same treatment as KPI cards / sidebar / time-range pill. Wiring real filter logic is meaningful when there's real data + scale to filter against.
- **Plumbing — layout-level prop forwarding.** `AppLayout` accepts `activityEvents` and passes it to both `<RightActivityRail>` instances. A Pinia store / composable is premature with one consumer page; revisit when 3+ pages need to read the feed.

## Open questions / blockers
- **Reverb wire-up.** The repo has Reverb env vars set (we configured them earlier). The roadmap §8.10 says "Broadcast event: ActivityEventCreated" — a real broadcast subscription is meaningful only when there are real events firing. Defer to phase 9 polish. This spec stays purely server-rendered mock data.
- **Heatmap accessibility model.** Treating the 42-cell grid as `role="img"` with a single descriptive `aria-label` summarizing the data, plus per-cell `<title>` tooltips for sighted users, is the lightweight choice. Going with that unless reviewer flags it during the agent pass.
