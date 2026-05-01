<?php

namespace App\Domain\Monitoring\Queries;

use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Support\Carbon;

/**
 * Count-based uptime summary over 24h / 7d / 30d windows (spec 024).
 *
 * **Definition**: `successful_checks / total_checks * 100`, where
 * `successful = status IN ('up', 'slow')`. Slow counts as up — a
 * successful response that was slow is still uptime.
 *
 * Each rate is a float 0–100 (rounded to 2 decimals), or `null` when
 * no checks landed in the window. The Vue layer renders null as
 * `—%` so the card doesn't pretend to know quality on no data.
 *
 * Three count queries per call (24h / 7d / 30d totals) plus three
 * "successful" subqueries plus one last-incident lookup. Cheap at
 * phase-1 scale (per-website monitor history capped naturally by the
 * configured check_interval and 24h–30d windows). Cache only when
 * slow-query logs flag it.
 */
class GetWebsitePerformanceSummaryQuery
{
    /**
     * @return array{
     *     uptime_24h: float|null,
     *     uptime_7d: float|null,
     *     uptime_30d: float|null,
     *     last_incident_at: Carbon|null,
     * }
     */
    public function execute(Website $website): array
    {
        $now = now();

        return [
            'uptime_24h' => $this->uptimeFor($website, $now->copy()->subDay()),
            'uptime_7d' => $this->uptimeFor($website, $now->copy()->subDays(7)),
            'uptime_30d' => $this->uptimeFor($website, $now->copy()->subDays(30)),
            'last_incident_at' => $this->lastIncidentAt($website),
        ];
    }

    /**
     * Uptime % for a half-open window `[from, now)`. Null when the
     * window is empty.
     */
    private function uptimeFor(Website $website, Carbon $from): ?float
    {
        $total = WebsiteCheck::query()
            ->where('website_id', $website->id)
            ->where('checked_at', '>=', $from)
            ->count();

        if ($total === 0) {
            return null;
        }

        $successful = WebsiteCheck::query()
            ->where('website_id', $website->id)
            ->where('checked_at', '>=', $from)
            ->whereIn('status', ['up', 'slow'])
            ->count();

        return round(($successful / $total) * 100, 2);
    }

    /**
     * `checked_at` of the most recent failed check (down or error),
     * or null if the website has never failed.
     */
    private function lastIncidentAt(Website $website): ?Carbon
    {
        return WebsiteCheck::query()
            ->where('website_id', $website->id)
            ->whereIn('status', ['down', 'error'])
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->value('checked_at');
    }
}
