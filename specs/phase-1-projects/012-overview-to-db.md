---
spec: overview-to-db
phase: 1-projects
status: done
owner: yoany
created: 2026-04-28
updated: 2026-04-28
issue: https://github.com/Copxer/nexus/issues/30
branch: spec/012-overview-to-db
pr: https://github.com/Copxer/nexus/pull/31
---

# 012 — Wire Overview KPIs and Top Repositories widget to the database

## Goal
Replace the hardcoded mock data in `OverviewController` with real-database reads for the slices we can populate today. After this spec the Overview page's `Projects` KPI card, `Hosts` KPI card (acting as a repository-count proxy until phase 6 ships actual hosts), and the Top Repositories widget all reflect what's actually in the database. The remaining KPIs (`Deployments`, `Services`, `Alerts`, `Uptime`) stay mock until their owning phases ship — but their mock values move out of the controller and into a clearly-marked `MockOverviewData` constant so it's obvious which fields are honest and which are still placeholders.

This spec also formalizes the `App\Domain\Dashboard\Queries\GetOverviewDashboardQuery` layer the roadmap §10.2 asks for, and starts the architectural pattern (read query objects under `app/Domain/Dashboard/Queries/`) that future spec dashboards will reuse.

Roadmap reference: §8.1 Overview Dashboard, §8.1.1 KPI Cards (the data shape we keep), §10.2 Example Controller (the read-query pattern), §27 Layered architecture (`app/Domain/Dashboard`).
Visual target: existing Overview page; no UI changes — only data sources.

## Scope
**In scope:**

- **`App\Domain\Dashboard\Queries\GetOverviewDashboardQuery`.** Pure read query class. `handle()` returns the same JSON shape `OverviewController` produces today (`['dashboard' => [...], 'recentActivity' => [...], 'activityHeatmap' => [...]]`). Internally:
    - **Real fields:**
        - `dashboard.projects.active` ← `Project::query()->where('status', 'active')->count()`
        - `dashboard.projects.new_this_week` ← `Project::query()->where('created_at', '>=', now()->subWeek())->count()`
        - `dashboard.projects.sparkline` ← daily `Project` creation counts for the past 12 days, padded with zeros on quiet days, in chronological order. Real data, low fidelity acceptable for now.
        - `dashboard.projects.status` ← `'success'` if `active >= 1` else `'muted'`.
        - `dashboard.hosts.online` ← `Repository::query()->count()` (repository count masquerades as a "hosts" proxy until phase 6 lands real hosts; the label on the card stays "Hosts" so the visual doesn't shift, but the value reflects what we have data for).
        - `dashboard.hosts.new` ← `Repository::query()->where('created_at', '>=', now()->subWeek())->count()`.
        - `dashboard.hosts.sparkline` ← daily `Repository` creation counts for the past 12 days.
        - `dashboard.hosts.status` ← `'info'` if `count >= 1` else `'muted'`.
    - **Mock-but-extracted fields** (the four KPIs whose phases haven't shipped):
        - `dashboard.deployments`, `dashboard.services`, `dashboard.alerts`, `dashboard.uptime` — each pulled from a clearly-marked private constant array `MOCK_KPIS`. Same numeric values as the controller has today, just consolidated and signposted.
        - `recentActivity` — same mock 9 events as today, moved into a `MOCK_ACTIVITY` constant.
        - `activityHeatmap` — same 7×6 mock grid as today, moved into a `MOCK_HEATMAP` constant.
    - **Top repositories slice.** New query method `topRepositories(int $limit = 4)` returning the page's existing shape (`{ name, commits, share }`) computed from the `repositories` table: `name = full_name`, `commits = stars_count` (proxy until we have real commit data in phase 2), `share = stars_count / max(stars_count) of returned slice`. The page's existing `Top Repositories` widget already accepts this shape via the controller's `dashboard` payload — so the `dashboard` array gains a top-level `topRepositories: [...]` slice that the page reads from.

- **`OverviewController` simplified.** Drop the inline arrays. Inject `GetOverviewDashboardQuery` via the constructor (or method-inject in `__invoke`). Just calls `$query->handle()` and renders. Should drop from ~150 lines to ~25.

- **`Pages/Overview.vue` updated.** The hardcoded `stubRepos` array is replaced by the new `dashboard.topRepositories` prop from the controller. The other 3 stub widgets (Issues & PRs, Container Hosts, Service Health) and the Visualizations placeholder stay mock — they have nothing real to read yet. The `recentActivity` / `activityHeatmap` props stay wired the same way, just sourced from the query layer.

- **Type updates.** `resources/js/types/index.d.ts` extends `DashboardPayload` with `topRepositories: { name, commits, share }[]`.

- **Empty-state behavior on Overview.** When `dashboard.projects.active === 0`, the Projects KPI card still renders (showing `0 / Active / muted status`). The Top Repositories widget shows an empty-state ("Link a repository on a project to populate this") if `topRepositories` is empty. KPI cards with `count = 0` should not crash the sparkline (`Sparkline.vue` handles `points.length < 2` already — verify).

- **Tests.**
    - `GetOverviewDashboardQueryTest` — feature test covering: zero-projects baseline returns the right shape, after seeding 2 active projects + 1 archived `dashboard.projects.active` = 2, top-repositories slice respects the limit, sparkline arrays are 12 elements long, mock fields stay consistent.
    - Update `tests/Feature/SmokeTest.php` `test_overview_carries_the_mock_dashboard_payload` to assert the new `dashboard.topRepositories` shape exists.
    - The two activity-feed/heatmap assertions in SmokeTest stay as-is (still mock).

- **Update phase trackers** as part of the same PR — flip 012 row to 🟡 in `specs/phase-1-projects/README.md`, then to 🟢 at the bookkeeping PR after merge.

**Out of scope:**

- Replacing the other three KPI cards (Deployments, Services, Alerts, Uptime) with real data. Their phases haven't shipped — keep the values in `MOCK_KPIS` exactly as they are today.
- The 3 stub widgets (Issues & PRs, Container Hosts, Service Health) — same reasoning. They live in `Pages/Overview.vue` as inline arrays and stay there.
- The Activity Feed and Activity Heatmap — moving them into the query class is part of this spec (so the controller drops to ~25 lines), but their data stays mock. Phase-3 owns the real activity feed.
- A team / multi-tenant scope on the query. Today there's no team; the query reads everything in the projects/repositories tables. When team scoping arrives we'll add a `Team $team` parameter to `handle()` per roadmap §10.2's signature — flagged with a `TODO(multi-team)` comment.
- Caching the dashboard payload via Redis. The roadmap mentions caching dashboard cards (§4.5); we'll wire that in phase 9 polish along with skeleton loading states.
- Top Repositories ordering by real GitHub commit count. We use `stars_count` as a proxy this spec; phase 2's GitHub sync populates real commit counts.

## Plan

1. **Build `GetOverviewDashboardQuery`.** New class under `app/Domain/Dashboard/Queries/`. Methods: public `handle(): array` and private helpers (`projects()`, `hosts()`, `topRepositories()`, `dailyCounts()`, plus the three constants). Pure read — no side effects, no model events touched.
2. **Extract sparkline helper.** `dailyCounts(string $modelClass, int $days): array` — generic enough to reuse for `projects.sparkline` and `hosts.sparkline`. Returns 12 zero-padded daily counts in chronological order.
3. **Refactor `OverviewController`.** Constructor-inject the query. `__invoke()` becomes a single render call. Drop the inline arrays.
4. **Wire `topRepositories` into `Pages/Overview.vue`.** Replace the hardcoded `stubRepos` array with a prop-derived `topRepositories` (from the new `dashboard.topRepositories` field). Add a small empty state when the array is empty.
5. **Update types** in `resources/js/types/index.d.ts`.
6. **Verify `Sparkline` handles zero/empty arrays** — quick visual check at desktop with a fresh empty database.
7. **Tests.** New `GetOverviewDashboardQueryTest`. Update `SmokeTest`'s dashboard-payload assertion.
8. **Manual UX walk** at desktop + 768 + 360. Use both a seeded database and a fresh `migrate:fresh` (no projects, no repos) to exercise the empty-state paths.
9. **Pipeline pass** — Pint, vue-tsc, build, full PHP test run.
10. **Self-review** with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] `App\Domain\Dashboard\Queries\GetOverviewDashboardQuery` exists; `handle()` returns the same top-level keys (`dashboard`, `recentActivity`, `activityHeatmap`).
- [ ] `dashboard.projects.{active,new_this_week,sparkline,status}` are computed from `Project` model queries.
- [ ] `dashboard.hosts.{online,new,sparkline,status}` are computed from `Repository` model queries.
- [ ] `dashboard.deployments`, `services`, `alerts`, `uptime` come from a `MOCK_KPIS` constant (clearly named) and match today's values exactly.
- [ ] `dashboard.topRepositories` is a new array slice computed from `Repository` rows ordered by `stars_count` desc, capped at 4 by default.
- [ ] `recentActivity` and `activityHeatmap` come from `MOCK_ACTIVITY` and `MOCK_HEATMAP` constants on the same query class (still mock; clearly signposted).
- [ ] `OverviewController` is ≤ 30 lines; injects the query; no inline data.
- [ ] `Pages/Overview.vue`'s Top Repositories widget reads `dashboard.topRepositories` instead of the hardcoded `stubRepos`. Empty state visible when the array is empty.
- [ ] `DashboardPayload` type extended with `topRepositories: { name, commits, share }[]`.
- [ ] `GetOverviewDashboardQueryTest` covers zero-state, seeded-state, sparkline length, top-repositories limit, mock consistency.
- [ ] `SmokeTest::test_overview_carries_the_mock_dashboard_payload` passes with the new `topRepositories` shape assertion.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes — design tokens only.
- [ ] Pint clean, vue-tsc clean, `npm run build` green, CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
- `app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php` — new.
- `app/Http/Controllers/OverviewController.php` — slimmed to ~25 lines, injects the query.
- `resources/js/Pages/Overview.vue` — Top Repositories widget reads `dashboard.topRepositories`; drop the hardcoded `stubRepos` array; empty state when the slice is empty.
- `resources/js/types/index.d.ts` — extend `DashboardPayload` with `topRepositories`.
- `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` — new.
- `tests/Feature/SmokeTest.php` — extend dashboard-payload assertion with `topRepositories` shape.

## Work log
Dated notes as work progresses.

### 2026-04-28
- Spec drafted; scope confirmed (6 decisions locked: Hosts repo-count proxy, top-repos by stars desc, single query class, real daily-count sparklines, show empty state on Top Repos, skip caching).
- Opened issue [#30](https://github.com/Copxer/nexus/issues/30) and branch `spec/012-overview-to-db` off `main`.
- Implemented:
    - **`App\Domain\Dashboard\Queries\GetOverviewDashboardQuery`** — first inhabitant of the `app/Domain/Dashboard/` layer per roadmap §10.2. Public `handle()` returns the same top-level shape (`dashboard`, `recentActivity`, `activityHeatmap`). Private helpers: `projects()` + `hosts()` (real, DB-backed), `topRepositories(int $limit = 4)` (ordered by `stars_count desc`, normalizes `share` against the brightest entry), and a generic `dailyCounts($modelClass, $days)` sparkline helper used for both KPIs. Mock slices live in `MOCK_KPIS`, `MOCK_ACTIVITY`, `MOCK_HEATMAP` constants on the same class — each commented with the phase that ships its real source. Includes a `TODO(multi-team)` for when `handle()` will accept a `Team` parameter per §10.2.
    - **`OverviewController`** slimmed from ~150 lines (inline arrays) to ~20 lines (constructor-inject the query, single Inertia render).
    - **`Pages/Overview.vue`** Top Repositories widget reads `dashboard.topRepositories` instead of the hardcoded `stubRepos`. Header label changed from "7d · mock" to "By stars · live". Empty state renders a dashed-border placeholder with "Link a repository on a project to populate this." when the slice is empty. The repo-name column widened from `sm:w-32` to `sm:w-40` to accommodate the longer real `owner/name` strings (vs the seeded "nexus-web" / "nexus-api").
    - **`DashboardPayload` type** extended with `topRepositories: { name: string; commits: number; share: number }[]`. Comment notes `commits` is a `stars_count` proxy until phase-2 sync.
    - **`tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php`** — 8 cases: top-level shape, real Projects KPI count by status filter, muted projects status when empty, Hosts KPI proxies repo count, sparklines zero-padded to 12 entries, top repositories ordered by stars desc + capped at default limit, empty top repositories returns `[]`, mock KPIs / recentActivity / activityHeatmap stay consistent with phase-0 values.
    - **`SmokeTest::test_overview_carries_the_mock_dashboard_payload`** — extended with `->has('topRepositories')` and the `projects.status` assertion swapped from `'success'` to `'muted'` (the test's clean DB has no projects, so the live status correctly reads `muted`).
- Manual UX walk in Playwright Chrome (1440×900):
    - **Seeded state:** Overview shows Projects = 2 (active count from the `Project` table; Customer Portal v3 + Billing API are active, the other two are maintenance/paused), Hosts = 9 (`Repository` count proxy), Top Repositories ordered by real stars (686/605/507/488 across the seeded mix). Mock KPIs (Deployments/Services/Alerts/Uptime) and the page-level stub widgets (Issues & PRs, Container Hosts, Service Health) continue to render unchanged.
    - **Empty state** (after `Repository::query()->delete(); Project::query()->delete()`): Projects KPI = 0 with a muted badge, Hosts KPI = 0, Top Repositories widget shows the dashed-border "Link a repository on a project to populate this." placeholder. Sparklines flat-line at the bottom (the `pts.length < 2` early-return + `range || 1` fallback handle this gracefully).
- Pipeline: Pint clean, vue-tsc clean, `npm run build` green. **49 tests pass with 232 assertions** (8 new dashboard query + 21 repository + 14 project + 6 existing smoke/heartbeat/horizon-access).
- Self-review with `superpowers:code-reviewer`. **No blockers; 1 material + 2 nits addressed.** Final test count: **51 tests, 238 assertions.**
    - **[material, fixed]** Test gap on `dailyCounts` chronological correctness. Reviewer flagged that the existing tests checked length and the all-zero case but didn't pin loop direction or off-by-one. Added two regression cases: `test_sparkline_lands_today_at_the_last_index` (a project created `now()` lands at index 11) and `test_sparkline_lands_oldest_day_at_index_zero` (a project created `subDays(11)->startOfDay()` lands at index 0). Future refactors that flip chronology will fail loudly instead of silently breaking the sparkline visual.
    - **[material, documented]** Timezone footgun in `dailyCounts`. `now()->startOfDay()` runs in the configured app timezone but `DATE(created_at)` runs against the raw UTC column. Today's `config('app.timezone') = 'UTC'` makes them agree; if the app ever moves to a non-UTC timezone the buckets could drift by a day at midnight. Added a `**Timezone assumption**` paragraph to the helper's docblock so a future reader hits the warning before flipping the config.
    - **[nit, fixed]** Redundant `(int)` casts in `topRepositories()`. The model already casts `stars_count` to integer and the migration declares `unsignedInteger`. Dropped both casts.
    - **[nit, fixed]** `last_pushed_at` not pinned in the top-repos ordering test. Reviewer flagged that null-default `last_pushed_at` could flap on different DB engines if multiple repos share star counts. Pinned `last_pushed_at = now()->subMinutes($i)` in the test loop so the tie-breaker chain is deterministic.
    - Reviewer also confirmed: `class-string<Model>` PHPDoc is valid (Larastan would flag, but we don't run it; defer the `Builder` swap until needed); flat sparkline at zero is acceptable (true representation of "nothing happened"); `'muted'` tone with hardcoded "Healthy" label is acceptable for this spec (file a phase-2 polish ticket to derive the label from `status`); `last_pushed_at` tie-breaker is right; `(string) $row->date` cast in `keyBy` is harmlessly defensive; `MOCK_ACTIVITY` / `MOCK_HEATMAP` drift risk is low — spec 007's screenshot tests assert structure, not specific titles.

## Decisions (locked 2026-04-28)
- **Hosts KPI — wire the proxy.** Card label stays "Hosts" but the value is `Repository::count()` until phase 6 lands actual hosts. Documented as a proxy in the query class.
- **Top Repositories — `stars_count desc`.** Proxy for popularity until phase 2 syncs real GitHub data.
- **Domain layer — single class.** `GetOverviewDashboardQuery` returns the whole payload. Split per-KPI later if a slice gets expensive enough to deserve its own cache.
- **Sparklines — real daily counts.** Zero-padded over the past 12 days. Honest, even if quiet.
- **Top Repositories empty state — show.** "Link a repository on a project to populate this" — same treatment as the Projects index empty state.
- **Caching — skip.** 9-row dataset renders fast. Add caching in phase 9 polish alongside skeleton loading states.

## Open questions / blockers

- **PHP 8.5 + Laravel 13.6 CSRF-in-tests issue** still present locally for the legacy auth tests. Not introduced by this spec; CI passes on PHP 8.4. Same disclaimer.
