<?php

namespace Tests\Feature\Notifications;

use App\Domain\Alerts\Actions\ResolveAlertAction;
use App\Domain\Notifications\Jobs\DispatchAlertNotificationJob;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertNotificationChannel;
use App\Models\AlertNotificationPreference;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Spec 042 — resolution notifications are opt-in per preference row
 * (`notify_on_resolve`). A resolve-flow must only dispatch to
 * preferences whose row has the flag set to true.
 */
class ResolveAlertActionNotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_dispatches_only_to_preferences_with_notify_on_resolve_true(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        // Two channels + two preferences: one opts in for resolve
        // notifications, one doesn't. Only the opt-in should fire.
        $slack = AlertNotificationChannel::factory()->slack()->for($user)->create();
        $webhook = AlertNotificationChannel::factory()->webhook()->for($user)->create();

        AlertNotificationPreference::factory()
            ->for($user)
            ->for($slack, 'channel')
            ->create([
                'min_severity' => AlertSeverity::Warning->value,
                'notify_on_resolve' => true,
            ]);

        AlertNotificationPreference::factory()
            ->for($user)
            ->for($webhook, 'channel')
            ->create([
                'min_severity' => AlertSeverity::Warning->value,
                'notify_on_resolve' => false,
            ]);

        Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 7,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);

        app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 7,
            'type' => 'website.down',
        ]);

        Bus::assertDispatchedTimes(DispatchAlertNotificationJob::class, 1);
        Bus::assertDispatched(
            DispatchAlertNotificationJob::class,
            fn (DispatchAlertNotificationJob $job): bool => $job->channelId === $slack->id
                && $job->event === 'alert.resolved',
        );
    }

    public function test_resolve_dispatches_nothing_when_no_preference_opts_in(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $slack = AlertNotificationChannel::factory()->slack()->for($user)->create();

        AlertNotificationPreference::factory()
            ->for($user)
            ->for($slack, 'channel')
            ->create([
                'min_severity' => AlertSeverity::Warning->value,
                'notify_on_resolve' => false,
            ]);

        Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Docker->value,
            'source_id' => 9,
            'type' => 'host.offline',
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);

        app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Docker,
            'source_id' => 9,
            'type' => 'host.offline',
        ]);

        Bus::assertNotDispatched(DispatchAlertNotificationJob::class);
    }
}
