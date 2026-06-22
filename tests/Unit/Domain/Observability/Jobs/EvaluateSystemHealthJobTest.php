<?php

namespace Tests\Unit\Domain\Observability\Jobs;

use App\Domain\Alerts\Actions\ResolveAlertAction;
use App\Domain\Alerts\Actions\TriggerAlertAction;
use App\Domain\Observability\Jobs\EvaluateSystemHealthJob;
use App\Domain\Observability\Queries\GetSystemHealthQuery;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\GithubRateLimitSnapshot;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EvaluateSystemHealthJobTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(): void
    {
        $job = app(EvaluateSystemHealthJob::class);
        $job->handle(
            app(GetSystemHealthQuery::class),
            app(TriggerAlertAction::class),
            app(ResolveAlertAction::class),
        );
    }

    public function test_does_nothing_when_all_signals_are_green(): void
    {
        $this->runJob();

        $this->assertSame(0, Alert::query()->count());
    }

    public function test_triggers_queue_backlog_alert_at_warning_threshold(): void
    {
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

        $this->runJob();

        $alert = Alert::query()->where('type', 'queue.backlog_high')->firstOrFail();
        $this->assertSame(AlertSource::System, $alert->source);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertNull($alert->project_id);
    }

    public function test_triggers_queue_backlog_critical_at_500(): void
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

        $this->runJob();

        $alert = Alert::query()->where('type', 'queue.backlog_high')->firstOrFail();
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
    }

    public function test_idempotent_on_repeated_runs(): void
    {
        for ($i = 0; $i < 150; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        $this->runJob();
        $this->runJob();
        $this->runJob();

        $this->assertSame(
            1,
            Alert::query()->where('type', 'queue.backlog_high')->count(),
            'TriggerAlertAction idempotency: one open alert, not three.',
        );
    }

    public function test_resolves_queue_alert_when_backlog_clears(): void
    {
        // Trigger first.
        for ($i = 0; $i < 150; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }
        $this->runJob();

        $this->assertSame(
            AlertStatus::Open,
            Alert::query()->where('type', 'queue.backlog_high')->firstOrFail()->status,
        );

        // Drain the queue + re-run — alert should auto-resolve.
        DB::table('jobs')->truncate();
        $this->runJob();

        $this->assertSame(
            AlertStatus::Resolved,
            Alert::query()->where('type', 'queue.backlog_high')->firstOrFail()->status,
        );
    }

    public function test_webhook_failure_rate_below_sample_floor_does_not_fire(): void
    {
        WebhookDelivery::factory()->create([
            'status' => 'failed',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->runJob();

        $this->assertSame(0, Alert::query()->where('type', 'webhook.failure_rate_high')->count());
    }

    public function test_webhook_warning_at_20_percent_failure_rate(): void
    {
        WebhookDelivery::factory()->count(4)->create([
            'status' => 'processed',
            'received_at' => now()->subMinutes(2),
        ]);
        WebhookDelivery::factory()->create([
            'status' => 'failed',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->runJob();

        $alert = Alert::query()->where('type', 'webhook.failure_rate_high')->firstOrFail();
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
    }

    public function test_github_rate_low_triggers_warning_then_resolves_when_replenished(): void
    {
        $user = User::factory()->create();

        // Low snapshot — triggers.
        GithubRateLimitSnapshot::create([
            'user_id' => $user->id,
            'remaining' => 50,
            'limit' => 5000,
            'reset_at' => now()->addHour(),
            'recorded_at' => now(),
        ]);
        $this->runJob();

        $alert = Alert::query()->where('type', 'github.rate_limit_low')->firstOrFail();
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);

        // Fresh higher snapshot — resolves.
        GithubRateLimitSnapshot::create([
            'user_id' => $user->id,
            'remaining' => 4000,
            'limit' => 5000,
            'reset_at' => now()->addHour(),
            'recorded_at' => now()->addSeconds(10),
        ]);
        $this->runJob();

        $this->assertSame(
            AlertStatus::Resolved,
            Alert::query()->where('type', 'github.rate_limit_low')->firstOrFail()->status,
        );
    }

    public function test_agent_auth_failures_trigger_warning(): void
    {
        ActivityEvent::factory()->count(12)->create([
            'event_type' => 'agent.auth.failure',
            'source' => 'agent',
            'occurred_at' => now()->subMinutes(2),
        ]);

        $this->runJob();

        $alert = Alert::query()->where('type', 'agent.auth_failures_high')->firstOrFail();
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
    }
}
