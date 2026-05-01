<?php

namespace App\Domain\Monitoring\Queries;

use App\Models\WebsiteCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cross-website uptime aggregate for the Overview's Uptime KpiCard
 * (spec 025). Replaces the long-standing `MOCK_KPIS['uptime']`
 * placeholder with real data from `website_checks`.
 *
 * **Volume-weighted definition** (locked decision B): rate is
 * `successful_checks / total_checks * 100` across **all** websites
 * over the last 24h. A busy website with one failure contributes
 * more to the system-wide rate than a quiet website with one success
 * — the truest "are my services up" measure.
 *
 * Returned shape mirrors `MOCK_KPIS['uptime']` exactly so the
 * `KpiCard` props in `Overview.vue` need no rename:
 *   overall   → 24h volume-weighted % (or null when no checks anywhere)
 *   change    → overall - previous-24h overall (or 0 when either side empty)
 *   sparkline → 12 daily uptime % values, oldest-first
 *   status    → muted | success (≥99) | warning (≥95) | danger
 *
 * **Sparkline empty-day default of 100.0** — a fresh account with no
 * checks before today shouldn't render as a 0-percent flatline (would
 * read as "everything was down"). 100 reads as "no failures observed"
 * which is the honest interpretation of zero data. Document the
 * caveat; future polish can switch to null + `Sparkline` gap rendering.
 *
 * Phase-1 single-tenant scoping — handle takes no `User` arg, matches
 * the rest of `GetOverviewDashboardQuery`'s slices.
 */
class GetMonitoringUptimeKpiQuery
{
    /** Match the rest of the dashboard's sparkline cadence. */
    private const SPARKLINE_DAYS = 12;

    /**
     * @return array{
     *     overall: float|null,
     *     change: float,
     *     sparkline: array<int, float>,
     *     status: 'success'|'warning'|'danger'|'muted',
     * }
     */
    public function execute(): array
    {
        $now = now();
        $currentStart = $now->copy()->subDay();
        $previousStart = $now->copy()->subDays(2);

        $overall = $this->uptimeFor($currentStart, $now);
        $previous = $this->uptimeFor($previousStart, $currentStart);

        $change = ($overall === null || $previous === null)
            ? 0.0
            : round($overall - $previous, 2);

        return [
            'overall' => $overall,
            'change' => $change,
            'sparkline' => $this->sparkline(),
            'status' => $this->statusFor($overall),
        ];
    }

    /**
     * Volume-weighted uptime % over the half-open window `[from, to)`.
     * Null when the window is empty.
     */
    private function uptimeFor(Carbon $from, Carbon $to): ?float
    {
        $total = WebsiteCheck::query()
            ->where('checked_at', '>=', $from)
            ->where('checked_at', '<', $to)
            ->count();

        if ($total === 0) {
            return null;
        }

        $successful = WebsiteCheck::query()
            ->where('checked_at', '>=', $from)
            ->where('checked_at', '<', $to)
            ->whereIn('status', ['up', 'slow'])
            ->count();

        return round(($successful / $total) * 100, 2);
    }

    /**
     * 12-entry daily uptime sparkline. Days with no checks default to
     * 100.0 so a fresh account doesn't render as a 0-percent flatline.
     *
     * One DB query (grouped by date) — beats 12 round-trips for a
     * cheap read.
     *
     * @return array<int, float>
     */
    private function sparkline(): array
    {
        $start = now()->startOfDay()->subDays(self::SPARKLINE_DAYS - 1);

        /** @var Collection<int, object{date: string, total: int, successful: int}> $rows */
        $rows = WebsiteCheck::query()
            ->where('checked_at', '>=', $start)
            ->selectRaw(
                'DATE(checked_at) as date, COUNT(*) as total,'
                .'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as successful',
                ['up', 'slow'],
            )
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $series = [];
        for ($i = 0; $i < self::SPARKLINE_DAYS; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $row = $rows->get($day);

            if ($row === null || (int) $row->total === 0) {
                // Empty day → "no failures observed" interpretation.
                $series[] = 100.0;

                continue;
            }

            $series[] = round(((int) $row->successful / (int) $row->total) * 100, 2);
        }

        return $series;
    }

    /**
     * Map overall rate → status tone.
     *
     * @return 'success'|'warning'|'danger'|'muted'
     */
    private function statusFor(?float $overall): string
    {
        if ($overall === null) {
            return 'muted';
        }
        if ($overall >= 99.0) {
            return 'success';
        }
        if ($overall >= 95.0) {
            return 'warning';
        }

        return 'danger';
    }
}
