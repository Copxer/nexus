<?php

namespace App\Domain\Docker\Actions;

use App\Enums\HostStatus;
use App\Models\Host;
use App\Models\HostMetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists a single agent telemetry tick (spec 027).
 *
 *   1. Updates host metadata (cpu_count, memory_total_mb, disk_total_gb,
 *      os, docker_version) when the agent reports them. Only present
 *      keys overwrite — a missing field doesn't blank out a previously
 *      reported value.
 *   2. Marks the host online (unless archived — archived hosts stay
 *      archived even if a stale agent ingests; the middleware should
 *      already block this, but defence in depth costs nothing).
 *   3. Inserts one `host_metric_snapshots` row.
 *   4. Hands the container array to `SyncContainerSnapshotsAction`.
 *
 * The whole thing runs in a single transaction so a partial failure
 * (e.g. a container row that violates the `containers.host_id` FK if
 * the host got deleted mid-flight) doesn't leave a host marked
 * `online` with no snapshots.
 */
class IngestHostTelemetryAction
{
    public function __construct(
        private readonly SyncContainerSnapshotsAction $syncContainers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Validated payload from
     *                                         IngestTelemetryRequest.
     */
    public function execute(Host $host, array $payload): void
    {
        $recordedAt = CarbonImmutable::parse($payload['recorded_at']);
        $hostPayload = $payload['host'] ?? [];
        $facts = $hostPayload['facts'] ?? [];
        $metrics = $hostPayload['metrics'] ?? [];
        $containers = $payload['containers'] ?? [];

        DB::transaction(function () use ($host, $recordedAt, $facts, $metrics, $containers): void {
            $this->updateHost($host, $recordedAt, $facts);
            $this->insertHostSnapshot($host, $recordedAt, $metrics);
            $this->syncContainers->execute($host, $recordedAt, $containers);
        });
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function updateHost(Host $host, CarbonImmutable $recordedAt, array $facts): void
    {
        // `forceFill` because `last_seen_at` and `status` aren't in
        // `$fillable` — they're owned by the ingestion path, not user
        // edits. The user-visible CRUD form (spec 026) doesn't touch
        // them.
        $payload = [];

        foreach (['cpu_count', 'memory_total_mb', 'disk_total_gb', 'os', 'docker_version'] as $key) {
            if (array_key_exists($key, $facts) && $facts[$key] !== null) {
                $payload[$key] = $facts[$key];
            }
        }

        if ($host->status !== HostStatus::Archived) {
            $payload['status'] = HostStatus::Online->value;
        }

        $payload['last_seen_at'] = $recordedAt;

        $host->forceFill($payload)->save();
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function insertHostSnapshot(Host $host, CarbonImmutable $recordedAt, array $metrics): void
    {
        HostMetricSnapshot::query()->create([
            'host_id' => $host->id,
            'cpu_percent' => $metrics['cpu_percent'] ?? null,
            'memory_used_mb' => $metrics['memory_used_mb'] ?? null,
            'memory_total_mb' => $metrics['memory_total_mb'] ?? null,
            'disk_used_gb' => $metrics['disk_used_gb'] ?? null,
            'disk_total_gb' => $metrics['disk_total_gb'] ?? null,
            'load_average' => $metrics['load_average'] ?? null,
            'network_rx_bytes' => $metrics['network_rx_bytes'] ?? null,
            'network_tx_bytes' => $metrics['network_tx_bytes'] ?? null,
            'recorded_at' => $recordedAt,
        ]);
    }
}
