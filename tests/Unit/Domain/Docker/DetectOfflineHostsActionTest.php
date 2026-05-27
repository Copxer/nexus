<?php

namespace Tests\Unit\Domain\Docker;

use App\Domain\Docker\Actions\DetectOfflineHostsAction;
use App\Enums\ActivitySeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\HostStatus;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 029 — the scheduled offline detector. Verifies the exact
 * status transitions + the one-event-per-flip guarantee.
 */
class DetectOfflineHostsActionTest extends TestCase
{
    use RefreshDatabase;

    /** Use the production default so tests reflect real behaviour. */
    private const TIMEOUT = 120;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hosts.heartbeat_timeout_seconds' => self::TIMEOUT]);
    }

    public function test_online_host_past_timeout_flips_to_offline_and_emits_event(): void
    {
        $host = Host::factory()->online()->create([
            'last_seen_at' => now()->subSeconds(self::TIMEOUT + 30),
        ]);

        $flipped = app(DetectOfflineHostsAction::class)->execute();

        $this->assertSame(1, $flipped);
        $host->refresh();
        $this->assertSame(HostStatus::Offline, $host->status);

        $event = ActivityEvent::query()->where('event_type', 'host.offline')->firstOrFail();
        $this->assertSame('hosts', $event->source);
        $this->assertSame(ActivitySeverity::Danger, $event->severity);
        $this->assertSame("{$host->name} went offline", $event->title);
        $this->assertSame($host->id, $event->metadata['host_id'] ?? null);
        $this->assertSame(self::TIMEOUT, $event->metadata['threshold_seconds'] ?? null);
    }

    public function test_online_host_within_timeout_is_untouched(): void
    {
        $host = Host::factory()->online()->create([
            'last_seen_at' => now()->subSeconds(self::TIMEOUT - 30),
        ]);

        $flipped = app(DetectOfflineHostsAction::class)->execute();

        $this->assertSame(0, $flipped);
        $this->assertSame(HostStatus::Online, $host->fresh()->status);
        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_pending_host_is_skipped_even_if_old(): void
    {
        // A host that was never online has no `offline` transition to
        // emit — pending stays pending until first telemetry.
        $host = Host::factory()->create([
            'last_seen_at' => now()->subYear(),
        ]);

        $flipped = app(DetectOfflineHostsAction::class)->execute();

        $this->assertSame(0, $flipped);
        $this->assertSame(HostStatus::Pending, $host->fresh()->status);
    }

    public function test_archived_host_is_skipped(): void
    {
        $host = Host::factory()->archived()->create([
            'last_seen_at' => now()->subYear(),
        ]);

        $flipped = app(DetectOfflineHostsAction::class)->execute();

        $this->assertSame(0, $flipped);
        $this->assertSame(HostStatus::Archived, $host->fresh()->status);
    }

    public function test_offline_host_stays_offline_and_does_not_re_emit(): void
    {
        $host = Host::factory()->offline()->create();

        $flipped = app(DetectOfflineHostsAction::class)->execute();

        $this->assertSame(0, $flipped);
        $this->assertSame(HostStatus::Offline, $host->fresh()->status);
        $this->assertSame(0, ActivityEvent::query()->where('event_type', 'host.offline')->count());
    }

    public function test_flipping_a_host_also_creates_a_critical_alert(): void
    {
        $host = Host::factory()->online()->create([
            'last_seen_at' => now()->subSeconds(self::TIMEOUT + 60),
        ]);

        app(DetectOfflineHostsAction::class)->execute();

        $alert = Alert::query()->firstOrFail();
        $this->assertSame(AlertSource::Docker, $alert->source);
        $this->assertSame($host->id, $alert->source_id);
        $this->assertSame('host.offline', $alert->type);
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame($host->project_id, $alert->project_id);
    }

    public function test_multiple_stale_hosts_each_produce_one_event(): void
    {
        Host::factory()->online()->count(3)->create([
            'last_seen_at' => now()->subSeconds(self::TIMEOUT + 30),
        ]);
        // Plus one fresh host that should NOT be touched.
        Host::factory()->online()->create([
            'last_seen_at' => now()->subSeconds(10),
        ]);

        $flipped = app(DetectOfflineHostsAction::class)->execute();

        $this->assertSame(3, $flipped);
        $this->assertSame(3, ActivityEvent::query()->where('event_type', 'host.offline')->count());
        $this->assertSame(3, Host::query()->where('status', HostStatus::Offline->value)->count());
        $this->assertSame(1, Host::query()->where('status', HostStatus::Online->value)->count());
    }
}
