<?php

namespace Tests\Unit\Domain\Alerts;

use App\Domain\Alerts\Actions\ResolveAlertAction;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Events\AlertResolved;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ResolveAlertActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_an_open_alert_and_emits_an_activity_event(): void
    {
        $project = Project::factory()->create();
        $alert = Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 11,
            'type' => 'website.down',
        ]);

        $resolved = app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 11,
        ]);

        $this->assertSame(1, $resolved);
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertNotNull($alert->fresh()->resolved_at);

        $event = ActivityEvent::query()->firstOrFail();
        $this->assertSame('alert.resolved', $event->event_type);
        $this->assertSame('alerts', $event->source);
        $this->assertSame($alert->id, $event->metadata['alert_id']);
    }

    public function test_resolves_acknowledged_alerts_too(): void
    {
        $project = Project::factory()->create();
        $alert = Alert::factory()->acknowledged()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Docker->value,
            'source_id' => 3,
            'type' => 'host.offline',
        ]);

        $resolved = app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Docker,
            'source_id' => 3,
        ]);

        $this->assertSame(1, $resolved);
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_is_a_noop_when_no_open_alert_matches(): void
    {
        // Already-resolved row should NOT be touched.
        Alert::factory()->resolved()->create([
            'source' => AlertSource::Website->value,
            'source_id' => 1,
            'type' => 'website.down',
        ]);

        $resolved = app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 1,
        ]);

        $this->assertSame(0, $resolved);
        $this->assertSame(0, ActivityEvent::query()->count(), 'no spurious activity event');
    }

    public function test_does_not_touch_other_sources(): void
    {
        Alert::factory()->create([
            'source' => AlertSource::Website->value,
            'source_id' => 1,
            'type' => 'website.down',
        ]);
        Alert::factory()->create([
            'source' => AlertSource::Docker->value,
            'source_id' => 1, // same id, different source
            'type' => 'host.offline',
        ]);

        app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 1,
        ]);

        $this->assertSame(
            1,
            Alert::query()->where('status', AlertStatus::Open->value)->count(),
            'the docker-source alert was untouched',
        );
    }

    public function test_muted_alerts_are_left_alone_on_resolve(): void
    {
        // The user explicitly muted this alert; auto-resolve must
        // not undo that decision. (Auto-resolve closes `open` and
        // `acknowledged` — `muted` and `resolved` stay put.)
        $project = Project::factory()->create();
        $muted = Alert::factory()->muted()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 50,
            'type' => 'website.down',
        ]);

        $resolved = app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 50,
        ]);

        $this->assertSame(0, $resolved);
        $this->assertSame(AlertStatus::Muted, $muted->fresh()->status);
        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_type_filter_narrows_the_close_set(): void
    {
        $project = Project::factory()->create();
        Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 7,
            'type' => 'website.down',
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 7,
            'type' => 'website.slow',
        ]);

        $resolved = app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 7,
            'type' => 'website.down',
        ]);

        $this->assertSame(1, $resolved);
        $this->assertSame(
            1,
            Alert::query()
                ->where('type', 'website.slow')
                ->where('status', AlertStatus::Open->value)
                ->count(),
            'website.slow stayed open',
        );
    }

    public function test_closing_a_row_dispatches_alert_resolved_with_resolved_owner(): void
    {
        Event::fake([AlertResolved::class]);
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $alert = Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 9,
            'type' => 'website.down',
        ]);

        app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 9,
        ]);

        Event::assertDispatched(
            AlertResolved::class,
            fn (AlertResolved $event): bool => $event->alertId === $alert->id
                && $event->ownerUserId === $owner->id,
        );
        Event::assertDispatchedTimes(AlertResolved::class, 1);
    }

    public function test_noop_does_not_dispatch_alert_resolved(): void
    {
        Event::fake([AlertResolved::class]);
        Alert::factory()->resolved()->create([
            'source' => AlertSource::Website->value,
            'source_id' => 1,
            'type' => 'website.down',
        ]);

        app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 1,
        ]);

        Event::assertNotDispatched(AlertResolved::class);
    }

    public function test_race_guard_skips_an_alert_concurrently_resolved_during_iteration(): void
    {
        // Simulates a racing caller (eg. a website-monitor cron's
        // recovery path) flipping the alert to resolved between the
        // action's initial SELECT and the per-row `lockForUpdate`
        // re-fetch. The race guard's whereIn('status', [open, ack'd])
        // filter on the lock-acquiring SELECT must catch this — the
        // close + emit + broadcast should all be skipped, not
        // double-fired.
        $alert = Alert::factory()->create([
            'source' => AlertSource::Website->value,
            'source_id' => 100,
            'type' => 'website.down',
            'status' => AlertStatus::Open->value,
        ]);

        // Listener fires on every Alert retrieval. The first time we
        // see the still-open candidate row (the action's initial
        // SELECT), we flip its DB-side status to resolved directly
        // (bypassing the model so this listener doesn't re-trigger).
        // The action's per-row `lockForUpdate` re-fetch then sees the
        // new status and the whereIn filter skips it.
        $flipped = false;
        $listener = function (Alert $model) use ($alert, &$flipped): void {
            if ($flipped) {
                return;
            }
            if ($model->id !== $alert->id) {
                return;
            }
            if ($model->status !== AlertStatus::Open) {
                return;
            }
            DB::table('alerts')
                ->where('id', $alert->id)
                ->update(['status' => AlertStatus::Resolved->value]);
            $flipped = true;
        };
        Alert::retrieved($listener);

        $closed = app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 100,
        ]);

        $this->assertSame(0, $closed, 'race guard reported zero closes');
        $this->assertSame(
            0,
            ActivityEvent::query()
                ->where('event_type', 'alert.resolved')
                ->count(),
            'no spurious alert.resolved activity event',
        );
        // The row landed resolved via the racing caller's direct
        // update (simulating the other resolve path winning the lock).
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }
}
