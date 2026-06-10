<?php

namespace App\Domain\Analytics\Queries;

use App\Models\User;
use App\Models\WebsiteCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Website uptime + average response time for `/analytics` (spec
 * 034). Scoped through `website_checks.website_id` → `websites.
 * project_id` → `projects.owner_user_id` so the page only sees the
 * authenticated user's data.
 *
 * Uptime mirrors the volume-weighted shape of `GetMonitoringUptimeKpiQuery`
 * (spec 025) but lives here as a fresh implementation rather than a
 * delegate — that older query is hard-coded to a 12-day single-tenant
 * Overview window, and refactoring it to optionally accept a user +
 * arbitrary range would risk Overview regressions. The math is small
 * and duplicating it keeps the two paths independently evolvable.
 *
 * Response time = mean `response_time_ms` over `up`-status checks
 * only. `down` / `error` checks usually have null timings (transport
 * never completed); `slow` checks are included since they're real
 * timed responses.
 */
class GetWebsiteMetricsQuery
{
    /**
     * @return array{
     *     uptime: array{percent: float|null, sparkline: array<int, float>, status: 'success'|'warning'|'danger'|'muted'},
     *     response_time: array{avg_ms: int|null, sparkline: array<int, int|null>, status: 'success'|'warning'|'danger'|'muted'},
     * }
     */
    public function execute(User $user, Carbon $from): array
    {
        $days = $this->dayCount($from);
        $now = now();

        $base = WebsiteCheck::query()
            ->join('websites', 'website_checks.website_id', '=', 'websites.id')
            ->join('projects', 'websites.project_id', '=', 'projects.id')
            ->where('projects.owner_user_id', $user->id)
            ->where('website_checks.checked_at', '>=', $from)
            ->where('website_checks.checked_at', '<', $now);

        $total = (clone $base)->count('website_checks.id');

        if ($total === 0) {
            return [
                'uptime' => [
                    'percent' => null,
                    'sparkline' => array_fill(0, $days, 100.0),
                    'status' => 'muted',
                ],
                'response_time' => [
                    'avg_ms' => null,
                    'sparkline' => array_fill(0, $days, null),
                    'status' => 'muted',
                ],
            ];
        }

        $successful = (clone $base)
            ->whereIn('website_checks.status', ['up', 'slow'])
            ->count('website_checks.id');

        $uptimePercent = round(($successful / $total) * 100, 2);

        $avgMs = (clone $base)
            ->where('website_checks.status', 'up')
            ->whereNotNull('website_checks.response_time_ms')
            ->avg('website_checks.response_time_ms');

        $avgMsInt = $avgMs === null ? null : (int) round((float) $avgMs);

        // Sparkline: one query each so the SQL stays simple and
        // portable. Both group by date in the matched range.
        /** @var Collection<int, object{date: string, total: int, successful: int}> $uptimeRows */
        $uptimeRows = (clone $base)
            ->selectRaw(
                'DATE(website_checks.checked_at) as date,'
                .' COUNT(website_checks.id) as total,'
                .' SUM(CASE WHEN website_checks.status IN (?, ?) THEN 1 ELSE 0 END) as successful',
                ['up', 'slow'],
            )
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        /** @var Collection<int, object{date: string, avg_ms: float|null}> $rtRows */
        $rtRows = (clone $base)
            ->where('website_checks.status', 'up')
            ->whereNotNull('website_checks.response_time_ms')
            ->selectRaw('DATE(website_checks.checked_at) as date, AVG(website_checks.response_time_ms) as avg_ms')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $uptimeSpark = [];
        $rtSpark = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $up = $uptimeRows->get($day);
            $rt = $rtRows->get($day);

            $uptimeSpark[] = ($up === null || (int) $up->total === 0)
                ? 100.0
                : round(((int) $up->successful / (int) $up->total) * 100, 2);

            $rtSpark[] = ($rt === null || $rt->avg_ms === null)
                ? null
                : (int) round((float) $rt->avg_ms);
        }

        return [
            'uptime' => [
                'percent' => $uptimePercent,
                'sparkline' => $uptimeSpark,
                'status' => $this->uptimeStatus($uptimePercent),
            ],
            'response_time' => [
                'avg_ms' => $avgMsInt,
                'sparkline' => $rtSpark,
                'status' => $this->responseTimeStatus($avgMsInt),
            ],
        ];
    }

    private function dayCount(Carbon $from): int
    {
        return (int) $from->diffInDays(now()->startOfDay()) + 1;
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function uptimeStatus(?float $percent): string
    {
        return match (true) {
            $percent === null => 'muted',
            $percent >= 99.0 => 'success',
            $percent >= 95.0 => 'warning',
            default => 'danger',
        };
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function responseTimeStatus(?int $ms): string
    {
        // 3000ms aligns with Phase 5's `WebsiteStatus::Slow` threshold.
        return match (true) {
            $ms === null => 'muted',
            $ms < 1000 => 'success',
            $ms < 3000 => 'warning',
            default => 'danger',
        };
    }
}
