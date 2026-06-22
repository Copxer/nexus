<?php

namespace Tests\Unit\Domain\Observability\Queries;

use App\Domain\Observability\Queries\GetSystemHealthQuery;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ActivityEvent;
use App\Models\GithubRateLimitSnapshot;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GetSystemHealthQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_green_when_nothing_is_wrong(): void
    {
        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame('success', $result['queue']['status']);
        $this->assertSame(0, $result['queue']['pending']);
        // Webhooks: no traffic -> muted (not success).
        $this->assertSame('muted', $result['webhooks']['status']);
        $this->assertSame('muted', $result['github_rate_limit']['status']);
        $this->assertNull($result['github_rate_limit']['remaining']);
        $this->assertSame('success', $result['agent_auth']['status']);
        $this->assertSame(0, $result['agent_auth']['failures_5m']);
    }

    public function test_queue_warning_when_pending_exceeds_100(): void
    {
        // Seed 101 dummy job rows.
        for ($i = 0; $i < 101; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame(101, $result['queue']['pending']);
        $this->assertSame('warning', $result['queue']['status']);
    }

    public function test_queue_danger_when_pending_exceeds_500(): void
    {
        for ($i = 0; $i < 501; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame('danger', $result['queue']['status']);
    }

    public function test_queue_failures_counted_only_within_5m_window(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'a',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Boom',
            'failed_at' => now()->subMinutes(2),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'b',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Boom',
            // Old failure — must NOT count toward the 5m window.
            'failed_at' => now()->subHours(2),
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame(1, $result['queue']['failed_5m']);
    }

    public function test_webhook_rate_below_min_sample_stays_quiet(): void
    {
        // Single failed delivery — sample size < 5, should be `success`
        // (no panic on a quiet account).
        WebhookDelivery::factory()->create([
            'status' => WebhookDeliveryStatus::Failed->value,
            'received_at' => now()->subMinutes(2),
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame(1, $result['webhooks']['deliveries_5m']);
        $this->assertSame(1, $result['webhooks']['failures_5m']);
        $this->assertNull($result['webhooks']['failure_rate_percent']);
        $this->assertSame('success', $result['webhooks']['status']);
    }

    public function test_webhook_warning_at_20_percent_failure_rate_with_sample(): void
    {
        // 5 deliveries, 1 failure = 20%.
        WebhookDelivery::factory()->count(4)->create([
            'status' => WebhookDeliveryStatus::Processed->value,
            'received_at' => now()->subMinutes(2),
        ]);
        WebhookDelivery::factory()->create([
            'status' => WebhookDeliveryStatus::Failed->value,
            'received_at' => now()->subMinutes(2),
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame(5, $result['webhooks']['deliveries_5m']);
        $this->assertSame(20.0, $result['webhooks']['failure_rate_percent']);
        $this->assertSame('warning', $result['webhooks']['status']);
    }

    public function test_webhook_danger_at_50_percent_failure_rate(): void
    {
        WebhookDelivery::factory()->count(3)->create([
            'status' => WebhookDeliveryStatus::Processed->value,
            'received_at' => now()->subMinutes(2),
        ]);
        WebhookDelivery::factory()->count(3)->create([
            'status' => WebhookDeliveryStatus::Failed->value,
            'received_at' => now()->subMinutes(2),
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame(50.0, $result['webhooks']['failure_rate_percent']);
        $this->assertSame('danger', $result['webhooks']['status']);
    }

    public function test_github_rate_limit_reads_latest_snapshot(): void
    {
        $user = User::factory()->create();
        GithubRateLimitSnapshot::create([
            'user_id' => $user->id,
            'remaining' => 1000,
            'limit' => 5000,
            'reset_at' => now()->addHour(),
            'recorded_at' => now()->subMinutes(20),
        ]);
        GithubRateLimitSnapshot::create([
            'user_id' => $user->id,
            'remaining' => 50,
            'limit' => 5000,
            'reset_at' => now()->addHour(),
            'recorded_at' => now()->subMinutes(1),
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame(50, $result['github_rate_limit']['remaining']);
        // 50 < 100 floor → warning.
        $this->assertSame('warning', $result['github_rate_limit']['status']);
    }

    public function test_github_rate_limit_danger_when_under_20(): void
    {
        $user = User::factory()->create();
        GithubRateLimitSnapshot::create([
            'user_id' => $user->id,
            'remaining' => 5,
            'limit' => 5000,
            'reset_at' => now()->addHour(),
            'recorded_at' => now(),
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame('danger', $result['github_rate_limit']['status']);
    }

    public function test_agent_auth_counts_recent_auth_failure_activity_events(): void
    {
        ActivityEvent::factory()->count(11)->create([
            'event_type' => 'agent.auth.failure',
            'source' => 'agent',
            'occurred_at' => now()->subMinutes(2),
        ]);
        ActivityEvent::factory()->create([
            'event_type' => 'agent.auth.failure',
            'source' => 'agent',
            'occurred_at' => now()->subHours(2), // outside 5m window
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame(11, $result['agent_auth']['failures_5m']);
        $this->assertSame('warning', $result['agent_auth']['status']);
    }

    public function test_agent_auth_danger_at_50_failures(): void
    {
        ActivityEvent::factory()->count(51)->create([
            'event_type' => 'agent.auth.failure',
            'source' => 'agent',
            'occurred_at' => now()->subMinutes(1),
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame('danger', $result['agent_auth']['status']);
    }

    public function test_agent_auth_other_events_do_not_count(): void
    {
        // A different event_type must not pollute the counter.
        ActivityEvent::factory()->count(50)->create([
            'event_type' => 'host.offline',
            'source' => 'hosts',
            'occurred_at' => now()->subMinutes(1),
        ]);

        $result = app(GetSystemHealthQuery::class)->execute();

        $this->assertSame(0, $result['agent_auth']['failures_5m']);
        $this->assertSame('success', $result['agent_auth']['status']);
    }
}
