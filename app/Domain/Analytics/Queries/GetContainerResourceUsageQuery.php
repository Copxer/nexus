<?php

namespace App\Domain\Analytics\Queries;

use App\Models\ContainerMetricSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Container CPU + memory utilization trends for `/analytics` (spec
 * 034). Joined through `container_metric_snapshots.container_id` →
 * `containers.host_id` → `hosts.project_id` →
 * `projects.owner_user_id`. The snapshot table doesn't carry a
 * denormalized `project_id` — the join chain is the canonical scope.
 *
 * Daily aggregates are `AVG(cpu_percent)` and `AVG(memory_percent)`
 * across every snapshot the user's hosts reported that day. Empty
 * days return null (chart code distinguishes "no data" from "zero
 * usage"). Users with no hosts at all return the fully-muted shape
 * — the page renders the "No data in this range" placeholder.
 */
class GetContainerResourceUsageQuery
{
    /**
     * @return array{
     *     cpu: array{percent: float|null, sparkline: array<int, float|null>, status: 'success'|'warning'|'danger'|'muted'},
     *     memory: array{percent: float|null, sparkline: array<int, float|null>, status: 'success'|'warning'|'danger'|'muted'},
     * }
     */
    public function execute(User $user, Carbon $from): array
    {
        $days = $this->dayCount($from);
        $now = now();

        $base = ContainerMetricSnapshot::query()
            ->join('containers', 'container_metric_snapshots.container_id', '=', 'containers.id')
            ->join('hosts', 'containers.host_id', '=', 'hosts.id')
            ->join('projects', 'hosts.project_id', '=', 'projects.id')
            ->where('projects.owner_user_id', $user->id)
            ->where('container_metric_snapshots.recorded_at', '>=', $from)
            ->where('container_metric_snapshots.recorded_at', '<', $now);

        $cpuAvg = (clone $base)
            ->whereNotNull('container_metric_snapshots.cpu_percent')
            ->avg('container_metric_snapshots.cpu_percent');

        $memAvg = (clone $base)
            ->whereNotNull('container_metric_snapshots.memory_percent')
            ->avg('container_metric_snapshots.memory_percent');

        /** @var Collection<int, object{date: string, cpu: float|null, mem: float|null}> $rows */
        $rows = (clone $base)
            ->selectRaw(
                'DATE(container_metric_snapshots.recorded_at) as date,'
                .' AVG(container_metric_snapshots.cpu_percent) as cpu,'
                .' AVG(container_metric_snapshots.memory_percent) as mem',
            )
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $cpuSpark = [];
        $memSpark = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $row = $rows->get($day);
            $cpuSpark[] = ($row === null || $row->cpu === null)
                ? null
                : round((float) $row->cpu, 2);
            $memSpark[] = ($row === null || $row->mem === null)
                ? null
                : round((float) $row->mem, 2);
        }

        return [
            'cpu' => [
                'percent' => $cpuAvg === null ? null : round((float) $cpuAvg, 2),
                'sparkline' => $cpuSpark,
                'status' => $this->statusFor($cpuAvg === null ? null : (float) $cpuAvg),
            ],
            'memory' => [
                'percent' => $memAvg === null ? null : round((float) $memAvg, 2),
                'sparkline' => $memSpark,
                'status' => $this->statusFor($memAvg === null ? null : (float) $memAvg),
            ],
        ];
    }

    private function dayCount(Carbon $from): int
    {
        return (int) $from->diffInDays(now()->startOfDay()) + 1;
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function statusFor(?float $percent): string
    {
        return match (true) {
            $percent === null => 'muted',
            $percent < 60.0 => 'success',
            $percent < 85.0 => 'warning',
            default => 'danger',
        };
    }
}
