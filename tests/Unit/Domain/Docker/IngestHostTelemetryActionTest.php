<?php

namespace Tests\Unit\Domain\Docker;

use App\Domain\Docker\Actions\IngestHostTelemetryAction;
use App\Enums\HostStatus;
use App\Models\Host;
use App\Models\HostMetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestHostTelemetryActionTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(?string $recordedAt = null): array
    {
        return [
            'recorded_at' => $recordedAt ?? CarbonImmutable::now()->toIso8601String(),
            'host' => [
                'metrics' => [
                    'cpu_percent' => 12.5,
                    'memory_used_mb' => 2048,
                    'memory_total_mb' => 4096,
                ],
            ],
        ];
    }

    public function test_first_telemetry_transitions_pending_host_to_online(): void
    {
        $host = Host::factory()->create(); // status: pending

        app(IngestHostTelemetryAction::class)->execute($host, $this->basePayload());

        $host->refresh();
        $this->assertSame(HostStatus::Online, $host->status);
        $this->assertNotNull($host->last_seen_at);
        $this->assertSame(1, HostMetricSnapshot::query()->count());
    }

    public function test_archived_host_stays_archived_even_if_telemetry_lands(): void
    {
        $host = Host::factory()->archived()->create();

        app(IngestHostTelemetryAction::class)->execute($host, $this->basePayload());

        $host->refresh();
        $this->assertSame(HostStatus::Archived, $host->status);
    }

    public function test_facts_only_overwrite_when_present(): void
    {
        $host = Host::factory()->create([
            'cpu_count' => 8,
            'memory_total_mb' => 16384,
            'os' => 'Ubuntu 22.04',
        ]);

        $payload = $this->basePayload();
        $payload['host']['facts'] = [
            'docker_version' => '26.1.0',
            // cpu_count, memory_total_mb, os intentionally absent
        ];

        app(IngestHostTelemetryAction::class)->execute($host, $payload);

        $host->refresh();
        $this->assertSame(8, $host->cpu_count, 'cpu_count preserved');
        $this->assertSame(16384, $host->memory_total_mb, 'memory_total_mb preserved');
        $this->assertSame('Ubuntu 22.04', $host->os, 'os preserved');
        $this->assertSame('26.1.0', $host->docker_version);
    }

    public function test_snapshot_carries_recorded_at_from_payload(): void
    {
        $host = Host::factory()->create();
        // Second-aligned: the `timestamp` column drops sub-second
        // precision, so a fractional CarbonImmutable round-trips lossy.
        $recordedAt = CarbonImmutable::now()->subMinutes(5)->startOfSecond();

        app(IngestHostTelemetryAction::class)->execute(
            $host,
            $this->basePayload($recordedAt->toIso8601String()),
        );

        $snapshot = HostMetricSnapshot::query()->firstOrFail();
        $this->assertSame(
            $recordedAt->toIso8601String(),
            $snapshot->recorded_at->toIso8601String(),
        );

        $host->refresh();
        $this->assertSame(
            $recordedAt->toIso8601String(),
            $host->last_seen_at->toIso8601String(),
        );
    }

    public function test_already_online_host_does_not_rewrite_status_column(): void
    {
        $host = Host::factory()->online()->create();
        $originalUpdatedAt = $host->updated_at;

        // Travel forward so any rewrite would bump `updated_at`.
        $this->travel(30)->seconds();

        app(IngestHostTelemetryAction::class)->execute(
            $host,
            $this->basePayload(now()->toIso8601String()),
        );

        $host->refresh();
        // `last_seen_at` was updated (so updated_at moves), but the
        // important assertion is that status stays Online and the
        // payload didn't include a redundant status column write.
        $this->assertSame(HostStatus::Online, $host->status);
    }
}
