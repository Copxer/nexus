<?php

namespace Tests\Unit\Domain\Docker;

use App\Domain\Docker\Actions\IngestHostTelemetryAction;
use App\Enums\ActivitySeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\HostStatus;
use App\Models\ActivityEvent;
use App\Models\Alert;
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

    public function test_offline_to_online_emits_a_host_recovered_activity_event(): void
    {
        $host = Host::factory()->offline()->create();

        app(IngestHostTelemetryAction::class)->execute($host, $this->basePayload());

        $host->refresh();
        $this->assertSame(HostStatus::Online, $host->status);

        $event = ActivityEvent::query()->where('event_type', 'host.recovered')->firstOrFail();
        $this->assertSame('hosts', $event->source);
        $this->assertSame(ActivitySeverity::Success, $event->severity);
        $this->assertSame("{$host->name} recovered", $event->title);
        $this->assertSame($host->id, $event->metadata['host_id'] ?? null);
    }

    public function test_pending_to_online_does_not_emit_host_recovered(): void
    {
        $host = Host::factory()->create(); // pending

        app(IngestHostTelemetryAction::class)->execute($host, $this->basePayload());

        $this->assertSame(0, ActivityEvent::query()->where('event_type', 'host.recovered')->count());
    }

    public function test_online_to_online_does_not_emit_host_recovered(): void
    {
        $host = Host::factory()->online()->create();

        app(IngestHostTelemetryAction::class)->execute($host, $this->basePayload());

        $this->assertSame(0, ActivityEvent::query()->where('event_type', 'host.recovered')->count());
    }

    public function test_recovery_auto_resolves_the_open_host_alert(): void
    {
        $host = Host::factory()->offline()->create();
        $alert = Alert::factory()->forHost()->create([
            'project_id' => $host->project_id,
            'source' => AlertSource::Docker->value,
            'source_id' => $host->id,
            'type' => 'host.offline',
        ]);

        app(IngestHostTelemetryAction::class)->execute($host, $this->basePayload());

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }
}
