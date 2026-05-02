<?php

namespace App\Domain\Docker\Actions;

use App\Models\Container;
use App\Models\ContainerMetricSnapshot;
use App\Models\Host;
use Carbon\CarbonImmutable;

/**
 * Upserts container rows + appends a metric snapshot for each entry in
 * the agent payload (spec 027).
 *
 * Containers that aren't in the payload are deliberately left alone —
 * their `last_seen_at` simply stops advancing. A future cleanup job
 * (or 029's offline detector) can use that staleness to mark them
 * gone, but at this layer we don't drop rows — a partial agent post
 * shouldn't be able to nuke live containers.
 *
 * `(host_id, container_id)` is unique by migration so the upsert
 * key is safe.
 */
class SyncContainerSnapshotsAction
{
    /**
     * @param  array<int, array<string, mixed>>  $containers
     */
    public function execute(Host $host, CarbonImmutable $recordedAt, array $containers): void
    {
        foreach ($containers as $payload) {
            $container = $this->upsertContainer($host, $recordedAt, $payload);
            $this->insertSnapshot($container, $recordedAt, $payload['metrics'] ?? []);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertContainer(Host $host, CarbonImmutable $recordedAt, array $payload): Container
    {
        $metrics = $payload['metrics'] ?? [];
        $memoryUsage = $metrics['memory_usage_mb'] ?? null;
        $memoryLimit = $metrics['memory_limit_mb'] ?? null;
        $memoryPercent = ($memoryUsage !== null && $memoryLimit !== null && $memoryLimit > 0)
            ? round(($memoryUsage / $memoryLimit) * 100, 2)
            : null;

        $attributes = [
            'host_id' => $host->id,
            'container_id' => $payload['container_id'],
        ];

        $values = [
            'name' => $payload['name'],
            'image' => $payload['image'],
            'image_tag' => $payload['image_tag'] ?? null,
            'status' => $payload['status'] ?? null,
            'state' => $payload['state'] ?? null,
            'health_status' => $payload['health_status'] ?? null,
            'ports' => $payload['ports'] ?? null,
            'labels' => $payload['labels'] ?? null,
            'cpu_percent' => $metrics['cpu_percent'] ?? null,
            'memory_usage_mb' => $memoryUsage,
            'memory_limit_mb' => $memoryLimit,
            'memory_percent' => $memoryPercent,
            'network_rx_bytes' => $metrics['network_rx_bytes'] ?? null,
            'network_tx_bytes' => $metrics['network_tx_bytes'] ?? null,
            'block_read_bytes' => $metrics['block_read_bytes'] ?? null,
            'block_write_bytes' => $metrics['block_write_bytes'] ?? null,
            'started_at' => $payload['started_at'] ?? null,
            'finished_at' => $payload['finished_at'] ?? null,
            'last_seen_at' => $recordedAt,
        ];

        return Container::query()->updateOrCreate($attributes, $values);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function insertSnapshot(Container $container, CarbonImmutable $recordedAt, array $metrics): void
    {
        $memoryUsage = $metrics['memory_usage_mb'] ?? null;
        $memoryLimit = $metrics['memory_limit_mb'] ?? null;
        $memoryPercent = ($memoryUsage !== null && $memoryLimit !== null && $memoryLimit > 0)
            ? round(($memoryUsage / $memoryLimit) * 100, 2)
            : null;

        ContainerMetricSnapshot::query()->create([
            'container_id' => $container->id,
            'cpu_percent' => $metrics['cpu_percent'] ?? null,
            'memory_usage_mb' => $memoryUsage,
            'memory_limit_mb' => $memoryLimit,
            'memory_percent' => $memoryPercent,
            'network_rx_bytes' => $metrics['network_rx_bytes'] ?? null,
            'network_tx_bytes' => $metrics['network_tx_bytes'] ?? null,
            'block_read_bytes' => $metrics['block_read_bytes'] ?? null,
            'block_write_bytes' => $metrics['block_write_bytes'] ?? null,
            'recorded_at' => $recordedAt,
        ]);
    }
}
