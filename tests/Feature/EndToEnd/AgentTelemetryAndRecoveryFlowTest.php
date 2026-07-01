<?php

namespace Tests\Feature\EndToEnd;

use App\Domain\Docker\Actions\DetectOfflineHostsAction;
use App\Domain\Docker\Actions\IssueAgentTokenAction;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\HostStatus;
use App\Models\Alert;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 040 — end-to-end: agent token issued → telemetry post →
 * host flips `pending` → `online`. Host then goes silent past the
 * heartbeat → `DetectOfflineHostsAction` flips it `online` →
 * `offline` + opens a `host.offline` alert. A fresh telemetry tick
 * arrives → host flips `offline` → `online` + the open alert
 * auto-resolves.
 *
 * Pins the contract that:
 *   - Agent telemetry POST writes a metric snapshot + transitions
 *     the host's `status` field.
 *   - The offline-detector action correctly identifies stale hosts
 *     and triggers `host.offline` alerts.
 *   - Recovery telemetry resolves the alert via the
 *     `IngestHostTelemetryAction` recovery path.
 */
class AgentTelemetryAndRecoveryFlowTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'recorded_at' => now()->toIso8601String(),
            'host' => [
                'metrics' => [
                    'cpu_percent' => 12.5,
                    'memory_used_mb' => 1024,
                ],
            ],
        ];
    }

    public function test_agent_telemetry_to_offline_alert_to_recovery_lifecycle(): void
    {
        // 1. Issue a token + first telemetry → host flips pending → online.
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute($host);

        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            ['Authorization' => 'Bearer '.$result->plaintext],
        )->assertNoContent();

        $online = $host->fresh();
        $this->assertSame(HostStatus::Online, $online->status);
        $this->assertNotNull($online->last_seen_at);

        // 2. Host goes silent past the heartbeat threshold (default
        //    120s). Force `last_seen_at` past the cutoff and run the
        //    detector action.
        $host->forceFill([
            'last_seen_at' => now()->subMinutes(5),
        ])->save();

        app(DetectOfflineHostsAction::class)->execute();

        $offline = $host->fresh();
        $this->assertSame(HostStatus::Offline, $offline->status);

        $alert = Alert::query()
            ->where('source', AlertSource::Docker->value)
            ->where('type', 'host.offline')
            ->where('source_id', $host->id)
            ->firstOrFail();
        $this->assertSame(AlertStatus::Open, $alert->status);

        // 3. Fresh telemetry arrives → host recovers + alert resolves.
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            ['Authorization' => 'Bearer '.$result->plaintext],
        )->assertNoContent();

        $recovered = $host->fresh();
        $this->assertSame(HostStatus::Online, $recovered->status);

        // Alert auto-resolved via `IngestHostTelemetryAction`'s
        // recovery branch (spec 030).
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }
}
