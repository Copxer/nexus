<?php

namespace App\Domain\Dashboard\Queries;

use App\Models\Project;
use App\Models\Repository;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-side query for the Overview dashboard. First inhabitant of
 * `app/Domain/Dashboard/Queries` (roadmap §10.2). Returns the same
 * payload `OverviewController` used to compose inline; real DB-backed
 * fields and signposted mock data live side by side here so it's
 * obvious which slices are honest and which are still placeholders.
 *
 * Real today (database-backed):
 *   - dashboard.projects.{active,new_this_week,sparkline,status}
 *   - dashboard.hosts.{online,new,sparkline,status}
 *     (Repository::count() acts as a proxy until phase 6 ships actual
 *      hosts; the card label keeps "Hosts" so the visual doesn't shift,
 *      but the value reflects what we have data for.)
 *   - dashboard.deployments.{successful_24h,success_rate_24h,change_percent,sparkline,status}
 *     (Spec 022 — aggregates `workflow_runs` over 24h and prior-24h
 *      windows; sparkline counts daily completed runs across the last
 *      12 days.)
 *   - dashboard.topRepositories[] — ordered by stars_count desc, default
 *     limit 4. `commits` proxies via stars_count until phase 2 syncs
 *     real commit counts from GitHub.
 *
 * Still mock (extracted to MOCK_* constants — clearly marked):
 *   - dashboard.{services,alerts,uptime}              → MOCK_KPIS
 *   - activityHeatmap                                 → MOCK_HEATMAP
 *
 * The right-rail activity feed is no longer surfaced from this query —
 * the AppLayout consumes the shared `activity.recent` Inertia prop
 * populated by `HandleInertiaRequests` (specs 018/019), so Overview no
 * longer ships its own copy.
 *
 * Each MOCK constant comments the phase that ships its real source.
 *
 * TODO(multi-team): when team scoping arrives the public `handle()`
 * should accept a `Team` parameter (per roadmap §10.2's signature) and
 * filter the Project + Repository reads accordingly.
 */
class GetOverviewDashboardQuery
{
    /** Number of recent days surfaced in the per-KPI sparkline. */
    private const SPARKLINE_DAYS = 12;

    /** Default number of repositories returned by the Top Repositories slice. */
    private const TOP_REPOS_LIMIT = 4;

    public function handle(): array
    {
        return [
            'dashboard' => array_merge(self::MOCK_KPIS, [
                'projects' => $this->projects(),
                'hosts' => $this->hosts(),
                'deployments' => $this->deploymentsKpi(),
                'topRepositories' => $this->topRepositories(),
            ]),
            'activityHeatmap' => self::MOCK_HEATMAP,
        ];
    }

    /** Real `Project` slice. */
    private function projects(): array
    {
        $active = Project::query()->where('status', 'active')->count();
        $newThisWeek = Project::query()
            ->where('created_at', '>=', now()->subWeek())
            ->count();
        $sparkline = $this->dailyCounts(Project::class, self::SPARKLINE_DAYS);

        return [
            'active' => $active,
            'new_this_week' => $newThisWeek,
            'sparkline' => $sparkline,
            'status' => $active >= 1 ? 'success' : 'muted',
        ];
    }

    /**
     * Spec 022 — real Deployments KpiCard slice. Aggregates the
     * `workflow_runs` table over the last 24h (vs the prior 24h) for
     * the headline numbers, plus a 12-day daily-count sparkline.
     *
     * Window keys on `run_completed_at` so a long-running job lands in
     * the bucket where it actually completed — keeps the metric honest
     * about "what happened in the last 24h."
     *
     * `success_rate_24h` is null when no completed runs landed in the
     * window. The Vue layer renders that as `—% success` so the card
     * doesn't pretend to know quality on no data.
     *
     * `change_percent` clamps to `[-100, +999]` — without the cap, a
     * single-deploy account going from 0 → 1 successes would render
     * "+∞%" which reads broken.
     *
     * Status thresholds match the spec's "muted floor" rule: empty
     * window → muted (no signal); ≥95% → success; ≥80% → warning;
     * else → danger.
     *
     * @return array{
     *     successful_24h: int,
     *     success_rate_24h: int|null,
     *     change_percent: int,
     *     sparkline: array<int, int>,
     *     status: 'success'|'warning'|'danger'|'muted',
     * }
     */
    private function deploymentsKpi(): array
    {
        $now = now();
        $currentStart = $now->copy()->subDay();
        $previousStart = $now->copy()->subDays(2);

        $currentTotal = $this->completedRunCount($currentStart, $now);
        $currentSuccess = $this->successfulRunCount($currentStart, $now);
        $previousSuccess = $this->successfulRunCount($previousStart, $currentStart);

        $successRate = $currentTotal === 0
            ? null
            : (int) round(($currentSuccess / $currentTotal) * 100);

        // Cap the change pill so a 0 → 1 jump doesn't render `+∞%` on
        // a quiet account. Lower bound `-100` covers the all-disappeared
        // case (1 → 0 reads as `-100%`).
        $changePercent = (int) round(
            (($currentSuccess - $previousSuccess) / max($previousSuccess, 1)) * 100,
        );
        $changePercent = max(-100, min(999, $changePercent));

        return [
            'successful_24h' => $currentSuccess,
            'success_rate_24h' => $successRate,
            'change_percent' => $changePercent,
            'sparkline' => $this->workflowRunSparkline(self::SPARKLINE_DAYS),
            'status' => $this->deploymentsStatus($currentTotal, $successRate),
        ];
    }

    /**
     * Completed runs (any conclusion) whose `run_completed_at` falls in
     * the half-open window `[from, to)`.
     */
    private function completedRunCount(Carbon $from, Carbon $to): int
    {
        return WorkflowRun::query()
            ->where('status', 'completed')
            ->where('run_completed_at', '>=', $from)
            ->where('run_completed_at', '<', $to)
            ->count();
    }

    /**
     * Successful subset of `completedRunCount()` — same window semantics.
     */
    private function successfulRunCount(Carbon $from, Carbon $to): int
    {
        return WorkflowRun::query()
            ->where('status', 'completed')
            ->where('conclusion', 'success')
            ->where('run_completed_at', '>=', $from)
            ->where('run_completed_at', '<', $to)
            ->count();
    }

    /**
     * Daily completed-run counts (success + failure + every other
     * terminal conclusion) over the last `$days`, oldest-first. Mirrors
     * `dailyCounts()` but keys on `run_completed_at` and filters to
     * status = 'completed' so only finished runs land in the buckets.
     *
     * @return array<int, int>
     */
    private function workflowRunSparkline(int $days): array
    {
        $start = now()->startOfDay()->subDays($days - 1);

        /** @var Collection<int, object{date: string, total: int}> $rows */
        $rows = WorkflowRun::query()
            ->where('status', 'completed')
            ->where('run_completed_at', '>=', $start)
            ->selectRaw('DATE(run_completed_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $series[] = (int) ($rows->get($day)->total ?? 0);
        }

        return $series;
    }

    /**
     * Map (sample size, success rate) → KpiCard status tone.
     * `muted` floor on empty windows prevents quiet weekends from
     * flashing red on low-traffic accounts.
     *
     * @return 'success'|'warning'|'danger'|'muted'
     */
    private function deploymentsStatus(int $completedTotal, ?int $successRate): string
    {
        if ($completedTotal === 0 || $successRate === null) {
            return 'muted';
        }
        if ($successRate >= 95) {
            return 'success';
        }
        if ($successRate >= 80) {
            return 'warning';
        }

        return 'danger';
    }

    /** Hosts proxy (Repository count) until phase 6 ships real hosts. */
    private function hosts(): array
    {
        $online = Repository::query()->count();
        $new = Repository::query()
            ->where('created_at', '>=', now()->subWeek())
            ->count();
        $sparkline = $this->dailyCounts(Repository::class, self::SPARKLINE_DAYS);

        return [
            'online' => $online,
            'new' => $new,
            'sparkline' => $sparkline,
            'status' => $online >= 1 ? 'info' : 'muted',
        ];
    }

    /**
     * Top repositories by `stars_count desc`. Phase-1 has no real commit
     * data yet, so `commits` reuses `stars_count` as a popularity proxy.
     * `share` is each row's stars normalized against the brightest of
     * the returned slice — drives the gradient bar width on the page.
     */
    private function topRepositories(int $limit = self::TOP_REPOS_LIMIT): array
    {
        $rows = Repository::query()
            ->orderByDesc('stars_count')
            ->orderByDesc('last_pushed_at')
            ->limit($limit)
            ->get(['full_name', 'stars_count']);

        if ($rows->isEmpty()) {
            return [];
        }

        $maxStars = max(1, (int) $rows->max('stars_count'));

        return $rows
            ->map(fn (Repository $repo) => [
                'name' => $repo->full_name,
                'commits' => $repo->stars_count,
                'share' => round($repo->stars_count / $maxStars, 3),
            ])
            ->all();
    }

    /**
     * Zero-padded daily creation counts over the past `$days` (chronological,
     * oldest at index 0, today at the last index).
     *
     * Generic enough to power both the Projects and Hosts sparklines —
     * the only other dashboard surfaces today that need real time-series.
     *
     * **Timezone assumption.** Day boundaries use `now()->startOfDay()` in
     * the configured app timezone, while `DATE(created_at)` slices the raw
     * column value (UTC). This matches today's `config('app.timezone') = 'UTC'`
     * setup; revisit if/when the app moves to a non-UTC timezone — buckets
     * would otherwise drift by a day at the day-boundary edge.
     *
     * @param  class-string<Model>  $modelClass
     * @return array<int, int>
     */
    private function dailyCounts(string $modelClass, int $days): array
    {
        $start = now()->startOfDay()->subDays($days - 1);

        /** @var Collection<int, object{date: string, total: int}> $rows */
        $rows = $modelClass::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $series[] = (int) ($rows->get($day)->total ?? 0);
        }

        return $series;
    }

    // ──────────────────────────────────────────────────────────────────
    // Mock data — extracted from `OverviewController` so it's obvious
    // which slices are still placeholders. Each comment names the phase
    // that ships the real source.
    // ──────────────────────────────────────────────────────────────────

    /** Phase 5/6 (Services/Hosts), phase 7 (Alerts), phase 8 (Uptime). */
    private const MOCK_KPIS = [
        'services' => [
            'running' => 47,
            'health_percent' => 100,
            'sparkline' => [44, 45, 45, 46, 46, 47, 47, 47, 47, 47, 47, 47],
            'status' => 'success',
        ],
        'alerts' => [
            'active' => 3,
            'critical' => 1,
            'sparkline' => [1, 0, 1, 2, 1, 1, 2, 2, 3, 2, 3, 3],
            'status' => 'danger',
        ],
        'uptime' => [
            'overall' => 99.98,
            'change' => 0.01,
            'sparkline' => [99.92, 99.93, 99.95, 99.94, 99.96, 99.97, 99.96, 99.97, 99.98, 99.98, 99.97, 99.98],
            'status' => 'success',
        ],
    ];

    /** Phase 3 ships the real heatmap on top of activity-event aggregates. */
    private const MOCK_HEATMAP = [
        [1, 0, 1, 3, 2, 1], // Sun
        [2, 1, 4, 7, 6, 3], // Mon
        [1, 1, 5, 9, 8, 4], // Tue
        [2, 1, 6, 10, 9, 5], // Wed
        [2, 1, 5, 9, 7, 4], // Thu
        [1, 0, 4, 6, 5, 2], // Fri
        [0, 0, 1, 2, 1, 1], // Sat
    ];
}
