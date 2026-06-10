<?php

namespace App\Domain\Analytics\Queries;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Alert frequency + mean time to recovery (MTTR) for `/analytics`
 * (spec 034). Both slices come from the `alerts` table scoped via
 * `alerts.project_id` → `projects.owner_user_id`.
 *
 * MTTR computes the average resolved_at − triggered_at delta over
 * alerts that were resolved within the window (resolved_at falls
 * inside the range, not triggered_at — we measure "how fast did we
 * close it", not "how fast does fresh stuff close"). Computed in PHP
 * to stay portable across SQLite + MySQL + Postgres without
 * dialect-specific seconds-diff functions.
 */
class GetAlertMetricsQuery
{
    /**
     * @return array{
     *     frequency: array{total: int, sparkline: array<int, int>},
     *     mttr: array{seconds: int|null, label: string|null, status: 'success'|'warning'|'danger'|'muted'},
     * }
     */
    public function execute(User $user, Carbon $from): array
    {
        $days = $this->dayCount($from);
        $now = now();

        $triggeredBase = Alert::query()
            ->join('projects', 'alerts.project_id', '=', 'projects.id')
            ->where('projects.owner_user_id', $user->id)
            ->where('alerts.triggered_at', '>=', $from)
            ->where('alerts.triggered_at', '<', $now);

        $total = (clone $triggeredBase)->count('alerts.id');

        /** @var Collection<int, object{date: string, total: int}> $rows */
        $rows = (clone $triggeredBase)
            ->selectRaw('DATE(alerts.triggered_at) as date, COUNT(alerts.id) as total')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $sparkline = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $row = $rows->get($day);
            $sparkline[] = $row === null ? 0 : (int) $row->total;
        }

        // MTTR — alerts resolved inside the window. Pull triggered_at
        // + resolved_at as raw selects so we don't hydrate the full
        // model when we only need the timestamps.
        /** @var Collection<int, object{triggered_at: string, resolved_at: string}> $resolved */
        $resolved = Alert::query()
            ->join('projects', 'alerts.project_id', '=', 'projects.id')
            ->where('projects.owner_user_id', $user->id)
            ->whereNotNull('alerts.resolved_at')
            ->where('alerts.resolved_at', '>=', $from)
            ->where('alerts.resolved_at', '<', $now)
            ->get(['alerts.triggered_at as triggered_at', 'alerts.resolved_at as resolved_at']);

        if ($resolved->isEmpty()) {
            return [
                'frequency' => ['total' => $total, 'sparkline' => $sparkline],
                'mttr' => ['seconds' => null, 'label' => null, 'status' => 'muted'],
            ];
        }

        // `getTimestamp()` deltas instead of `diffInSeconds()` — the
        // latter returns a signed float in Carbon 3+ and the sign
        // depends on argument order, which is easy to misread. Raw
        // unix-time subtraction is unambiguous.
        $totalSeconds = $resolved->sum(function (object $row): int {
            $triggeredAt = Carbon::parse($row->triggered_at)->getTimestamp();
            $resolvedAt = Carbon::parse($row->resolved_at)->getTimestamp();

            return max(0, $resolvedAt - $triggeredAt);
        });

        $avgSeconds = (int) round($totalSeconds / $resolved->count());

        return [
            'frequency' => ['total' => $total, 'sparkline' => $sparkline],
            'mttr' => [
                'seconds' => $avgSeconds,
                'label' => $this->humanizeSeconds($avgSeconds),
                'status' => $this->statusFor($avgSeconds),
            ],
        ];
    }

    private function dayCount(Carbon $from): int
    {
        return (int) $from->diffInDays(now()->startOfDay()) + 1;
    }

    private function humanizeSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $minutes = intdiv($seconds, 60);
        $remSeconds = $seconds % 60;
        if ($minutes < 60) {
            return $remSeconds > 0
                ? "{$minutes}m {$remSeconds}s"
                : "{$minutes}m";
        }
        $hours = intdiv($minutes, 60);
        $remMinutes = $minutes % 60;

        return $remMinutes > 0
            ? "{$hours}h {$remMinutes}m"
            : "{$hours}h";
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function statusFor(?int $seconds): string
    {
        if ($seconds === null) {
            return 'muted';
        }
        if ($seconds < 600) { // < 10 minutes
            return 'success';
        }
        if ($seconds < 1800) { // < 30 minutes
            return 'warning';
        }

        return 'danger';
    }
}
