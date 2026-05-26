<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Docker\Actions\DetectOfflineHostsAction;
use App\Domain\Docker\Jobs\DetectOfflineHostsJob;
use App\Enums\HostStatus;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 029 — end-to-end wiring check for the scheduled offline detector.
 * The action's logic is covered by `DetectOfflineHostsActionTest`; this
 * test just verifies the job's `handle()` actually resolves and invokes
 * the action against the live container.
 */
class DetectOfflineHostsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_flips_stale_online_hosts_to_offline(): void
    {
        config(['hosts.heartbeat_timeout_seconds' => 120]);

        $host = Host::factory()->online()->create([
            'last_seen_at' => now()->subMinutes(5),
        ]);

        app(DetectOfflineHostsJob::class)->handle(app()->make(DetectOfflineHostsAction::class));

        $this->assertSame(HostStatus::Offline, $host->fresh()->status);
    }
}
