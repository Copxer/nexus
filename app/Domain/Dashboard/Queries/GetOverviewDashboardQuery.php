<?php

namespace App\Domain\Dashboard\Queries;

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Model;
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
 *   - dashboard.topRepositories[] — ordered by stars_count desc, default
 *     limit 4. `commits` proxies via stars_count until phase 2 syncs
 *     real commit counts from GitHub.
 *
 * Still mock (extracted to MOCK_* constants — clearly marked):
 *   - dashboard.{deployments,services,alerts,uptime}  → MOCK_KPIS
 *   - recentActivity                                  → MOCK_ACTIVITY
 *   - activityHeatmap                                 → MOCK_HEATMAP
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
                'topRepositories' => $this->topRepositories(),
            ]),
            'recentActivity' => self::MOCK_ACTIVITY,
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
                'commits' => (int) $repo->stars_count,
                'share' => round((int) $repo->stars_count / $maxStars, 3),
            ])
            ->all();
    }

    /**
     * Zero-padded daily creation counts over the past `$days` (chronological).
     *
     * Generic enough to power both the Projects and Hosts sparklines —
     * the only other dashboard surfaces today that need real time-series.
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

    /** Phase 4 (Deployments), phase 5/6 (Services/Hosts), phase 7 (Alerts), phase 8 (Uptime). */
    private const MOCK_KPIS = [
        'deployments' => [
            'successful_24h' => 24,
            'change_percent' => 18,
            'sparkline' => [12, 14, 13, 16, 18, 20, 19, 22, 21, 23, 24, 24],
            'status' => 'success',
        ],
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

    /** Phase 3 ships the real activity feed. */
    private const MOCK_ACTIVITY = [
        [
            'id' => 'evt-001',
            'type' => 'deployment.succeeded',
            'severity' => 'success',
            'title' => 'Deployed nexus-api v2.14.3 to production',
            'source' => 'nexus-api',
            'occurred_at' => '2 min ago',
            'metadata' => 'us-east',
        ],
        [
            'id' => 'evt-002',
            'type' => 'pull_request.merged',
            'severity' => 'info',
            'title' => 'PR #142 — Switch session driver to Redis cluster',
            'source' => 'nexus-web',
            'occurred_at' => '14 min ago',
        ],
        [
            'id' => 'evt-003',
            'type' => 'alert.triggered',
            'severity' => 'danger',
            'title' => 'CPU sustained > 90% on prod-api-02 for 5 min',
            'source' => 'monitoring',
            'occurred_at' => '38 min ago',
            'metadata' => 'critical',
        ],
        [
            'id' => 'evt-004',
            'type' => 'workflow.failed',
            'severity' => 'danger',
            'title' => 'CI failed — flaky test on nexus-mail#main',
            'source' => 'nexus-mail',
            'occurred_at' => '52 min ago',
        ],
        [
            'id' => 'evt-005',
            'type' => 'pull_request.review_requested',
            'severity' => 'info',
            'title' => 'Review requested on PR #218 — Billing webhook hardening',
            'source' => 'nexus-api',
            'occurred_at' => '1 h ago',
        ],
        [
            'id' => 'evt-006',
            'type' => 'website.recovered',
            'severity' => 'success',
            'title' => 'status.nexus.io recovered after 3 m 12 s outage',
            'source' => 'monitoring',
            'occurred_at' => '2 h ago',
        ],
        [
            'id' => 'evt-007',
            'type' => 'container.unhealthy',
            'severity' => 'warning',
            'title' => 'billing-worker container restarted after OOM',
            'source' => 'prod-api-02',
            'occurred_at' => '3 h ago',
        ],
        [
            'id' => 'evt-008',
            'type' => 'issue.created',
            'severity' => 'muted',
            'title' => 'Login flow rejects valid 2FA codes intermittently',
            'source' => 'nexus-web',
            'occurred_at' => '4 h ago',
        ],
        [
            'id' => 'evt-009',
            'type' => 'host.recovered',
            'severity' => 'success',
            'title' => 'edge-eu-01 back online after scheduled maintenance',
            'source' => 'edge-eu-01',
            'occurred_at' => '6 h ago',
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
