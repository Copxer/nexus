<?php

namespace Tests\Unit\Domain\Alerts;

use App\Domain\Alerts\Actions\TriggerAlertAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Events\AlertTriggered;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TriggerAlertActionTest extends TestCase
{
    use RefreshDatabase;

    private function attrs(array $overrides = []): array
    {
        $project = Project::factory()->create();

        return array_merge([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 42,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Site is down',
            'description' => 'GET / returned 500',
            'metadata' => ['url' => 'https://example.com'],
        ], $overrides);
    }

    public function test_first_trigger_inserts_an_open_alert_and_one_activity_event(): void
    {
        $alert = app(TriggerAlertAction::class)->execute($this->attrs());

        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame(AlertSource::Website, $alert->source);
        $this->assertSame('website.down', $alert->type);
        $this->assertNotNull($alert->triggered_at);
        $this->assertNotNull($alert->last_seen_at);

        $this->assertSame(1, Alert::query()->count());

        $event = ActivityEvent::query()->firstOrFail();
        $this->assertSame('alert.triggered', $event->event_type);
        $this->assertSame('alerts', $event->source);
        $this->assertSame($alert->id, $event->metadata['alert_id']);
        $this->assertSame('website', $event->metadata['alert_source']);
        $this->assertSame(42, $event->metadata['alert_source_id']);
    }

    public function test_second_identical_trigger_is_idempotent(): void
    {
        $action = app(TriggerAlertAction::class);
        $first = $action->execute($this->attrs());

        // Travel to assert last_seen_at moves on the second call.
        $this->travel(2)->minutes();
        $second = $action->execute($this->attrs(['project_id' => $first->project_id]));

        $this->assertSame($first->id, $second->id, 'returns the same row');
        $this->assertSame(1, Alert::query()->count(), 'no duplicate Alert');
        $this->assertSame(1, ActivityEvent::query()->count(), 'no second activity event');

        $second->refresh();
        $this->assertTrue($second->last_seen_at->greaterThan($first->triggered_at));
    }

    public function test_different_type_with_same_source_id_creates_a_separate_alert(): void
    {
        $action = app(TriggerAlertAction::class);
        $project = Project::factory()->create();

        $action->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 7,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Down',
        ]);
        $action->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 7,
            'type' => 'website.slow', // different type
            'severity' => AlertSeverity::Warning,
            'title' => 'Slow',
        ]);

        $this->assertSame(2, Alert::query()->count());
    }

    public function test_resolved_alert_does_not_block_a_fresh_trigger(): void
    {
        $project = Project::factory()->create();
        Alert::factory()->resolved()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 99,
            'type' => 'website.down',
        ]);

        $alert = app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 99,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Fresh outage',
        ]);

        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame(2, Alert::query()->count(), 'old resolved row stays; new open row inserted');
    }

    public function test_acknowledged_alert_blocks_a_duplicate_trigger(): void
    {
        $project = Project::factory()->create();
        $existing = Alert::factory()->acknowledged()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 5,
            'type' => 'website.down',
        ]);

        $alert = app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 5,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Still down',
        ]);

        $this->assertSame($existing->id, $alert->id);
        $this->assertSame(AlertStatus::Acknowledged, $alert->fresh()->status);
        $this->assertSame(1, Alert::query()->count());
    }

    public function test_fresh_trigger_dispatches_alert_triggered_with_resolved_owner(): void
    {
        Event::fake([AlertTriggered::class]);
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $alert = app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 1,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'fresh',
        ]);

        Event::assertDispatched(
            AlertTriggered::class,
            fn (AlertTriggered $event): bool => $event->alertId === $alert->id
                && $event->ownerUserId === $owner->id,
        );
        Event::assertDispatchedTimes(AlertTriggered::class, 1);
    }

    public function test_idempotent_re_trigger_does_not_re_broadcast(): void
    {
        $project = Project::factory()->create();
        $action = app(TriggerAlertAction::class);
        // First trigger lays the row down + emits (we don't care about
        // the first emit; tests above cover that).
        $action->execute($this->attrs(['project_id' => $project->id]));

        // Fake AFTER the first dispatch so we only observe the second.
        Event::fake([AlertTriggered::class]);

        $action->execute($this->attrs(['project_id' => $project->id]));

        Event::assertNotDispatched(AlertTriggered::class);
    }
}
