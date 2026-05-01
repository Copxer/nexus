---
spec: overview-success-rate-widget
phase: 4-deployments-cicd
status: done
owner: yoany
created: 2026-04-30
updated: 2026-04-30
issue: https://github.com/Copxer/nexus/issues/66
branch: spec/022-overview-success-rate-widget
---

# 022 — Overview success-rate widget

## Goal
Swap the mocked **Deployments (24h)** KPI card on `/overview` for a real query against the `workflow_runs` table shipped in spec 020. After this spec, the card honestly reflects the user's GitHub Actions activity in the last 24 hours: count of successful runs, rate vs total, change vs the prior 24h window, and a 12-day daily-count sparkline.

This is the **last spec of phase 4** — closing out the roadmap's "deployment success rate chart" deliverable.

Roadmap reference: §19 Phase 4 acceptance ("Dashboard shows latest deployments"), §10.2 `GetOverviewDashboardQuery`.

## Scope
**In scope:**

- **`GetOverviewDashboardQuery::deploymentsKpi()`** — new private method replacing the `MOCK_KPIS['deployments']` slice. Returns the existing array shape so `KpiCard` props in `Overview.vue` need no rename:
    ```
    [
        'successful_24h' => int,        // primary value
        'success_rate_24h' => int,      // 0–100, drives the secondary line
        'change_percent' => int,        // +/- vs previous 24h window
        'sparkline' => array<int>,      // 12 entries, daily completed-run counts
        'status' => 'success'|'warning'|'danger'|'muted',
    ]
    ```

- **Query semantics:**
    - **`successful_24h`** — `WorkflowRun::where('conclusion', 'success')->where('run_completed_at', '>=', now()->subDay())->count()`. The `run_completed_at` window (NOT `run_started_at`) keeps the metric honest — long-running jobs land in the bucket they completed in.
    - **`success_rate_24h`** — `successful / completedTotal * 100`, rounded to integer percent. `completedTotal` filters `status = 'completed'` over the same 24h window. Returns `null` if `completedTotal` is 0; the Vue layer renders that as `—%`.
    - **`change_percent`** — compare current 24h success count to the `[-48h, -24h]` window. Computed as `(current - previous) / max(previous, 1) * 100` rounded to integer percent. Capped at `-100` / `+999` to avoid runaway labels on near-zero baselines.
    - **`sparkline`** — daily completed-run counts (success + failure + cancelled etc.) over the last 12 days, oldest-first. Mirrors how `projects()`'s sparkline counts new projects per day so the squiggle's mental model stays "daily activity, not quality."
    - **`status`** — derived from the **rate**, with a **sample-size floor** so quiet windows don't flash red:
        - `completedTotal === 0` → `muted`
        - `success_rate_24h >= 95` → `success`
        - `success_rate_24h >= 80` → `warning`
        - else → `danger`

- **Single-tenant scoping (phase-1).** Match the existing `projects()` / `hosts()` slices: cross-user counts on the assumption of a single owner. The roadmap's already-documented `TODO(multi-team)` on `GetOverviewDashboardQuery` covers the future change uniformly across all slices — don't introduce a per-slice `User` arg now.

- **`Pages/Overview.vue` — the Deployments KpiCard:**
    - Update the `dashboard.deployments` TypeScript shape to add `success_rate_24h: number | null`.
    - Render `secondary="92% success"` from a small computed (or inline expression). When `success_rate_24h === null` (no completed runs in window), render `"—% success"` so the card stays visually balanced rather than collapsing the line.
    - The `value`, `change_percent`, `sparkline`, `status` props already wire through unchanged.
    - Use the shared workflow-run tone helpers when natural, but the `status` enum here is the page's existing `success|warning|danger|muted` set — not the WorkflowRun-specific one — so don't force the import.

- **Mock-block hygiene.** Drop `'deployments' => [...]` from `MOCK_KPIS`. Update the doc-comment block on the query class so the "Real today" / "Still mock" lists reflect the move.

- **Tests** (Pest/PHPUnit, mirrors existing query tests):
    - `GetOverviewDashboardQueryTest::test_deployments_kpi_counts_successful_runs_in_24h_window`.
    - `…test_deployments_kpi_excludes_runs_outside_24h_window` — runs completed > 24h ago aren't counted.
    - `…test_deployments_kpi_change_percent_compares_to_previous_24h` — seed runs in `-48h..-24h` and `-24h..now` windows; assert the delta.
    - `…test_deployments_kpi_change_percent_handles_zero_previous` — previous window empty, current window has runs → caps at +999.
    - `…test_deployments_kpi_status_thresholds` — table-driven: 0 runs → muted, 95% → success, 85% → warning, 50% → danger, 100% → success.
    - `…test_deployments_kpi_sparkline_counts_daily_completed_runs` — 12 entries, oldest first, last entry covers today.
    - `…test_deployments_kpi_returns_null_rate_when_no_completed_runs` — assert `success_rate_24h` is null and the Vue type tolerates that.
    - Existing Overview controller test gets one new assertion that `dashboard.deployments` is present + shape-checked.

**Out of scope:**

- Activity heatmap (still mock — phase 3 polish carryover; will get a follow-up if it proves load-bearing).
- Realtime push for the Overview KPI — page-load fresh; the dedicated `/deployments` page already broadcasts. Adding Echo subscription on Overview just for one card would be over-engineering.
- A separate "deployment trends" chart / drill-down. The KPI card links nowhere new; users click through to `/deployments` from the sidebar when they want depth.
- "Per project" breakdown of the rate. Single-tenant phase-1 doesn't surface team-scoped views.
- Caching the aggregate. Three index-backed counts on a 24h window are cheap; revisit if a real user has thousands of runs in their account.

## Plan

1. **Migration check** — `workflow_runs` already has `status`, `conclusion`, `run_completed_at`, and the `(repository_id, run_started_at)` index. Add a brief `(conclusion, run_completed_at)` index ONLY if the query plan shows it's needed; otherwise skip — `EXPLAIN` first.
2. **Query method** — `deploymentsKpi()` private method on `GetOverviewDashboardQuery`. Mirror the shape of `projects()` / `hosts()`.
3. **Wire it in** — replace the `MOCK_KPIS['deployments']` lookup. Drop the mock entry. Update the class doc-comment.
4. **Tests** — table-driven status thresholds + each branch (window scoping, change-vs-previous, zero-previous edge, null-rate edge, sparkline ordering).
5. **Vue page** — extend the TS interface (`success_rate_24h: number | null`), update the `secondary` prop expression, verify all existing card visuals.
6. **Self-review pass via `superpowers:code-reviewer`**.
7. **Open the PR** with the standard body shape.

## Acceptance criteria
- [ ] Mock `'deployments'` block removed from `MOCK_KPIS`; class doc-comment lists deployments under "Real today."
- [ ] `successful_24h` reflects the count of `WorkflowRun` rows with `conclusion = 'success'` and `run_completed_at` within the last 24h.
- [ ] `success_rate_24h` is null when `completedTotal` in the window is 0; otherwise an integer 0–100.
- [ ] `change_percent` compares the current 24h success count to the `[-48h, -24h]` window; capped at `-100` / `+999`.
- [ ] Sparkline returns 12 integer entries oldest-first; each entry is the day's completed-run count (success + failure + every other terminal conclusion).
- [ ] Status mapping: empty window → `muted`; rate `≥ 95` → `success`; `[80, 95)` → `warning`; `< 80` → `danger`.
- [ ] `Overview.vue` renders the secondary line as `"{rate}% success"` when `success_rate_24h` is non-null; `"—% success"` when null.
- [ ] All other KpiCard props on the deployments card resolve from real data (sparkline, change indicator, status pill).
- [ ] Pint + `php artisan test` (full suite) + `npm run build` clean. CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before opening the PR.

## Files touched
- `app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php` — new `deploymentsKpi()` method, drop mock entry, update class doc-comment.
- `resources/js/Pages/Overview.vue` — extend TS interface, update secondary prop.
- `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` — new test file (or extend existing one if present).
- `tests/Feature/Smoke/OverviewSmokeTest.php` (or wherever the controller test lives) — extend with deployment-shape assertion.
- `specs/README.md` — phase 4 tracker.
- `specs/phase-4-deployments-cicd/README.md` — task tracker.

## Work log
Dated notes as work progresses.

### 2026-04-30
- Spec drafted.
- Opened issue [#66](https://github.com/Copxer/nexus/issues/66) and branch `spec/022-overview-success-rate-widget` off `main`.
- Implementation complete. `GetOverviewDashboardQuery::deploymentsKpi()` aggregates `workflow_runs` over the 24h window (keyed on `run_completed_at`) and the prior 24h for `change_percent`. Three small helpers — `completedRunCount`, `successfulRunCount`, `workflowRunSparkline` — keep the main method readable. `MOCK_KPIS['deployments']` removed; class doc-comment lists the slice under "Real today."
- 14 net new passing tests covering the zero state, 24h window scoping, change-percent vs prior 24h, +999 cap, -100 floor, threshold boundaries at 95% / 80%, the warning + danger bands, sparkline daily counts (success + failure + cancelled all land), and sparkline excluding in-progress runs.
- Self-review pass via `superpowers:code-reviewer`; addressed both substantive findings — added the boundary + cap-floor tests; documented the index-deferral checkpoint here (see below) instead of silently dropping the spec's "EXPLAIN first" line.
- **`run_completed_at` index — deferred.** The new aggregate has three predicates (`status`, `conclusion`, `run_completed_at`); the existing `(repository_id, run_started_at)` index doesn't cover them. At phase-1 scale (low row count) the planner will scan; that's fine for now. Revisit once a real account crosses ~5–10k workflow runs and the dashboard load shows up in slow-query logs. A composite `(status, conclusion, run_completed_at)` would be optimal when needed.

## Decisions (locked 2026-04-30)
- **Secondary line shows `92% success` (option B).** Static "Successful" carries no signal; rate is the roadmap's stated deliverable. Primary stays the count for grid consistency with the other 5 KpiCards.
- **Sparkline = daily completed-run counts (option A).** Every other KpiCard's sparkline is a count; switching one card to a percent line would break the user's mental model. Volume and quality are two axes — the squiggle owns volume, the secondary line owns quality.
- **Status thresholds: 95 / 80 / 0 with a `muted` empty floor.** Sample-size floor prevents quiet weekends from flashing red on low-traffic accounts.
- **24h window keys on `run_completed_at`, not `run_started_at`.** Long-running jobs land in their completion bucket — keeps the metric honest about "what happened in the last 24h."
- **Single-tenant scoping (no `User` arg added).** Matches the existing `projects()` / `hosts()` slices' phase-1 simplification. The class-level multi-team TODO covers the future migration uniformly.

## Open questions / blockers
- **Index check.** Confirm the existing `(repository_id, run_started_at)` index is enough for the new aggregate; add `(conclusion, run_completed_at)` only if `EXPLAIN` flags a sequential scan.
