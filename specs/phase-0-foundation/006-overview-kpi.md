---
spec: overview-kpi
phase: 0-foundation
status: in-progress
owner: yoany
created: 2026-04-27
updated: 2026-04-27
issue: https://github.com/Copxer/nexus/issues/12
branch: spec/006-overview-kpi
---

# 006 — Overview page with mock KPI cards, sparklines, status badges

## Goal
Replace the placeholder body of `/overview` with the futuristic 6-card KPI row from the visual reference, fed by mock data from a thin controller. Introduce the three reusable dashboard primitives — `KpiCard`, `Sparkline`, `StatusBadge` — that every later widget (Service Health, Container Hosts, Top Repositories, etc.) will compose.

After this spec, the Overview page should look populated: the headline KPI row up top, then placeholder glass-cards for the widgets that ship in spec 007 and later (Activity feed/heatmap, map, charts, hosts, etc.). No real integrations — every value is hardcoded in the controller and matches the JSON shape from roadmap §8.1.1.

Roadmap reference: §8.1 Overview Dashboard, §8.1.1 KPI Cards.
Visual target: [`../visual-reference.md`](../visual-reference.md) → [`../../nexus-dashboard.png`](../../nexus-dashboard.png) (top KPI row + the empty grid below it).

## Scope
**In scope:**
- `App\Http\Controllers\OverviewController` (single-action, `__invoke`) returning an Inertia response with a single `dashboard` prop matching §8.1.1's JSON shape verbatim. Hardcoded values for now; the data shape is the contract.
- `routes/web.php` updated to dispatch `/overview` to `OverviewController` instead of the inline closure. Middleware (`auth`, `verified`) and route name (`overview`) preserved exactly.
- Three new dashboard primitives, all under `resources/js/Components/Dashboard/`:
    - **`KpiCard.vue`** — composes the others; props: `label`, `value`, `valueLabel?`, `icon`, `accent` (one of `cyan|blue|purple|magenta|success|warning|danger`), `trend?` (`{ direction: 'up' | 'down' | 'flat'; value: string }`), `status?` (`'success' | 'warning' | 'danger' | 'info'`), `sparkline?` (`number[]`), and an optional `href?`/`disabled?` for click-to-detail. Renders inside a `glass-card` with the icon top-left (soft accent glow), the big tabular-nums numeric value, the secondary label, the trend chip, and the sparkline behind/under the number.
    - **`Sparkline.vue`** — pure inline SVG `<polyline>` + faint area fill. Props: `points: number[]`, `accent?` (default `cyan`), `aria-hidden`. ~30 LOC. No third-party charting dep.
    - **`StatusBadge.vue`** — small pill: dot + label, with a soft accent glow only on `success` (consistent with "glow reserved for active states" rule in visual-reference). Props: `tone: 'success' | 'warning' | 'danger' | 'info'`, default slot for label.
    - **`TrendChip.vue`** — small inline pill rendered next to the value: ↑/↓/→ icon + change string + optional `tone` override. Up = success-tinted, down = danger-tinted, flat = muted.
- `resources/js/Pages/Overview.vue` rebuilt:
    - 6 KPI cards (Projects, Deployments, Services, Hosts, Alerts, Uptime) in a responsive grid: 2 cols mobile → 3 cols tablet → 6 cols desktop. Each card is `aria-disabled` for now (target sections not yet built), but visually styled as a clickable card with a focus ring — consistent with the "Soon" treatment from sidebar/palette.
    - Below the KPI row: a 12-col grid of placeholder glass-cards stubbing the next-spec widgets (Activity feed, Activity heatmap, World map, Resource Utilization chart, Website Performance chart, Container hosts, Service health, Top repositories, Deployment timeline, System metrics). Each placeholder includes:
        - A real heading + icon (matches the visual reference's chrome).
        - A small representative preview using 2–4 lines of hardcoded mock data (e.g., 3 mock activity rows for the activity-feed stub, 3 mock hosts with status dots for the hosts stub, 4 mock services for service-health, 3 mock repos for top-repositories) so the page looks populated rather than empty.
        - A faint footer microcopy like "Full widget lands with spec 007" linking to the owning spec — reviewer can tell at a glance which chunks are stubs.
    - The stubs reuse existing primitives only (`glass-card`, `Sparkline`, `StatusBadge`, lucide icons). They are intentionally not interactive and are not the canonical implementation of those widgets — each future spec replaces its own stub with the real component.
- Tests:
    - Update `tests/Feature/SmokeTest.php` to also assert the response contains a `dashboard` prop with the 6 expected keys (one Inertia assertion). The existing `200` check stays.

**Out of scope:**
- Real backend data. Every value is hardcoded.
- A `Domain/Dashboard/Queries/GetOverviewDashboardQuery` class — the controller hardcodes the data; we'll formalize the domain layer once we have real integrations.
- Wiring the click-to-detail navigation. Cards are inert this spec.
- Activity feed / heatmap (spec 007), map / charts / hosts / service health / repositories / timeline / metrics (later phases).
- Theme variants — dark only.
- Animations beyond the existing ≤200ms hover transitions.

## Plan
1. Build the leaf primitives first so the integration step is easy:
    - `Sparkline.vue` (mock with 12-point arrays, normalize to `viewBox="0 0 100 24"`).
    - `StatusBadge.vue`.
    - `TrendChip.vue`.
2. Build `KpiCard.vue` composing them.
3. Backend wiring:
    - Generate `OverviewController` (single-action, `__invoke`).
    - Hardcode the dashboard payload from §8.1.1 example data, plus a `sparkline: number[]` per card and a `status` token per card.
    - Update `routes/web.php`.
4. Rebuild `Pages/Overview.vue`:
    - Receive `dashboard` prop typed via the existing inertia.d.ts pattern (extend the page-prop typing).
    - Render the 6 KpiCards in the responsive grid.
    - Render the placeholder grid below.
5. Run dev server and visually compare with `nexus-dashboard.png`. Adjust spacing / sizes / accents until the KPI row matches.
6. Update the smoke test to assert the new prop shape; optionally add a small Vitest-style logical assertion if Vitest gets wired in time (remember: deferred from spec 005, separate chore PR).
7. Pipeline (Pint, vue-tsc, build, tests).
8. Self-review with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] `/overview` renders 6 KPI cards (Projects, Deployments, Services, Hosts, Alerts, Uptime) populated with the §8.1.1 example numbers.
- [ ] Each card shows: icon (top-left, soft accent glow), big tabular-nums value, secondary label, trend chip, status badge (or dot), mini sparkline.
- [ ] Cards are responsive: 2 cols < md, 3 cols md, 6 cols xl. Numbers and chips don't truncate awkwardly at any breakpoint.
- [ ] `KpiCard.vue`, `Sparkline.vue`, `StatusBadge.vue`, `TrendChip.vue` all live under `resources/js/Components/Dashboard/` and are exported as standalone components for reuse in later widgets.
- [ ] Cards are `aria-disabled` (click-to-detail comes when the target section exists) but render with a focus ring on Tab.
- [ ] Below the KPI row, the page renders placeholder glass-cards for the next-spec widgets at correct grid sizes, each with a "Lands with spec 0XX" microcopy line.
- [ ] `OverviewController` returns an Inertia response with a single `dashboard` prop matching the §8.1.1 JSON shape (controller is invocable; route name `overview` and middleware preserved).
- [ ] No real integrations / API calls / DB queries — values are hardcoded.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes leak in — design tokens only.
- [ ] No regressions: existing tests still pass on CI; the SmokeTest now also asserts the `dashboard` prop shape.
- [ ] Pint clean, `vue-tsc` clean, `npm run build` green, CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
- `app/Http/Controllers/OverviewController.php` — new single-action controller returning the mock dashboard payload.
- `routes/web.php` — dispatch `/overview` to `OverviewController` instead of an inline closure (middleware + name preserved).
- `resources/js/Components/Dashboard/KpiCard.vue` — composed card.
- `resources/js/Components/Dashboard/Sparkline.vue` — pure SVG sparkline.
- `resources/js/Components/Dashboard/StatusBadge.vue` — pill badge with tone variants.
- `resources/js/Components/Dashboard/TrendChip.vue` — ↑/↓/→ trend pill.
- `resources/js/Pages/Overview.vue` — rebuilt to render the KPI row and placeholder widget grid.
- `tests/Feature/SmokeTest.php` — extend with `dashboard` prop shape assertion.

## Work log
Dated notes as work progresses.

### 2026-04-27
- Spec drafted; scope confirmed (4 decisions locked: roll-our-own SVG sparkline, inert+aria-disabled click-to-detail, thin controller, populated stub widgets).
- Opened issue [#12](https://github.com/Copxer/nexus/issues/12) and branch `spec/006-overview-kpi` off `main`.

## Decisions (locked 2026-04-27)
- **Sparkline implementation — roll our own.** Pure-SVG polyline + faint area fill (~30 LOC), no dep. Charting library is a future phase decision when real charts ship.
- **Click-to-detail behavior — inert + aria-disabled.** Cards render with focus rings but don't navigate. Same treatment as the sidebar / command palette "Soon" entries. Activated when the target sections exist.
- **Backend abstraction — thin controller, hardcoded data.** No `App\Domain\Dashboard\Queries\GetOverviewDashboardQuery` layer yet; we'll formalize it when real integrations land. Saves a layer of indirection over what is currently a constant.
- **Placeholder widgets — populated stubs, not empty boxes.** Each future-spec widget renders with real chrome + 2–4 lines of representative mock data + a "Full widget lands with spec 0XX" footer. Page should look populated, not skeletal. Each future spec replaces its own stub with the canonical implementation.

## Open questions / blockers
- **Status mapping per card.** Need to lock which `status` token each KPI displays at the example values:
    - Projects (active 12): success
    - Deployments (24 successful, +18%): success
    - Services (47 running, 100% health): success
    - Hosts (128 online, 4 new): info
    - Alerts (3 active, 1 critical): danger
    - Uptime (99.98%, +0.01): success
  These are placeholder mappings only. Real status logic comes when the integrations land.
- **Inertia page-prop typing.** The repo already types Inertia props in `resources/js/types/`. Extending that for the new `dashboard` prop is part of this spec — no new typing infrastructure.
