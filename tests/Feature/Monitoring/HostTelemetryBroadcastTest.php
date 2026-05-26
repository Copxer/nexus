<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Docker\Actions\IssueAgentTokenAction;
use App\Events\HostTelemetryRecorded;
use App\Models\Host;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Spec 028 — every successful agent telemetry post should fire
 * `HostTelemetryRecorded` on the host owner's `users.{id}.hosts`
 * private channel. The Host Show page listens for this pulse and
 * partial-reloads the host + telemetry props.
 */
class HostTelemetryBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_telemetry_post_dispatches_host_telemetry_recorded_with_resolved_owner(): void
    {
        Event::fake([HostTelemetryRecorded::class]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);
        $plaintext = app(IssueAgentTokenAction::class)->execute($host)->plaintext;

        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            ['Authorization' => 'Bearer '.$plaintext],
        )->assertNoContent();

        Event::assertDispatched(
            HostTelemetryRecorded::class,
            fn (HostTelemetryRecorded $event): bool => $event->hostId === $host->id
                && $event->ownerUserId === $user->id,
        );
        Event::assertDispatchedTimes(HostTelemetryRecorded::class, 1);
    }

    public function test_a_rejected_telemetry_post_does_not_dispatch_the_event(): void
    {
        Event::fake([HostTelemetryRecorded::class]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);
        $plaintext = app(IssueAgentTokenAction::class)->execute($host)->plaintext;

        $payload = $this->payload();
        $payload['host']['metrics']['cpu_percent'] = 250; // out of [0,100]

        $this->postJson(
            route('agent.telemetry'),
            $payload,
            ['Authorization' => 'Bearer '.$plaintext],
        )->assertStatus(422);

        Event::assertNotDispatched(HostTelemetryRecorded::class);
    }

    private function payload(): array
    {
        return [
            'recorded_at' => now()->toIso8601String(),
            'host' => [
                'metrics' => [
                    'cpu_percent' => 17.5,
                    'memory_used_mb' => 2048,
                    'memory_total_mb' => 8192,
                ],
            ],
        ];
    }
}
