<?php

namespace App\Domain\Docker\Queries;

use App\Models\Container;
use App\Models\Host;
use App\Models\HostMetricSnapshot;

/**
 * Read model for the Host detail page (spec 028). Bundles three things
 * so `HostController@show` stays thin:
 *
 *   - `current`    — the latest host metric snapshot (or null).
 *   - `series`     — the last 50 snapshots, oldest→newest, as
 *                    {cpu_percent, memory_percent, recorded_at} for the
 *                    Show-page sparkline.
 *   - `containers` — the host's containers with current per-container
 *                    stats, ordered by name.
 */
class GetHostTelemetryQuery
{
    /** Most recent snapshots fed to the Show-page sparkline. */
    private const SERIES_LIMIT = 50;

    /**
     * @return array{
     *     current: array<string, mixed>|null,
     *     series: list<array<string, mixed>>,
     *     containers: list<array<string, mixed>>,
     * }
     */
    public function execute(Host $host): array
    {
        // Pull newest-first (so the LIMIT keeps the *recent* rows), then
        // reverse for the chart series so it reads left→right oldest→newest.
        $snapshots = HostMetricSnapshot::query()
            ->where('host_id', $host->id)
            ->orderByDesc('recorded_at')
            ->limit(self::SERIES_LIMIT)
            ->get();

        $current = $snapshots->first();

        $series = $snapshots
            ->reverse()
            ->values()
            ->map(fn (HostMetricSnapshot $snapshot): array => [
                'cpu_percent' => $snapshot->cpu_percent,
                'memory_percent' => $snapshot->memoryPercent(),
                'recorded_at' => $snapshot->recorded_at?->toIso8601String(),
            ])
            ->all();

        $containers = Container::query()
            ->where('host_id', $host->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Container $container): array => [
                'id' => $container->id,
                'container_id' => $container->container_id,
                'name' => $container->name,
                'image' => $container->image,
                'image_tag' => $container->image_tag,
                'status' => $container->status,
                'state' => $container->state,
                'health_status' => $container->health_status,
                'cpu_percent' => $container->cpu_percent,
                'memory_usage_mb' => $container->memory_usage_mb,
                'memory_limit_mb' => $container->memory_limit_mb,
                'memory_percent' => $container->memory_percent,
                'last_seen_at' => $container->last_seen_at?->diffForHumans(),
            ])
            ->all();

        return [
            'current' => $current === null ? null : [
                'cpu_percent' => $current->cpu_percent,
                'memory_used_mb' => $current->memory_used_mb,
                'memory_total_mb' => $current->memory_total_mb,
                'memory_percent' => $current->memoryPercent(),
                'disk_used_gb' => $current->disk_used_gb,
                'disk_total_gb' => $current->disk_total_gb,
                'load_average' => $current->load_average,
                'network_rx_bytes' => $current->network_rx_bytes,
                'network_tx_bytes' => $current->network_tx_bytes,
                'recorded_at' => $current->recorded_at?->toIso8601String(),
            ],
            'series' => $series,
            'containers' => $containers,
        ];
    }
}
